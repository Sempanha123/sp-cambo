// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { Order, OrderStatus } from '~/types/commerce'
import { SpApiError } from '~/utils/spApiError'
import OrdersPage from '~/pages/dashboard/orders.vue'

/**
 * The order list, mounted for real.
 *
 * The rule this page exists to protect is that an unpaid order leads *back to its
 * own payment screen*, never to a fresh order. A customer who is offered "buy
 * again" on an order that already has a live KHQR can end up paying twice for the
 * same package, and the second transfer is real money against an order SP Cambo
 * did not ask for.
 *
 * The status classification itself is covered in `tests/unit/orderState.spec.ts`;
 * this file asserts that each backend status reaches the customer as the right
 * row, the right filter and the right destination.
 */

const NOW = Date.parse('2026-08-21T10:00:00.000Z')
const HOUR = 3600_000

const iso = (ms: number) => new Date(ms).toISOString()

const order = (overrides: Partial<Order> & { id: string }): Order => ({
  reference: `SPC-${overrides.id.toUpperCase()}`,
  status: 'PENDING_PAYMENT',
  created_at: iso(NOW - HOUR),
  items: [{
    package_slug: 'starter',
    package_name: 'Starter',
    quantity: 1,
    unit_price: { minor: '1500', currency: 'USD', exponent: 2 },
    line_total: { minor: '1500', currency: 'USD', exponent: 2 }
  }],
  subtotal: { minor: '1500', currency: 'USD', exponent: 2 },
  discount_total: { minor: '0', currency: 'USD', exponent: 2 },
  total: { minor: '1500', currency: 'USD', exponent: 2 },
  applied_promotion: null,
  fulfilled_at: null,
  ...overrides
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  orders: [] as Order[],
  error: null as SpApiError | null
}

const { listOrders } = vi.hoisted(() => ({ listOrders: vi.fn() }))

mockNuxtImport('useSpApi', () => () => ({
  orders: { list: listOrders }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)

  plane.orders = []
  plane.error = null

  listOrders.mockReset().mockImplementation(async () => {
    if (plane.error) {
      throw plane.error
    }

    return plane.orders
  })

  // `useSpResource` caches into the payload, which is shared across this file.
  clearNuxtData()
  clearNuxtState()
})

afterEach(() => {
  vi.useRealTimers()
})

const mountOrders = async () => {
  const page = await mountSuspended(OrdersPage)

  await vi.advanceTimersByTimeAsync(0)
  await nextTick()

  return page
}

/**
 * Destination of the row action for the only order on screen.
 *
 * Scoped to the list row on purpose. The page header also carries a "New order"
 * link to `/dashboard/buy`, so a page-wide anchor query finds that first and
 * every destination assertion below would pass or fail for the wrong reason —
 * including the one case where `/dashboard/buy` is genuinely correct.
 */
const actionHref = (page: Awaited<ReturnType<typeof mountOrders>>) =>
  page.find('ul > li').find('a').attributes('href')

describe('order list actions', () => {
  /** The whole reason `orderPrimaryAction` exists. */
  it('sends an unpaid order back to its own payment screen, not to a new order', async () => {
    plane.orders = [order({ id: 'open1', status: 'PENDING_PAYMENT' })]

    const page = await mountOrders()

    expect(page.text()).toContain('Continue payment')
    expect(actionHref(page)).toBe('/dashboard/checkout/open1')
    expect(page.text()).not.toContain('Order again')
  })

  it('treats an order the backend is still verifying as open, not as finished', async () => {
    plane.orders = [order({ id: 'ver1', status: 'VERIFYING' })]

    const page = await mountOrders()

    expect(page.text()).toContain('Continue payment')
    expect(actionHref(page)).toBe('/dashboard/checkout/ver1')
  })

  /**
   * Paid but not fulfilled is a real state: the money is taken and the
   * entitlement is not live. Sending the customer to entitlements here would
   * show them an empty list right after they paid.
   */
  it('sends a paid-but-unfulfilled order to its status, not to entitlements', async () => {
    plane.orders = [order({ id: 'paid1', status: 'PAID' })]

    const page = await mountOrders()

    expect(page.text()).toContain('View status')
    expect(actionHref(page)).toBe('/dashboard/checkout/paid1')
  })

  it('sends a fulfilled order to the entitlement it created', async () => {
    plane.orders = [order({
      id: 'done1',
      status: 'FULFILLED',
      fulfilled_at: iso(NOW - 30 * 60_000)
    })]

    const page = await mountOrders()

    expect(page.text()).toContain('View entitlement')
    expect(actionHref(page)).toBe('/dashboard/entitlements')
  })

  it('offers the same package again for a closed order, pre-selected', async () => {
    plane.orders = [order({ id: 'dead1', status: 'EXPIRED' })]

    const page = await mountOrders()

    expect(page.text()).toContain('Order again')
    expect(actionHref(page)).toBe('/dashboard/buy?package=starter')
  })

  /** A retired package cannot be re-selected, so the catalogue is the honest fallback. */
  it('falls back to the catalogue when the ordered package is gone', async () => {
    plane.orders = [order({ id: 'dead2', status: 'CANCELLED', items: [] })]

    const page = await mountOrders()

    expect(actionHref(page)).toBe('/dashboard/buy')
  })
})

describe('order list state grouping', () => {
  it('warns about unpaid orders and tells the customer not to duplicate them', async () => {
    plane.orders = [
      order({ id: 'open1', status: 'PENDING_PAYMENT' }),
      order({ id: 'open2', status: 'VERIFYING' })
    ]

    const page = await mountOrders()

    expect(page.text()).toContain('2 orders awaiting payment')
    expect(page.text()).toContain('do not create a duplicate')
  })

  it('does not warn when every order is settled', async () => {
    plane.orders = [order({ id: 'done1', status: 'FULFILLED' })]

    const page = await mountOrders()

    expect(page.text()).not.toContain('awaiting payment')
  })

  it('counts each backend status into exactly one filter', async () => {
    const statuses: OrderStatus[] = [
      'PENDING_PAYMENT',
      'VERIFYING',
      'PAID',
      'FULFILLED',
      'EXPIRED',
      'FAILED',
      'CANCELLED'
    ]

    plane.orders = statuses.map((status, index) => order({ id: `o${index}`, status }))

    const page = await mountOrders()
    const tabs = page.findAll('[role="tab"]').map(tab => tab.text().replace(/\s+/g, ' '))

    // 7 total = 2 open + 2 completed + 3 closed. Nothing uncounted, nothing double-counted.
    expect(tabs).toEqual(['All 7', 'Needs payment 2', 'Completed 2', 'Closed 3'])
  })

  it('narrows the list to the chosen filter', async () => {
    plane.orders = [
      order({ id: 'open1', status: 'PENDING_PAYMENT', reference: 'SPC-OPEN' }),
      order({ id: 'done1', status: 'FULFILLED', reference: 'SPC-DONE' })
    ]

    const page = await mountOrders()

    const completed = page.findAll('[role="tab"]').find(tab => tab.text().includes('Completed'))
    await completed!.trigger('click')
    await nextTick()

    expect(page.text()).toContain('SPC-DONE')
    expect(page.text()).not.toContain('SPC-OPEN')
  })

  it('says the view is empty rather than showing every order when a filter matches nothing', async () => {
    plane.orders = [order({ id: 'open1', status: 'PENDING_PAYMENT', reference: 'SPC-OPEN' })]

    const page = await mountOrders()

    const closed = page.findAll('[role="tab"]').find(tab => tab.text().includes('Closed'))
    await closed!.trigger('click')
    await nextTick()

    expect(page.text()).toContain('No orders in this view')
    expect(page.text()).not.toContain('SPC-OPEN')
  })

  it('lists newest first, whatever order the API returned', async () => {
    plane.orders = [
      order({ id: 'old', reference: 'SPC-OLD', created_at: iso(NOW - 72 * HOUR) }),
      order({ id: 'new', reference: 'SPC-NEW', created_at: iso(NOW - HOUR) }),
      order({ id: 'mid', reference: 'SPC-MID', created_at: iso(NOW - 24 * HOUR) })
    ]

    const page = await mountOrders()
    const text = page.text()

    expect(text.indexOf('SPC-NEW')).toBeLessThan(text.indexOf('SPC-MID'))
    expect(text.indexOf('SPC-MID')).toBeLessThan(text.indexOf('SPC-OLD'))
  })
})

describe('order list figures', () => {
  it('shows the server-applied discount and its promotion code', async () => {
    plane.orders = [order({
      id: 'promo1',
      status: 'FULFILLED',
      discount_total: { minor: '500', currency: 'USD', exponent: 2 },
      total: { minor: '1000', currency: 'USD', exponent: 2 },
      applied_promotion: { code: 'LAUNCH25', label: 'Launch offer' }
    })]

    const page = await mountOrders()

    expect(page.text()).toContain('LAUNCH25')
    expect(page.text()).toContain('−$5.00 discount')
    expect(page.text()).toContain('$10.00')
  })

  /** No discount is not the same as a zero discount worth a row of its own. */
  it('shows no discount row when the server applied none', async () => {
    plane.orders = [order({ id: 'plain1', status: 'FULFILLED' })]

    const page = await mountOrders()

    expect(page.text()).not.toContain('discount')
  })

  it('renders a whole-unit currency without inventing decimals', async () => {
    plane.orders = [order({
      id: 'khr1',
      status: 'FULFILLED',
      total: { minor: '40000', currency: 'KHR', exponent: 0 }
    })]

    const page = await mountOrders()

    expect(page.text()).toContain('៛40,000')
  })

  it('summarises a multi-package order with its quantities', async () => {
    plane.orders = [order({
      id: 'multi1',
      status: 'FULFILLED',
      items: [
        {
          package_slug: 'starter',
          package_name: 'Starter',
          quantity: 2,
          unit_price: { minor: '1500', currency: 'USD', exponent: 2 },
          line_total: { minor: '3000', currency: 'USD', exponent: 2 }
        },
        {
          package_slug: 'pro',
          package_name: 'Pro',
          quantity: 1,
          unit_price: { minor: '5000', currency: 'USD', exponent: 2 },
          line_total: { minor: '5000', currency: 'USD', exponent: 2 }
        }
      ]
    })]

    const page = await mountOrders()

    expect(page.text()).toContain('2 × Starter, Pro')
  })
})

describe('order list honesty about missing data', () => {
  it('shows an empty state for an account that has never ordered', async () => {
    plane.orders = []

    const page = await mountOrders()

    expect(page.text()).toContain('No orders yet')
  })

  it('says the endpoint is not published rather than implying no orders exist', async () => {
    plane.error = new SpApiError({
      code: 'endpoint_unavailable',
      status: 404,
      message: 'This part of the SP Cambo API is not available yet.'
    })

    const page = await mountOrders()

    expect(page.text()).toContain('Order history is not published yet')
    expect(page.text()).toContain('none will be invented here')
    expect(page.text()).not.toContain('No orders yet')
  })

  it('distinguishes being offline from a missing endpoint', async () => {
    plane.error = new SpApiError({
      code: 'network_unreachable',
      status: 0,
      message: 'SP Cambo could not be reached. Check your connection and try again.'
    })

    const page = await mountOrders()

    expect(page.text()).toContain('could not be reached')
  })
})
