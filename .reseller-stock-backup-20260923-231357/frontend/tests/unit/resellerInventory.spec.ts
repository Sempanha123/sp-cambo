import { describe, expect, it } from 'vitest'
import type { EntitlementLot } from '~/types/commerce'
import {
  allocatableLots,
  availableToAllocate,
  fundableAliases,
  hasSharedAliasLots,
  isLotAllocatable,
  lotAvailableUnits
} from '~/utils/resellerInventory'

const NOW = Date.parse('2026-08-21T12:00:00Z')

const lot = (overrides: Partial<EntitlementLot> & { id: string }): EntitlementLot => ({
  billing_mode: 'TOKEN_QUOTA',
  package_name: 'Package',
  family_label: 'Family',
  original_units: '1000000',
  remaining_units: '1000000',
  reserved_units: '0',
  unit_label: 'tokens',
  remaining_amount: null,
  activated_at: '2026-08-20T12:00:00Z',
  expires_at: null,
  allowed_model_aliases: ['sp-fast'],
  status: 'ACTIVE',
  source: 'ORDER',
  access_scope: 'ACCOUNT',
  fulfillment_claim_id: null,
  bound_api_key: null,
  ...overrides
})

describe('lotAvailableUnits', () => {
  it('is what remains, less what is already reserved for in-flight requests', () => {
    expect(lotAvailableUnits(lot({ id: 'a', remaining_units: '1000', reserved_units: '250' }))).toBe('750')
  })

  it('never goes negative — a lot cannot owe units', () => {
    expect(lotAvailableUnits(lot({ id: 'a', remaining_units: '100', reserved_units: '500' }))).toBe('0')
  })

  it('stays exact past float precision', () => {
    expect(lotAvailableUnits(lot({ id: 'a', remaining_units: '9007199254740993', reserved_units: '1' })))
      .toBe('9007199254740992')
  })
})

describe('isLotAllocatable', () => {
  it('accepts an active lot that has not lapsed', () => {
    expect(isLotAllocatable(lot({ id: 'a', expires_at: '2026-08-30T12:00:00Z' }), NOW)).toBe(true)
    expect(isLotAllocatable(lot({ id: 'a' }), NOW)).toBe(true)
  })

  it('rejects a lapsed lot, matching the control plane\'s expires_at filter', () => {
    expect(isLotAllocatable(lot({ id: 'a', expires_at: '2026-08-20T12:00:00Z' }), NOW)).toBe(false)
  })

  it('rejects any lot the server has taken out of service', () => {
    for (const status of ['DEPLETED', 'EXPIRED', 'REVOKED', 'PENDING'] as const) {
      expect(isLotAllocatable(lot({ id: 'a', status }), NOW)).toBe(false)
    }
  })
})

describe('allocatableLots and availableToAllocate', () => {
  /**
   * Mirrors `ResellerAllocationService`: same billing mode, ACTIVE, unexpired, and
   * `allowed_model_aliases` containing the requested alias.
   */
  const lots = [
    lot({ id: 'match-a', remaining_units: '600', reserved_units: '100' }),
    lot({ id: 'match-b', remaining_units: '400', expires_at: '2026-08-25T12:00:00Z' }),
    lot({ id: 'wrong-mode', billing_mode: 'CREDIT_BALANCE' }),
    lot({ id: 'wrong-alias', allowed_model_aliases: ['sp-deep'] }),
    lot({ id: 'lapsed', expires_at: '2026-08-19T12:00:00Z' }),
    lot({ id: 'depleted', status: 'DEPLETED' }),
    lot({ id: 'nothing-left', remaining_units: '0' }),
    lot({ id: 'all-reserved', remaining_units: '900', reserved_units: '900' })
  ]

  it('selects only the lots the control plane would draw from', () => {
    expect(allocatableLots(lots, 'TOKEN_QUOTA', 'sp-fast', NOW).map(entry => entry.id))
      .toEqual(['match-a', 'match-b'])
  })

  it('totals the free units across them exactly', () => {
    expect(availableToAllocate(lots, 'TOKEN_QUOTA', 'sp-fast', NOW)).toBe('900')
  })

  it('is zero — not an error — for a mode and alias the reseller holds nothing for', () => {
    expect(availableToAllocate(lots, 'TOKEN_QUOTA', 'sp-unheld', NOW)).toBe('0')
    expect(availableToAllocate([], 'TOKEN_QUOTA', 'sp-fast', NOW)).toBe('0')
  })

  it('keeps the two billing modes apart, because one cannot fund the other', () => {
    expect(availableToAllocate(lots, 'CREDIT_BALANCE', 'sp-fast', NOW)).toBe('1000000')
  })
})

describe('fundableAliases', () => {
  it('groups by mode and alias, largest holding first', () => {
    const entries = fundableAliases([
      lot({ id: 'small', allowed_model_aliases: ['sp-small'], remaining_units: '100' }),
      lot({ id: 'big-a', allowed_model_aliases: ['sp-big'], remaining_units: '5000' }),
      lot({ id: 'big-b', allowed_model_aliases: ['sp-big'], remaining_units: '3000' })
    ], NOW)

    expect(entries.map(entry => [entry.alias, entry.available_units, entry.lot_count]))
      .toEqual([['sp-big', '8000', 2], ['sp-small', '100', 1]])
  })

  it('separates the same alias sold under two billing modes', () => {
    const entries = fundableAliases([
      lot({ id: 'tokens', allowed_model_aliases: ['sp-fast'], remaining_units: '900' }),
      lot({ id: 'credits', allowed_model_aliases: ['sp-fast'], billing_mode: 'CREDIT_BALANCE', remaining_units: '900', unit_label: 'credits' })
    ], NOW)

    expect(entries).toHaveLength(2)
    expect(entries.map(entry => entry.billing_mode).sort()).toEqual(['CREDIT_BALANCE', 'TOKEN_QUOTA'])
  })

  /**
   * A lot permitting several aliases really is spendable under any of them, so it
   * is reported under each. The figures therefore overlap and must not be summed —
   * which is exactly what `hasSharedAliasLots` exists to warn about.
   */
  it('reports a multi-alias lot under every alias it permits', () => {
    const entries = fundableAliases([
      lot({ id: 'shared', allowed_model_aliases: ['sp-fast', 'sp-deep'], remaining_units: '2000' })
    ], NOW)

    expect(entries.map(entry => entry.alias).sort()).toEqual(['sp-deep', 'sp-fast'])
    expect(entries.every(entry => entry.available_units === '2000')).toBe(true)
  })

  it('reports the soonest expiry as an instant, not by string order', () => {
    /*
     * The control plane serialises with `toAtomString`, which writes `+00:00`.
     * Comparing those against a `Z` timestamp lexicographically puts `+00:00`
     * first regardless of when it actually falls, so the expiries are parsed.
     */
    const entries = fundableAliases([
      lot({ id: 'later', expires_at: '2026-12-01T00:00:00+00:00' }),
      lot({ id: 'sooner', expires_at: '2026-08-25T00:00:00Z' })
    ], NOW)

    expect(entries[0]?.next_expires_at).toBe('2026-08-25T00:00:00Z')
  })

  it('is null for an alias whose lots never expire', () => {
    expect(fundableAliases([lot({ id: 'a' })], NOW)[0]?.next_expires_at).toBeNull()
  })

  it('takes the unit label from the lots rather than inventing one', () => {
    expect(fundableAliases([lot({ id: 'a', unit_label: 'credits' })], NOW)[0]?.unit_label).toBe('credits')
  })

  it('leaves out anything that cannot fund a transfer', () => {
    expect(fundableAliases([
      lot({ id: 'lapsed', expires_at: '2026-08-19T12:00:00Z' }),
      lot({ id: 'revoked', status: 'REVOKED' }),
      lot({ id: 'empty', remaining_units: '0' }),
      lot({ id: 'fully-reserved', remaining_units: '50', reserved_units: '50' })
    ], NOW)).toEqual([])
  })

  it('does not leak the internal expiry cursor into the result', () => {
    const entry = fundableAliases([lot({ id: 'a', expires_at: '2026-08-25T12:00:00Z' })], NOW)[0]

    expect(entry && 'soonestMs' in entry).toBe(false)
  })
})

describe('hasSharedAliasLots', () => {
  it('is true when a fundable lot permits more than one alias', () => {
    expect(hasSharedAliasLots([lot({ id: 'a', allowed_model_aliases: ['sp-fast', 'sp-deep'] })], NOW)).toBe(true)
  })

  it('is false when every fundable lot permits exactly one', () => {
    expect(hasSharedAliasLots([
      lot({ id: 'a', allowed_model_aliases: ['sp-fast'] }),
      lot({ id: 'b', allowed_model_aliases: ['sp-deep'] })
    ], NOW)).toBe(false)
  })

  it('ignores a multi-alias lot that could not fund anything anyway', () => {
    expect(hasSharedAliasLots([
      lot({ id: 'a', allowed_model_aliases: ['sp-fast', 'sp-deep'], remaining_units: '0' })
    ], NOW)).toBe(false)
  })
})
