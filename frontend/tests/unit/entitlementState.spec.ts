import { describe, expect, it } from 'vitest'
import type { EntitlementLot } from '~/types/commerce'
import {
  closedLots,
  isLotExpiringSoon,
  isLotSpendable,
  lotExpiryMs,
  lotPercentRemaining,
  lotSourceLabel,
  pendingLots,
  sortLotsFefo,
  spendableLots
} from '~/utils/entitlementState'

const NOW = Date.parse('2026-08-21T12:00:00Z')

const lot = (overrides: Partial<EntitlementLot> & { id: string }): EntitlementLot => ({
  billing_mode: 'TOKEN_QUOTA',
  package_name: 'Package',
  family_label: 'Family',
  original_units: '20000000',
  remaining_units: '20000000',
  reserved_units: '0',
  unit_label: 'tokens',
  remaining_amount: null,
  activated_at: '2026-08-20T12:00:00Z',
  expires_at: null,
  allowed_model_aliases: [],
  status: 'ACTIVE',
  source: 'ORDER',
  ...overrides
})

describe('lotExpiryMs', () => {
  it('reads the expiry the server set', () => {
    expect(lotExpiryMs(lot({ id: 'a', expires_at: '2026-08-22T12:00:00Z' })))
      .toBe(Date.parse('2026-08-22T12:00:00Z'))
  })

  it('is null for a lot that does not expire', () => {
    expect(lotExpiryMs(lot({ id: 'a' }))).toBeNull()
  })
})

describe('isLotSpendable', () => {
  it('is true only for an active lot with something left', () => {
    expect(isLotSpendable(lot({ id: 'a' }))).toBe(true)
  })

  it('is false for an active lot that has run out', () => {
    expect(isLotSpendable(lot({ id: 'a', remaining_units: '0' }))).toBe(false)
  })

  it('is false for a lot the server has taken out of service', () => {
    for (const status of ['DEPLETED', 'EXPIRED', 'REVOKED', 'PENDING'] as const) {
      expect(isLotSpendable(lot({ id: 'a', status }))).toBe(false)
    }
  })

  it('does not treat a huge remainder as a float', () => {
    expect(isLotSpendable(lot({ id: 'a', remaining_units: '9007199254740993' }))).toBe(true)
  })
})

describe('sortLotsFefo', () => {
  it('puts the soonest expiry first, matching how the backend spends them', () => {
    const sorted = sortLotsFefo([
      lot({ id: 'later', expires_at: '2026-08-30T12:00:00Z' }),
      lot({ id: 'sooner', expires_at: '2026-08-22T12:00:00Z' }),
      lot({ id: 'middle', expires_at: '2026-08-25T12:00:00Z' })
    ])

    expect(sorted.map(entry => entry.id)).toEqual(['sooner', 'middle', 'later'])
  })

  it('spends the perishable quota before the quota that keeps', () => {
    const sorted = sortLotsFefo([
      lot({ id: 'never-expires' }),
      lot({ id: 'expires', expires_at: '2026-09-30T12:00:00Z' })
    ])

    expect(sorted.map(entry => entry.id)).toEqual(['expires', 'never-expires'])
  })

  it('does not mutate the array it was given', () => {
    const input = [
      lot({ id: 'later', expires_at: '2026-08-30T12:00:00Z' }),
      lot({ id: 'sooner', expires_at: '2026-08-22T12:00:00Z' })
    ]

    sortLotsFefo(input)

    expect(input.map(entry => entry.id)).toEqual(['later', 'sooner'])
  })
})

describe('spendableLots, pendingLots and closedLots', () => {
  const lots = [
    lot({ id: 'active-later', expires_at: '2026-08-30T12:00:00Z' }),
    lot({ id: 'active-sooner', expires_at: '2026-08-22T12:00:00Z' }),
    lot({ id: 'empty', remaining_units: '0' }),
    lot({ id: 'expired', status: 'EXPIRED', expires_at: '2026-08-19T12:00:00Z', remaining_units: '500' }),
    lot({ id: 'revoked', status: 'REVOKED', expires_at: '2026-08-20T12:00:00Z' }),
    lot({ id: 'awaiting', status: 'PENDING' })
  ]

  it('lists only what can be spent, in spend order', () => {
    expect(spendableLots(lots).map(entry => entry.id)).toEqual(['active-sooner', 'active-later'])
  })

  it('separates lots that are paid for but not yet activated', () => {
    expect(pendingLots(lots).map(entry => entry.id)).toEqual(['awaiting'])
  })

  it('collects everything finished, most recently closed first', () => {
    expect(closedLots(lots).map(entry => entry.id)).toEqual(['revoked', 'expired', 'empty'])
  })

  it('accounts for every lot exactly once across the three groups', () => {
    const grouped = [...spendableLots(lots), ...pendingLots(lots), ...closedLots(lots)]

    expect(grouped).toHaveLength(lots.length)
    expect(new Set(grouped.map(entry => entry.id)).size).toBe(lots.length)
  })
})

describe('isLotExpiringSoon', () => {
  it('flags a lot inside the warning window', () => {
    expect(isLotExpiringSoon(lot({ id: 'a', expires_at: '2026-08-22T12:00:00Z' }), NOW)).toBe(true)
  })

  it('does not flag a lot with more time than the window', () => {
    expect(isLotExpiringSoon(lot({ id: 'a', expires_at: '2026-08-25T12:00:00Z' }), NOW)).toBe(false)
  })

  it('does not flag a lot that has already lapsed — that is not "soon"', () => {
    expect(isLotExpiringSoon(lot({ id: 'a', expires_at: '2026-08-20T12:00:00Z' }), NOW)).toBe(false)
  })

  it('never flags a lot without an expiry', () => {
    expect(isLotExpiringSoon(lot({ id: 'a' }), NOW)).toBe(false)
  })

  it('accepts a caller-chosen window', () => {
    const soon = lot({ id: 'a', expires_at: '2026-08-21T13:00:00Z' })

    expect(isLotExpiringSoon(soon, NOW, 30 * 60_000)).toBe(false)
    expect(isLotExpiringSoon(soon, NOW, 2 * 3600_000)).toBe(true)
  })
})

describe('lotPercentRemaining', () => {
  it('is an exact percentage of what the lot started with', () => {
    expect(lotPercentRemaining(lot({ id: 'a', original_units: '200', remaining_units: '50' }))).toBe(25)
  })

  it('has no answer for a lot that started with nothing', () => {
    expect(lotPercentRemaining(lot({ id: 'a', original_units: '0', remaining_units: '0' }))).toBeNull()
  })
})

describe('lotSourceLabel', () => {
  /**
   * Only the two values the control plane actually writes to
   * `entitlement_lots.source_type` are named. `RESELLER_TRANSFER` is the real
   * one for a reseller allocation — an earlier guess of `RESELLER_ALLOCATION`
   * would have silently degraded every reseller-funded lot to "Granted".
   */
  it('names each source the control plane writes, in customer language', () => {
    expect(lotSourceLabel(lot({ id: 'a', source: 'ORDER' }))).toBe('Purchased')
    expect(lotSourceLabel(lot({ id: 'a', source: 'RESELLER_TRANSFER' }))).toBe('Allocated by your reseller')
  })

  it('does not leak a raw enum name for a source added later', () => {
    for (const source of ['PARTNER_BUNDLE', 'PROMOTION', 'ADMIN_GRANT']) {
      expect(lotSourceLabel(lot({ id: 'a', source }))).toBe('Granted')
    }
  })
})
