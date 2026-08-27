// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { Order, PaymentAttempt, PaymentAttemptStatus } from '~/types/commerce'
import { SpApiError } from '~/utils/spApiError'
import CheckoutPage from '~/pages/dashboard/checkout/[order].vue'

/**
 * The payment screen, mounted for real.
 *
 * `tests/unit/paymentState.spec.ts` already proves the arithmetic. What is only
 * observable by mounting the page is whether the arithmetic is *wired up*: that
 * the countdown is corrected by `server_time` rather than trusting the device
 * clock, that the browser never declares an order paid on its own, and that a
 * second payable code is never issued behind the customer's back.
 *
 * Each of those is a money-handling guarantee, which is why they are asserted
 * against rendered output rather than against internal state.
 */

const ORDER_ID = 'ord_test'

/** Server clock. Every fixture below is expressed relative to this instant. */
const SERVER_EPOCH = Date.parse('2026-08-21T10:00:00.000Z')
const WINDOW_MS = 5 * 60_000

/**
 * How far this device's clock runs ahead of the control plane's, in ms.
 *
 * Set per test. The server keeps reporting honest times; only the browser is
 * wrong, which is exactly the real-world failure being guarded against.
 */
let deviceAheadMs = 0

const iso = (ms: number) => new Date(ms).toISOString()

/** Server time now, derived from the (possibly wrong) device clock. */
const serverNow = () => Date.now() - deviceAheadMs

const attempt = (overrides: Partial<PaymentAttempt> = {}): PaymentAttempt => ({
  id: 'pay_test',
  order_id: ORDER_ID,
  status: 'PENDING',
  qr_payload: '00020101021229180014test-khqr-payload',
  qr_image_url: null,
  amount: { minor: '1500', currency: 'USD', exponent: 2 },
  merchant_display_name: 'SP Cambo Test Merchant',
  expires_at: iso(SERVER_EPOCH + WINDOW_MS),
  // Advances with real time, as a live control plane would.
  server_time: iso(serverNow()),
  last_checked_at: null,
  ...overrides
})

const order = (overrides: Partial<Order> = {}): Order => ({
  id: ORDER_ID,
  reference: 'SPC-TEST-0001',
  status: 'PENDING_PAYMENT',
  created_at: iso(SERVER_EPOCH - 30_000),
  items: [{
    package_slug: 'test-package',
    package_name: 'Test Package',
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

/** What the mocked control plane will answer with, mutable per test. */
const plane = {
  order: order(),
  attemptStatus: 'PENDING' as PaymentAttemptStatus,
  /** Thrown by `paymentStatus` instead of returning an attempt. */
  statusError: null as SpApiError | null
}

const { getOrder, paymentStatus, autoCheckPayment, createPayment, requestVerification, toastAdd } = vi.hoisted(() => ({
  getOrder: vi.fn(),
  paymentStatus: vi.fn(),
  autoCheckPayment: vi.fn(),
  createPayment: vi.fn(),
  requestVerification: vi.fn(),
  toastAdd: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  orders: {
    get: getOrder,
    paymentStatus,
    autoCheckPayment,
    createPayment,
    requestVerification
  }
}))

mockNuxtImport('useRoute', () => () => ({ params: { order: ORDER_ID } }))

mockNuxtImport('useToast', () => () => ({ add: toastAdd }))

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers()
  deviceAheadMs = 0
  vi.setSystemTime(SERVER_EPOCH)

  plane.order = order()
  plane.attemptStatus = 'PENDING'
  plane.statusError = null

  getOrder.mockReset().mockImplementation(async () => plane.order)
  paymentStatus.mockReset().mockImplementation(async () => {
    if (plane.statusError) {
      throw plane.statusError
    }

    return attempt({ status: plane.attemptStatus })
  })
  autoCheckPayment.mockReset().mockImplementation(async () => attempt({ status: plane.attemptStatus }))
  createPayment.mockReset().mockImplementation(async () => attempt({ status: plane.attemptStatus }))
  requestVerification.mockReset().mockImplementation(async () => attempt({ status: plane.attemptStatus }))
  toastAdd.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

/**
 * Mounts and lets `onMounted`'s two awaited loads settle.
 *
 * Timers are faked, so the settling is done by flushing microtasks rather than
 * by waiting on wall-clock time.
 */
const mountCheckout = async () => {
  const page = await mountSuspended(CheckoutPage)

  await vi.advanceTimersByTimeAsync(0)
  await nextTick()

  return page
}

describe('checkout countdown', () => {
  it('shows the code as payable, and counts down from the server-issued expiry', async () => {
    const page = await mountCheckout()

    expect(page.text()).toContain('Scan with any Bakong-enabled app')
    expect(page.text()).toContain('05:00')
    expect(page.text()).toContain('I have paid')
  })

  /**
   * The reason `clockSkewMs` exists. A device whose clock reads eleven minutes
   * ahead would compute a code with six minutes left as five minutes expired,
   * and the customer would be told a perfectly payable QR is dead.
   */
  it('ignores a device clock running fast, and keeps a payable code payable', async () => {
    deviceAheadMs = 11 * 60_000
    vi.setSystemTime(SERVER_EPOCH + deviceAheadMs)

    const page = await mountCheckout()

    expect(page.text()).toContain('05:00')
    expect(page.text()).not.toContain('This payment code expired')
  })

  /**
   * The mirror image, and the more dangerous direction: a slow device clock must
   * not present an expired code as payable, because paying an expired KHQR moves
   * real money against a code the backend will not settle.
   */
  it('ignores a device clock running slow, and still expires the code on time', async () => {
    deviceAheadMs = -20 * 60_000
    vi.setSystemTime(SERVER_EPOCH + deviceAheadMs)

    const page = await mountCheckout()

    expect(page.text()).toContain('05:00')

    await vi.advanceTimersByTimeAsync(WINDOW_MS)
    await nextTick()

    expect(page.text()).toContain('This payment code expired')
  })

  it('flips to a safe expired state and still lets an already-paid customer re-check', async () => {
    const page = await mountCheckout()

    await vi.advanceTimersByTimeAsync(WINDOW_MS)
    await nextTick()

    expect(page.text()).toContain('This payment code expired')
    expect(page.text()).toContain('If you already paid, do not pay again')
    expect(page.text()).toContain('Re-check payment')
    expect(page.text()).not.toContain('I have paid — check now')
    expect(page.text()).not.toContain('Scan with any Bakong-enabled app')
  })

  it('offers a fresh code rather than reusing the expired one', async () => {
    const page = await mountCheckout()

    await vi.advanceTimersByTimeAsync(WINDOW_MS)
    await nextTick()

    const newCode = page.findAll('button').find(button => button.text().includes('Get a new code'))

    expect(newCode).toBeDefined()

    createPayment.mockClear()
    await newCode!.trigger('click')
    await vi.advanceTimersByTimeAsync(0)

    expect(createPayment).toHaveBeenCalledTimes(1)
  })

  it('lets an expired attempt be explicitly re-checked before issuing another QR', async () => {
    const page = await mountCheckout()

    await vi.advanceTimersByTimeAsync(WINDOW_MS)
    await nextTick()

    requestVerification.mockClear()
    const recheck = page.findAll('button').find(button => button.text().includes('Re-check payment'))
    expect(recheck).toBeDefined()

    await recheck!.trigger('click')
    await vi.advanceTimersByTimeAsync(0)

    expect(requestVerification).toHaveBeenCalledWith(ORDER_ID)
  })

  it('auto-checks payment server-side without requiring the manual button', async () => {
    await mountCheckout()

    autoCheckPayment.mockClear()
    requestVerification.mockClear()
    await vi.advanceTimersByTimeAsync(20_000)

    expect(autoCheckPayment).toHaveBeenCalledWith(ORDER_ID)
    expect(requestVerification).not.toHaveBeenCalled()
  })

  /** Polling exists to notice a settled transfer; once expired there is nothing to notice. */
  it('stops polling once the outcome is decided', async () => {
    await mountCheckout()

    await vi.advanceTimersByTimeAsync(WINDOW_MS)
    await nextTick()

    paymentStatus.mockClear()
    await vi.advanceTimersByTimeAsync(60_000)

    expect(paymentStatus).not.toHaveBeenCalled()
  })
})

describe('checkout payment outcome', () => {
  /**
   * The single most important assertion on this page: pressing "I have paid" is
   * a *request for a check*. When the control plane answers that it is still
   * verifying, the customer must not see a confirmation.
   */
  it('never declares an order paid because the customer said so', async () => {
    const page = await mountCheckout()

    plane.attemptStatus = 'VERIFYING'

    const iHavePaid = page.findAll('button').find(button => button.text().includes('I have paid'))
    await iHavePaid!.trigger('click')
    await vi.advanceTimersByTimeAsync(0)
    await nextTick()

    expect(requestVerification).toHaveBeenCalledWith(ORDER_ID)
    expect(page.text()).not.toContain('Payment confirmed')
    expect(page.text()).toContain('Scan with any Bakong-enabled app')
  })

  it('asks the control plane to re-read the order, since fulfilment lands there', async () => {
    const page = await mountCheckout()

    getOrder.mockClear()

    const iHavePaid = page.findAll('button').find(button => button.text().includes('I have paid'))
    await iHavePaid!.trigger('click')
    await vi.advanceTimersByTimeAsync(0)

    expect(getOrder).toHaveBeenCalledWith(ORDER_ID)
  })

  /**
   * Paid and fulfilled are different facts. Between them the money is taken but
   * the entitlement is not live yet, and telling the customer their package is
   * ready at that point would be a false claim about what they can do.
   */
  it('distinguishes payment confirmed from package activated', async () => {
    plane.attemptStatus = 'PAID'
    plane.order = order({ status: 'PAID' })

    const paid = await mountCheckout()

    expect(paid.text()).toContain('Payment confirmed')
    expect(paid.text()).not.toContain('package activated')
    expect(paid.text()).toContain('do not need to pay again')
  })

  it('confirms activation only once the order reports fulfilment', async () => {
    plane.attemptStatus = 'PAID'
    plane.order = order({
      status: 'FULFILLED',
      fulfilled_at: iso(SERVER_EPOCH + 20_000)
    })

    const page = await mountCheckout()

    expect(page.text()).toContain('Payment confirmed and package activated')
    expect(page.text()).not.toContain('I have paid')
  })

  /**
   * A settled transfer beats an elapsed clock. The server took the money; a
   * countdown reaching zero afterwards must not tell the customer to pay again.
   */
  it('keeps a paid order paid after the countdown elapses', async () => {
    plane.attemptStatus = 'PAID'
    plane.order = order({ status: 'PAID' })

    const page = await mountCheckout()

    await vi.advanceTimersByTimeAsync(WINDOW_MS + 60_000)
    await nextTick()

    expect(page.text()).toContain('Payment confirmed')
    expect(page.text()).not.toContain('This payment code expired')
  })

  it('tells a customer with a failed order to ask for a re-check instead of paying again', async () => {
    plane.attemptStatus = 'FAILED'
    plane.order = order({ status: 'FAILED' })

    const page = await mountCheckout()

    expect(page.text()).toContain('This order did not complete')
    expect(page.text()).toContain('do not pay again')
    expect(page.text()).not.toContain('Scan with any Bakong-enabled app')
  })
})

describe('checkout payment attempt creation', () => {
  /**
   * One order, one live payable code. `POST` is reached only when the control
   * plane says there is genuinely no attempt to read.
   */
  it('reads the existing attempt rather than creating a second one', async () => {
    await mountCheckout()

    expect(paymentStatus).toHaveBeenCalledWith(ORDER_ID)
    expect(createPayment).not.toHaveBeenCalled()
  })

  it('creates an attempt exactly once when the order has none', async () => {
    plane.statusError = new SpApiError({
      code: 'not_found',
      status: 404,
      message: 'No payment attempt exists for this order.'
    })

    const page = await mountCheckout()

    expect(createPayment).toHaveBeenCalledTimes(1)
    expect(page.text()).toContain('Scan with any Bakong-enabled app')
  })

  /**
   * An ambiguous failure is not evidence that no attempt exists. Creating one
   * here could put two live codes on the same payable amount, and a customer
   * who scans the stale one pays against a code the backend has moved past.
   */
  it('does not create an attempt when the read failed for any other reason', async () => {
    plane.statusError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountCheckout()

    expect(createPayment).not.toHaveBeenCalled()
    expect(page.text()).toContain('The payment code could not be loaded')
  })

  it('reports a missing endpoint honestly instead of showing an empty payment screen', async () => {
    getOrder.mockRejectedValue(new SpApiError({
      code: 'endpoint_unavailable',
      status: 501,
      message: 'This part of the SP Cambo API is not available yet.'
    }))

    const page = await mountCheckout()

    expect(page.text()).toContain('Checkout is not available yet')
    expect(page.text()).toContain('No money has moved')
    expect(page.text()).not.toContain('I have paid')
  })
})
