import type { EntitlementLot } from '~/types/commerce'
import { isUnitsDepleted, percentOfUnits } from './format'
import { parseInstant } from './paymentState'

/**
 * Entitlement-lot classification and ordering.
 *
 * The list a customer reads must behave like the meter that spends it: the
 * backend consumes the soonest-expiring spendable lot first (FEFO), so the page
 * orders lots the same way. None of this invents a quantity — every unit figure
 * comes from the control plane and is compared as an exact integer.
 */

/** Two days: late enough to be worth a warning, early enough to still be usable. */
export const EXPIRING_SOON_MS = 48 * 3600_000

/** Expiry instant of a lot, or null for a lot that does not expire. */
export function lotExpiryMs(lot: EntitlementLot): number | null {
  return parseInstant(lot.expires_at)
}

/** A lot that can still serve a request: active, and with something left in it. */
export function isLotSpendable(lot: EntitlementLot): boolean {
  return lot.status === 'ACTIVE' && !isUnitsDepleted(lot.remaining_units)
}

/**
 * Spendable lots in consumption order: soonest expiry first. A lot with no
 * expiry sorts last, because the backend spends the perishable quota before the
 * quota that keeps.
 */
export function sortLotsFefo(lots: EntitlementLot[]): EntitlementLot[] {
  return [...lots].sort((a, b) =>
    (lotExpiryMs(a) ?? Number.POSITIVE_INFINITY) - (lotExpiryMs(b) ?? Number.POSITIVE_INFINITY)
  )
}

/** The lots that will be spent, in the order they will be spent. */
export function spendableLots(lots: EntitlementLot[]): EntitlementLot[] {
  return sortLotsFefo(lots.filter(isLotSpendable))
}

/** Paid for but not yet activated, usually awaiting fulfilment of an order. */
export function pendingLots(lots: EntitlementLot[]): EntitlementLot[] {
  return lots.filter(lot => lot.status === 'PENDING')
}

/**
 * Lots that are finished — depleted, expired, revoked — most recently closed
 * first, so the newest history is at the top.
 */
export function closedLots(lots: EntitlementLot[]): EntitlementLot[] {
  return lots
    .filter(lot => lot.status !== 'PENDING' && !isLotSpendable(lot))
    .sort((a, b) => (lotExpiryMs(b) ?? 0) - (lotExpiryMs(a) ?? 0))
}

/** True for a live lot that is close enough to expiry to be worth flagging. */
export function isLotExpiringSoon(lot: EntitlementLot, nowMs: number, withinMs = EXPIRING_SOON_MS): boolean {
  const at = lotExpiryMs(lot)

  if (at === null) {
    return false
  }

  const remaining = at - nowMs

  return remaining > 0 && remaining < withinMs
}

/** How much of the lot is left, as an exact percentage of what it started with. */
export function lotPercentRemaining(lot: EntitlementLot): number | null {
  return percentOfUnits(lot.remaining_units, lot.original_units)
}

/**
 * Sources the control plane actually writes to `entitlement_lots.source_type`.
 *
 * `RESELLER_TRANSFER` is the value `ResellerAllocationService` records — not
 * `RESELLER_ALLOCATION`, which does not exist. Getting this wrong is not a
 * cosmetic slip: a customer funded by their reseller would be told only that the
 * quota was "Granted", with no indication of where it came from.
 */
const SOURCE_LABELS: Record<string, string> = {
  ORDER: 'Purchased',
  RESELLER_TRANSFER: 'Allocated by your reseller'
}

/**
 * Where the lot came from, in customer language. An unrecognised source — one
 * the backend adds later — reads as the neutral "Granted" rather than as a raw
 * enum name.
 */
export function lotSourceLabel(lot: EntitlementLot): string {
  return SOURCE_LABELS[lot.source] ?? 'Granted'
}
