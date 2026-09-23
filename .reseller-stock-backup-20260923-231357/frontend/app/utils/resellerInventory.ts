import type { BillingMode, EntitlementLot } from '~/types/commerce'
import { compareUnits, parseUnits } from './format'
import { lotExpiryMs } from './entitlementState'

/**
 * What a reseller can fund, derived from the reseller's own entitlement lots.
 *
 * `ResellerAllocationService::allocate` draws units out of the reseller's lots
 * with exactly this filter: same `billing_mode`, `status = ACTIVE`, `expires_at`
 * null or in the future, and `allowed_model_aliases` containing the requested
 * alias. It then takes `remaining_units - reserved_units` from each, soonest
 * expiry first, and refuses the whole transfer with a 402 if the total is short.
 *
 * Mirroring that filter here lets the allocation form tell a reseller what they
 * hold before they submit, rather than letting the control plane reject it. This
 * is a projection of server data and never a substitute for the server's
 * decision: the allocation remains authoritative and can still be refused, not
 * least because the reseller's own inference traffic may spend the same lots
 * between the page loading and the form being submitted.
 */
export interface ResellerFundableAlias {
  billing_mode: BillingMode
  alias: string
  /** Exact integer string: total of `remaining - reserved` across matching lots. */
  available_units: string
  /** Admin-defined unit name from the lots themselves, e.g. "tokens". */
  unit_label: string
  /** How many lots can actually contribute — lots with nothing left are excluded. */
  lot_count: number
  /** Earliest expiry among the contributing lots, or null if none of them expire. */
  next_expires_at: string | null
}

/**
 * Units of a lot that are free to move: what remains, less what is already held
 * back for in-flight requests. Never negative — a lot cannot owe units.
 */
export function lotAvailableUnits(lot: EntitlementLot): string {
  const remaining = parseUnits(lot.remaining_units) ?? 0n
  const reserved = parseUnits(lot.reserved_units) ?? 0n
  const available = remaining - reserved

  return (available > 0n ? available : 0n).toString()
}

/**
 * True when the control plane's allocation query would select this lot.
 *
 * The expiry comparison uses the caller's clock while the server uses its own, so
 * a lot within seconds of lapsing may be classified differently by each. That
 * only ever affects what the form previews, never what is transferred.
 */
export function isLotAllocatable(lot: EntitlementLot, nowMs: number): boolean {
  if (lot.status !== 'ACTIVE') {
    return false
  }

  const expiry = lotExpiryMs(lot)

  return expiry === null || expiry > nowMs
}

/** The reseller's lots that could fund an allocation of `alias` in `billingMode`. */
export function allocatableLots(
  lots: EntitlementLot[],
  billingMode: BillingMode,
  alias: string,
  nowMs: number
): EntitlementLot[] {
  return lots.filter(lot =>
    lot.billing_mode === billingMode
    && lot.allowed_model_aliases.includes(alias)
    && isLotAllocatable(lot, nowMs)
    && lotAvailableUnits(lot) !== '0'
  )
}

/** Exact integer total the reseller could allocate for one mode and alias. */
export function availableToAllocate(
  lots: EntitlementLot[],
  billingMode: BillingMode,
  alias: string,
  nowMs: number
): string {
  let total = 0n

  for (const lot of allocatableLots(lots, billingMode, alias, nowMs)) {
    total += parseUnits(lotAvailableUnits(lot)) ?? 0n
  }

  return total.toString()
}

/**
 * Every mode/alias pair the reseller can currently fund, largest holding first.
 *
 * A lot that permits several aliases appears once per alias. That is not double
 * counting — the same units really are spendable under any of those aliases — but
 * it does mean the rows must not be added together, and any surface showing them
 * has to say so. `hasSharedAliasLots` reports whether that caveat applies.
 */
export function fundableAliases(lots: EntitlementLot[], nowMs: number): ResellerFundableAlias[] {
  interface Accumulator extends ResellerFundableAlias {
    /** Parsed form of `next_expires_at`, so expiries are compared as instants. */
    soonestMs: number | null
  }

  const grouped = new Map<string, Accumulator>()

  for (const lot of lots) {
    if (!isLotAllocatable(lot, nowMs) || lotAvailableUnits(lot) === '0') {
      continue
    }

    const available = parseUnits(lotAvailableUnits(lot)) ?? 0n
    const expiryMs = lotExpiryMs(lot)

    for (const alias of lot.allowed_model_aliases) {
      const key = `${lot.billing_mode}::${alias}`
      const existing = grouped.get(key)

      if (!existing) {
        grouped.set(key, {
          billing_mode: lot.billing_mode,
          alias,
          available_units: available.toString(),
          unit_label: lot.unit_label,
          lot_count: 1,
          next_expires_at: lot.expires_at,
          soonestMs: expiryMs
        })

        continue
      }

      existing.available_units = ((parseUnits(existing.available_units) ?? 0n) + available).toString()
      existing.lot_count += 1

      if (expiryMs !== null && (existing.soonestMs === null || expiryMs < existing.soonestMs)) {
        existing.next_expires_at = lot.expires_at
        existing.soonestMs = expiryMs
      }
    }
  }

  return [...grouped.values()]
    .map(({ soonestMs: _soonestMs, ...entry }) => entry)
    .sort((a, b) => compareUnits(b.available_units, a.available_units) || a.alias.localeCompare(b.alias))
}

/**
 * True when at least one fundable lot permits more than one alias, so the totals
 * per alias overlap and cannot be summed.
 */
export function hasSharedAliasLots(lots: EntitlementLot[], nowMs: number): boolean {
  return lots.some(lot =>
    lot.allowed_model_aliases.length > 1
    && isLotAllocatable(lot, nowMs)
    && lotAvailableUnits(lot) !== '0'
  )
}
