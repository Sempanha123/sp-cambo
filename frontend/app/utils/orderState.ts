import type { Order, OrderStatus } from '~/types/commerce'

/**
 * Order-list classification and routing.
 *
 * An order's status is the backend's word on it. The frontend only decides how
 * to describe it and where the row's button should lead — never whether it is
 * paid.
 */

/** Still something for the customer to do. */
export const OPEN_ORDER_STATES: OrderStatus[] = ['PENDING_PAYMENT', 'VERIFYING']

/** Finished successfully: paid, and fulfilled once the entitlement lands. */
export const COMPLETED_ORDER_STATES: OrderStatus[] = ['PAID', 'FULFILLED']

/** Finished without a purchase. */
export const CLOSED_ORDER_STATES: OrderStatus[] = ['EXPIRED', 'FAILED', 'CANCELLED']

export function isOrderOpen(order: Order): boolean {
  return OPEN_ORDER_STATES.includes(order.status)
}

export function isOrderCompleted(order: Order): boolean {
  return COMPLETED_ORDER_STATES.includes(order.status)
}

export function isOrderClosed(order: Order): boolean {
  return CLOSED_ORDER_STATES.includes(order.status)
}

/**
 * Newest first. An unparsable `created_at` sorts to the end rather than
 * reordering everything around a NaN comparison.
 */
export function sortOrdersNewestFirst(orders: Order[]): Order[] {
  const createdAt = (order: Order) => {
    const parsed = Date.parse(order.created_at)

    return Number.isNaN(parsed) ? Number.NEGATIVE_INFINITY : parsed
  }

  return [...orders].sort((a, b) => createdAt(b) - createdAt(a))
}

/** One-line summary of what the order contained, e.g. `2 × Starter, Pro`. */
export function orderItemSummary(order: Order): string {
  return order.items
    .map(item => (item.quantity > 1 ? `${item.quantity} × ${item.package_name}` : item.package_name))
    .join(', ')
}

export interface OrderAction {
  to: string
  label: string
  icon: string
  /** Rendered as the prominent button, reserved for an order that needs paying. */
  primary: boolean
}

/**
 * The single most useful thing to offer for an order.
 *
 * An open order leads back to its payment screen — never to a fresh order, which
 * is how a customer ends up paying twice. A closed order offers the same package
 * again, and falls back to the catalogue when the package it referenced is gone.
 */
export function orderPrimaryAction(order: Order): OrderAction {
  if (isOrderOpen(order)) {
    return { to: `/dashboard/checkout/${order.id}`, label: 'Continue payment', icon: 'i-lucide-qr-code', primary: true }
  }

  if (order.status === 'FULFILLED') {
    return { to: '/dashboard/entitlements', label: 'View entitlement', icon: 'i-lucide-hourglass', primary: false }
  }

  if (order.status === 'PAID') {
    return { to: `/dashboard/checkout/${order.id}`, label: 'View status', icon: 'i-lucide-loader-circle', primary: false }
  }

  const slug = order.items[0]?.package_slug

  return {
    to: slug ? `/dashboard/buy?package=${slug}` : '/dashboard/buy',
    label: 'Order again',
    icon: 'i-lucide-rotate-ccw',
    primary: false
  }
}
