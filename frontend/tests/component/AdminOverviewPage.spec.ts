// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { AdminOverview, AdminRevenue, AdminSystemHealth } from '~/types/admin'
import OverviewPage from '~/pages/admin/index.vue'

/**
 * The operator overview, mounted for real.
 *
 * The figure under test is fulfilled revenue, and the rule is that the page states
 * it exactly as precisely as the response allows and no more. SP Cambo settles
 * through Bakong in USD and KHR, so a deployment taking both is the expected case,
 * not an edge one — and two currencies cannot be added, because minor units of
 * unlike currencies are unlike units.
 *
 * Refusing to sum them is correct. Refusing to *show* them is not: the control
 * plane groups the exact total per currency in `by_currency`, so a page that only
 * says "not shown" tells an operator with real revenue that there is none.
 */

const HEALTH: AdminSystemHealth = {
  updated_at: '2026-08-22T09:00:00.000Z',
  overall: 'operational',
  components: [
    { key: 'database', label: 'Database', status: 'operational', detail: null }
  ]
}

const revenue = (overrides: Partial<AdminRevenue> = {}): AdminRevenue => ({
  minor: '125000',
  currency: 'USD',
  exponent: 2,
  mixed_currency: false,
  by_currency: [{ minor: '125000', currency: 'USD', exponent: 2 }],
  ...overrides
})

const overview = (fulfilledRevenue: AdminRevenue): AdminOverview => ({
  updated_at: '2026-08-22T09:00:00.000Z',
  users: { total: 12, active: 11 },
  orders: { total: 8, by_status: { FULFILLED: 7, PENDING_PAYMENT: 1 } },
  payments: { total: 9, by_status: { PAID: 7, PENDING: 2 } },
  fulfilled_revenue: fulfilledRevenue,
  entitlements: { active_lots: 5 },
  reservations: { active: 1 },
  ledger_entries: 42
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  overview: overview(revenue())
}

const { fetchOverview, fetchHealth } = vi.hoisted(() => ({
  fetchOverview: vi.fn(),
  fetchHealth: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  admin: {
    overview: fetchOverview,
    systemHealth: fetchHealth
  }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  plane.overview = overview(revenue())

  fetchOverview.mockReset().mockImplementation(async () => plane.overview)
  fetchHealth.mockReset().mockImplementation(async () => HEALTH)

  /*
   * `useSpResource` keys into Nuxt's payload, which is shared for the whole test
   * file, so without clearing it a later test renders an earlier test's overview.
   */
  clearNuxtData()
  clearNuxtState()
})

const mountOverview = async () => {
  const page = await mountSuspended(OverviewPage)

  await nextTick()
  await nextTick()

  return page
}

describe('fulfilled revenue', () => {
  it('states a single-currency total as money', async () => {
    const page = await mountOverview()
    const text = page.text()

    expect(text).toContain('$1,250.00')
    expect(text).not.toContain('Revenue spans more than one currency')
    expect(text).not.toContain('Fulfilled revenue by currency')
  })

  it('shows each currency exactly instead of one meaningless total', async () => {
    plane.overview = overview(revenue({
      minor: '0',
      currency: null,
      exponent: null,
      mixed_currency: true,
      by_currency: [
        { minor: '125000', currency: 'USD', exponent: 2 },
        { minor: '48000000', currency: 'KHR', exponent: 0 }
      ]
    }))

    const page = await mountOverview()
    const text = page.text()

    expect(text).toContain('Revenue spans more than one currency')
    expect(text).toContain('Fulfilled revenue by currency')
    expect(text).toContain('$1,250.00')
    expect(text).toContain('៛48,000,000')
  })

  it('never adds minor units across currencies into a single figure', async () => {
    plane.overview = overview(revenue({
      minor: '0',
      currency: null,
      exponent: null,
      mixed_currency: true,
      by_currency: [
        { minor: '125000', currency: 'USD', exponent: 2 },
        { minor: '48000000', currency: 'KHR', exponent: 0 }
      ]
    }))

    const page = await mountOverview()
    const text = page.text()

    // 125000 + 48000000 in any scaling, which no currency on this page is worth.
    expect(text).not.toContain('48125000')
    expect(text).not.toContain('481,250')
    expect(text).toContain('Not shown')
  })

  it('explains an unplaceable decimal point instead of guessing the scale', async () => {
    plane.overview = overview(revenue({
      minor: '125000',
      currency: 'USD',
      exponent: null,
      by_currency: [{ minor: '125000', currency: 'USD', exponent: 2 }]
    }))

    const page = await mountOverview()
    const text = page.text()

    expect(text).toContain('Revenue is shown in exact minor units')
    expect(text).toContain('125,000')
    expect(text).not.toContain('$1,250.00')
  })

  it('does not read an empty ledger as an amount of money', async () => {
    plane.overview = overview(revenue({
      minor: '0',
      currency: null,
      exponent: null,
      by_currency: []
    }))

    const page = await mountOverview()
    const text = page.text()

    expect(text).toContain('No fulfilled order has settled yet')
    expect(text).not.toContain('Fulfilled revenue by currency')
  })
})
