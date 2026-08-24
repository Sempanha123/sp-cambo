import { describe, expect, it } from 'vitest'
import type { MoneyAmount, Order, OrderStatus } from '~/types/commerce'
import {
  isOrderClosed,
  isOrderCompleted,
  isOrderOpen,
  orderItemSummary,
  orderPrimaryAction,
  sortOrdersNewestFirst
} from '~/utils/orderState'

const money = (minor: string): MoneyAmount => ({ minor, currency: 'USD', exponent: 2 })

const item = (overrides: Partial<Order['items'][number]> = {}): Order['items'][number] => ({
  package_slug: 'starter',
  package_name: 'Starter',
  quantity: 1,
  unit_price: money('1500'),
  line_total: money('1500'),
  ...overrides
})

const order = (overrides: Partial<Order> & { id: string }): Order => ({
  reference: 'SPC-0001',
  status: 'PENDING_PAYMENT',
  created_at: '2026-08-21T12:00:00Z',
  items: [item()],
  subtotal: money('1500'),
  discount_total: money('0'),
  total: money('1500'),
  applied_promotion: null,
  fulfilled_at: null,
  ...overrides
})

const ALL_STATES: OrderStatus[] = [
  'PENDING_PAYMENT',
  'VERIFYING',
  'PAID',
  'FULFILLED',
  'FAILED',
  'EXPIRED',
  'CANCELLED'
]

describe('order state classification', () => {
  it('treats an unpaid and a verifying order as still open', () => {
    expect(isOrderOpen(order({ id: 'a', status: 'PENDING_PAYMENT' }))).toBe(true)
    expect(isOrderOpen(order({ id: 'a', status: 'VERIFYING' }))).toBe(true)
  })

  it('treats paid and fulfilled as completed', () => {
    expect(isOrderCompleted(order({ id: 'a', status: 'PAID' }))).toBe(true)
    expect(isOrderCompleted(order({ id: 'a', status: 'FULFILLED' }))).toBe(true)
  })

  it('treats expired, failed and cancelled as closed', () => {
    expect(isOrderClosed(order({ id: 'a', status: 'EXPIRED' }))).toBe(true)
    expect(isOrderClosed(order({ id: 'a', status: 'FAILED' }))).toBe(true)
    expect(isOrderClosed(order({ id: 'a', status: 'CANCELLED' }))).toBe(true)
  })

  it('puts every contract status in exactly one group, so no order vanishes from the filters', () => {
    for (const status of ALL_STATES) {
      const subject = order({ id: 'a', status })
      const groups = [isOrderOpen(subject), isOrderCompleted(subject), isOrderClosed(subject)]

      expect(groups.filter(Boolean)).toHaveLength(1)
    }
  })
})

describe('sortOrdersNewestFirst', () => {
  it('lists the most recent order first', () => {
    const sorted = sortOrdersNewestFirst([
      order({ id: 'older', created_at: '2026-08-01T12:00:00Z' }),
      order({ id: 'newest', created_at: '2026-08-21T12:00:00Z' }),
      order({ id: 'middle', created_at: '2026-08-10T12:00:00Z' })
    ])

    expect(sorted.map(entry => entry.id)).toEqual(['newest', 'middle', 'older'])
  })

  it('pushes an unparsable timestamp to the end instead of scrambling the list', () => {
    const sorted = sortOrdersNewestFirst([
      order({ id: 'broken', created_at: 'whenever' }),
      order({ id: 'older', created_at: '2026-08-01T12:00:00Z' }),
      order({ id: 'newest', created_at: '2026-08-21T12:00:00Z' })
    ])

    expect(sorted.map(entry => entry.id)).toEqual(['newest', 'older', 'broken'])
  })

  it('does not mutate the array it was given', () => {
    const input = [
      order({ id: 'older', created_at: '2026-08-01T12:00:00Z' }),
      order({ id: 'newest', created_at: '2026-08-21T12:00:00Z' })
    ]

    sortOrdersNewestFirst(input)

    expect(input.map(entry => entry.id)).toEqual(['older', 'newest'])
  })
})

describe('orderItemSummary', () => {
  it('names a single package plainly', () => {
    expect(orderItemSummary(order({ id: 'a' }))).toBe('Starter')
  })

  it('shows the quantity only when more than one was bought', () => {
    expect(orderItemSummary(order({ id: 'a', items: [item({ quantity: 3 })] }))).toBe('3 × Starter')
  })

  it('joins several lines in the order the server listed them', () => {
    const summary = orderItemSummary(order({
      id: 'a',
      items: [item({ package_name: 'Starter' }), item({ package_name: 'Pro', quantity: 2 })]
    }))

    expect(summary).toBe('Starter, 2 × Pro')
  })

  it('is empty rather than invented for an order with no lines', () => {
    expect(orderItemSummary(order({ id: 'a', items: [] }))).toBe('')
  })
})

describe('orderPrimaryAction', () => {
  it('sends an unpaid order back to its own payment screen, never to a new order', () => {
    const action = orderPrimaryAction(order({ id: 'ord_1', status: 'PENDING_PAYMENT' }))

    expect(action.to).toBe('/dashboard/checkout/ord_1')
    expect(action.label).toBe('Continue payment')
    expect(action.primary).toBe(true)
  })

  it('keeps a verifying order on its payment screen too', () => {
    expect(orderPrimaryAction(order({ id: 'ord_1', status: 'VERIFYING' })).to)
      .toBe('/dashboard/checkout/ord_1')
  })

  it('never offers a second payment for an order that is already open, paid or fulfilled', () => {
    for (const status of ['PENDING_PAYMENT', 'VERIFYING', 'PAID', 'FULFILLED'] as const) {
      expect(orderPrimaryAction(order({ id: 'ord_1', status })).to).not.toContain('/dashboard/buy')
    }
  })

  it('shows a paid order its settlement status until fulfilment lands', () => {
    const action = orderPrimaryAction(order({ id: 'ord_1', status: 'PAID' }))

    expect(action.to).toBe('/dashboard/checkout/ord_1')
    expect(action.label).toBe('View status')
    expect(action.primary).toBe(false)
  })

  it('sends a fulfilled order to the entitlement it created', () => {
    expect(orderPrimaryAction(order({ id: 'ord_1', status: 'FULFILLED' })).to)
      .toBe('/dashboard/entitlements')
  })

  it('offers a closed order the same package again', () => {
    const action = orderPrimaryAction(order({
      id: 'ord_1',
      status: 'EXPIRED',
      items: [item({ package_slug: 'pro-30d' })]
    }))

    expect(action.to).toBe('/dashboard/buy?package=pro-30d')
    expect(action.label).toBe('Order again')
  })

  it('falls back to the catalogue when the package it referenced is gone', () => {
    expect(orderPrimaryAction(order({ id: 'ord_1', status: 'CANCELLED', items: [] })).to)
      .toBe('/dashboard/buy')
  })

  it('marks only an order that needs paying as the prominent action', () => {
    for (const status of ALL_STATES) {
      const action = orderPrimaryAction(order({ id: 'ord_1', status }))

      expect(action.primary).toBe(status === 'PENDING_PAYMENT' || status === 'VERIFYING')
    }
  })

  it('always resolves to a real dashboard route', () => {
    for (const status of ALL_STATES) {
      expect(orderPrimaryAction(order({ id: 'ord_1', status })).to).toMatch(/^\/dashboard\//)
    }
  })
})
