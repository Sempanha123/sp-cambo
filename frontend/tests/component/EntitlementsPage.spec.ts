// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { BalanceSummary, EntitlementLot } from '~/types/commerce'
import { SpApiError } from '~/utils/spApiError'
import EntitlementsPage from '~/pages/dashboard/entitlements.vue'

/**
 * The entitlements page, mounted for real.
 *
 * The guarantee under test is that the list reads the way the meter behaves. The
 * backend spends the soonest-expiring spendable lot first, so the order on screen
 * has to be FEFO and the lot marked "Spent next" has to be the one a request will
 * actually draw from. A customer who reorders their usage around a wrong answer
 * here loses quota to an expiry they were told they had time for.
 *
 * `tests/unit/entitlementState.spec.ts` covers the sorting in isolation; this
 * file covers the wiring, the empty and depleted cases, and the forfeited figure.
 */

const NOW = Date.parse('2026-08-21T10:00:00.000Z')
const HOUR = 3600_000
const DAY = 24 * HOUR

const iso = (ms: number) => new Date(ms).toISOString()

const lot = (overrides: Partial<EntitlementLot> & { id: string }): EntitlementLot => ({
  billing_mode: 'TOKEN_QUOTA',
  package_name: `Package ${overrides.id}`,
  family_label: 'Standard',
  original_units: '20000000',
  remaining_units: '12000000',
  reserved_units: '0',
  unit_label: 'tokens',
  remaining_amount: null,
  activated_at: iso(NOW - DAY),
  expires_at: iso(NOW + 30 * DAY),
  allowed_model_aliases: ['sp-fast'],
  status: 'ACTIVE',
  source: 'ORDER',
  ...overrides
})

const balance = (overrides: Partial<BalanceSummary> = {}): BalanceSummary => ({
  token_quota: {
    remaining_units: '12000000',
    reserved_units: '0',
    original_units: '20000000'
  },
  credit_balance: {
    remaining: { minor: '0', currency: 'USD', exponent: 2 },
    reserved: { minor: '0', currency: 'USD', exponent: 2 }
  },
  next_expires_at: iso(NOW + 30 * DAY),
  active_lot_count: 1,
  version: 1,
  ...overrides
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  lots: [] as EntitlementLot[],
  balance: balance(),
  lotsError: null as SpApiError | null
}

const { fetchBalance, fetchEntitlements } = vi.hoisted(() => ({
  fetchBalance: vi.fn(),
  fetchEntitlements: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  account: {
    balance: fetchBalance,
    entitlements: fetchEntitlements
  }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)

  plane.lots = []
  plane.balance = balance()
  plane.lotsError = null

  fetchBalance.mockReset().mockImplementation(async () => plane.balance)
  fetchEntitlements.mockReset().mockImplementation(async () => {
    if (plane.lotsError) {
      throw plane.lotsError
    }

    return plane.lots
  })

  /*
   * `useSpResource` keys into Nuxt's payload, which is shared for the whole test
   * file. Without clearing it, the second test would render the first test's
   * lots and pass for the wrong reason.
   */
  clearNuxtData()
  clearNuxtState()
})

afterEach(() => {
  vi.useRealTimers()
})

const mountEntitlements = async () => {
  const page = await mountSuspended(EntitlementsPage)

  await vi.advanceTimersByTimeAsync(0)
  await nextTick()

  return page
}

/** Package names in the order they appear on screen. */
const renderedOrder = (text: string, names: string[]) =>
  [...names].sort((a, b) => text.indexOf(a) - text.indexOf(b))

describe('entitlements FEFO ordering', () => {
  it('lists spendable lots soonest-expiry first, however the API ordered them', async () => {
    plane.lots = [
      lot({ id: 'far', package_name: 'Expires in thirty days', expires_at: iso(NOW + 30 * DAY) }),
      lot({ id: 'soon', package_name: 'Expires in six hours', expires_at: iso(NOW + 6 * HOUR) }),
      lot({ id: 'mid', package_name: 'Expires in three days', expires_at: iso(NOW + 3 * DAY) })
    ]

    const page = await mountEntitlements()
    const text = page.text()

    expect(renderedOrder(text, [
      'Expires in thirty days',
      'Expires in six hours',
      'Expires in three days'
    ])).toEqual([
      'Expires in six hours',
      'Expires in three days',
      'Expires in thirty days'
    ])
  })

  /**
   * A lot that never expires cannot be lost to time, so the backend spends the
   * perishable quota first. Showing it at the top would tell the customer to
   * plan around the wrong lot.
   */
  it('places a lot with no expiry last, because perishable quota is spent first', async () => {
    plane.lots = [
      lot({ id: 'never', package_name: 'No expiry lot', expires_at: null }),
      lot({ id: 'week', package_name: 'One week lot', expires_at: iso(NOW + 7 * DAY) })
    ]

    const page = await mountEntitlements()

    expect(renderedOrder(page.text(), ['No expiry lot', 'One week lot']))
      .toEqual(['One week lot', 'No expiry lot'])
  })

  it('marks exactly one lot as the one that will be spent next', async () => {
    plane.lots = [
      lot({ id: 'a', expires_at: iso(NOW + 2 * DAY) }),
      lot({ id: 'b', expires_at: iso(NOW + 9 * DAY) }),
      lot({ id: 'c', expires_at: iso(NOW + 20 * DAY) })
    ]

    const page = await mountEntitlements()

    expect(page.text().match(/Spent next/g)).toHaveLength(1)
  })

  it('warns about a lot that is nearly out of time', async () => {
    plane.lots = [lot({ id: 'soon', expires_at: iso(NOW + 6 * HOUR) })]

    const page = await mountEntitlements()

    expect(page.text()).toContain('Expiring soon')
  })

  it('does not warn about a lot with weeks left', async () => {
    plane.lots = [lot({ id: 'far', expires_at: iso(NOW + 30 * DAY) })]

    const page = await mountEntitlements()

    expect(page.text()).not.toContain('Expiring soon')
  })
})

describe('entitlements spendability', () => {
  /**
   * A depleted or expired lot still exists on the account, and the row for it is
   * worth keeping. What must not happen is it appearing in the spendable list,
   * where the customer would read it as quota they can use.
   */
  it('keeps depleted, expired and revoked lots out of the spendable list', async () => {
    plane.lots = [
      lot({ id: 'spent', package_name: 'Depleted lot', remaining_units: '0', status: 'DEPLETED' }),
      lot({ id: 'lapsed', package_name: 'Expired lot', status: 'EXPIRED', expires_at: iso(NOW - DAY) }),
      lot({ id: 'gone', package_name: 'Revoked lot', status: 'REVOKED' }),
      lot({ id: 'live', package_name: 'Live lot' })
    ]

    const page = await mountEntitlements()

    expect(page.text()).toContain('Live lot')
    expect(page.text()).toContain('Spent next')
    // The closed section is collapsed by default, so none of the three shows yet.
    expect(page.text()).not.toContain('Depleted lot')
    expect(page.text()).not.toContain('Expired lot')
    expect(page.text()).not.toContain('Revoked lot')
    expect(page.text()).toContain('3 lots that can no longer serve a request')
  })

  /**
   * An ACTIVE lot with nothing left in it is the trap this guards: the status
   * says active, so a status-only check would advertise zero quota as spendable
   * and the customer would expect a request to succeed.
   */
  it('treats an active-but-empty lot as unspendable', async () => {
    plane.lots = [lot({ id: 'empty', package_name: 'Empty active lot', remaining_units: '0' })]

    const page = await mountEntitlements()

    expect(page.text()).toContain('Nothing on this account can serve a request right now')
    expect(page.text()).toContain('refused rather than billed as an overage')
    expect(page.text()).not.toContain('Spent next')
  })

  it('separates a paid-but-not-yet-active lot from spendable quota', async () => {
    plane.lots = [
      lot({ id: 'pending', package_name: 'Awaiting activation', status: 'PENDING', activated_at: null }),
      lot({ id: 'live', package_name: 'Live lot' })
    ]

    const page = await mountEntitlements()

    expect(page.text()).toContain('Not active yet')
    expect(page.text()).toContain('Awaiting activation')
    expect(page.text()).toContain('Nothing is consumed from a lot in this state')
  })

  /** Quota left in an expired lot is gone. Saying so is the honest version. */
  it('names the quantity forfeited when a lot expired with quota left', async () => {
    plane.lots = [lot({
      id: 'lapsed',
      package_name: 'Lapsed lot',
      status: 'EXPIRED',
      remaining_units: '4500000',
      expires_at: iso(NOW - DAY)
    })]

    const page = await mountEntitlements()

    const show = page.findAll('button').find(button => button.text().includes('Show'))
    await show!.trigger('click')
    await nextTick()

    expect(page.text()).toContain('4,500,000 forfeited')
  })
})

describe('entitlements honesty about missing data', () => {
  it('shows an empty state rather than a zero balance when nothing has been bought', async () => {
    plane.lots = []

    const page = await mountEntitlements()

    expect(page.text()).toContain('No entitlements yet')
    expect(page.text()).toContain('Buy a package')
  })

  it('says the endpoint is not published rather than showing an invented quantity', async () => {
    plane.lotsError = new SpApiError({
      code: 'endpoint_unavailable',
      status: 501,
      message: 'This part of the SP Cambo API is not available yet.'
    })

    const page = await mountEntitlements()

    expect(page.text()).toContain('Entitlements are not published yet')
    expect(page.text()).toContain('no placeholder quantity will be shown')
  })

  /**
   * Exact integers all the way through: 9,007,199,254,740,993 is the first
   * integer a float64 cannot hold, and a customer's quota must never be rounded
   * on its way to the screen.
   */
  it('renders a quota beyond float precision exactly', async () => {
    plane.lots = [lot({
      id: 'huge',
      original_units: '9007199254740993',
      remaining_units: '9007199254740993'
    })]

    const page = await mountEntitlements()

    expect(page.text()).toContain('9,007,199,254,740,993')
  })
})
