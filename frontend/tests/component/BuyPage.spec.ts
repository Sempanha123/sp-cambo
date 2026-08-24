// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { Order, PromotionPreview, PublicPackage } from '~/types/commerce'
import { SpApiError } from '~/utils/spApiError'
import BuyPage from '~/pages/dashboard/buy.vue'

/**
 * The purchase screen, mounted for real.
 *
 * This is where a customer commits money, so the guarantees are about what the
 * page is allowed to *claim*. Two of them matter more than the rest:
 *
 * 1. No figure on this page may be computed in the browser. The subtotal,
 *    discount and payable total are whatever the control plane returned from
 *    `POST /promotions/preview`. A total the frontend derived would be a price
 *    SP Cambo never quoted, and the customer would be asked to transfer it.
 * 2. One press of "Continue to payment" creates one order. A duplicate order
 *    means a second live KHQR for the same purchase, and a customer who scans
 *    the wrong one has paid real money against an order nobody is watching.
 *
 * `tests/unit/format.spec.ts` covers the money formatting itself; this file
 * covers what the page does with the server's answer.
 */

const pkg = (overrides: Partial<PublicPackage> & { slug: string }): PublicPackage => ({
  id: `pkg_${overrides.slug}`,
  name: `Package ${overrides.slug}`,
  subtitle: null,
  badge: null,
  billing_mode: 'TOKEN_QUOTA',
  family: 'standard',
  family_label: 'Standard',
  advertised_units: '20000000',
  unit_label: 'tokens',
  price: { minor: '1500', currency: 'USD', exponent: 2 },
  compare_at_price: null,
  duration_seconds: 2592000,
  allowed_model_aliases: ['sp-fast'],
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null,
    max_request_bytes: null,
    max_output_tokens: null
  },
  auto_creates_api_key: false,
  featured: false,
  sort_order: 1,
  ...overrides
})

const preview = (overrides: Partial<PromotionPreview> = {}): PromotionPreview => ({
  code: 'LAUNCH25',
  label: 'Launch offer',
  valid: true,
  reason: null,
  subtotal: { minor: '1500', currency: 'USD', exponent: 2 },
  discount_total: { minor: '375', currency: 'USD', exponent: 2 },
  total: { minor: '1125', currency: 'USD', exponent: 2 },
  bonus_units: null,
  ...overrides
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  packages: [] as PublicPackage[],
  packagesError: null as SpApiError | null,
  preview: preview(),
  previewError: null as SpApiError | null,
  orderError: null as SpApiError | null,
  /** Deep-link query, as `/dashboard/buy?package=<slug>` would supply. */
  query: {} as Record<string, string>
}

const {
  listPackages,
  previewPromotion,
  createOrder,
  toastAdd
} = vi.hoisted(() => ({
  listPackages: vi.fn(),
  previewPromotion: vi.fn(),
  createOrder: vi.fn(),
  toastAdd: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  catalog: { packages: listPackages },
  orders: { create: createOrder, previewPromotion }
}))

mockNuxtImport('useRoute', () => () => ({ query: plane.query }))
mockNuxtImport('useToast', () => () => ({ add: toastAdd }))

/**
 * Navigation is observed by spying on the real router, not by replacing it.
 *
 * `mockNuxtImport('useRouter', ...)` cannot be used here: Nuxt's own test
 * harness calls `useRouter().afterEach()` during setup, so a stub router breaks
 * the environment before any test runs.
 */
let routerPush: ReturnType<typeof vi.spyOn>

enableAutoUnmount(afterEach)

beforeEach(() => {
  routerPush = vi.spyOn(useRouter(), 'push').mockResolvedValue(undefined)

  plane.packages = [pkg({ slug: 'starter' })]
  plane.packagesError = null
  plane.preview = preview()
  plane.previewError = null
  plane.orderError = null
  plane.query = {}

  listPackages.mockReset().mockImplementation(async () => {
    if (plane.packagesError) {
      throw plane.packagesError
    }

    return plane.packages
  })

  previewPromotion.mockReset().mockImplementation(async () => {
    if (plane.previewError) {
      throw plane.previewError
    }

    return plane.preview
  })

  createOrder.mockReset().mockImplementation(async (input: { package_slug: string }): Promise<Order> => {
    if (plane.orderError) {
      throw plane.orderError
    }

    return {
      id: `ord_${input.package_slug}`,
      reference: 'SPC-TEST-0001',
      status: 'PENDING_PAYMENT',
      created_at: '2026-08-21T10:00:00.000Z',
      items: [],
      subtotal: { minor: '1500', currency: 'USD', exponent: 2 },
      discount_total: { minor: '0', currency: 'USD', exponent: 2 },
      total: { minor: '1500', currency: 'USD', exponent: 2 },
      applied_promotion: null,
      fulfilled_at: null
    }
  })

  routerPush.mockClear()
  toastAdd.mockReset()

  // `useSpResource` caches into the payload, which is shared across this file.
  clearNuxtData()
  clearNuxtState()
})

const mountBuy = async () => {
  const page = await mountSuspended(BuyPage)

  await nextTick()
  await nextTick()

  return page
}

type Page = Awaited<ReturnType<typeof mountBuy>>

const clickButton = async (page: Page, label: string) => {
  const button = page.findAll('button').find(candidate => candidate.text().includes(label))

  expect(button, `no button labelled "${label}"`).toBeDefined()
  await button!.trigger('click')
  await nextTick()
}

/** Types a code into the promotion field and asks the server to check it. */
const checkPromoCode = async (page: Page, code: string) => {
  await page.find('input').setValue(code)
  await nextTick()
  await clickButton(page, 'Check')
  await nextTick()
}

describe('buy page package selection', () => {
  it('renders only figures the control plane published', async () => {
    plane.packages = [pkg({
      slug: 'starter',
      name: 'Starter',
      price: { minor: '1500', currency: 'USD', exponent: 2 },
      advertised_units: '20000000',
      unit_label: 'tokens',
      duration_seconds: 2592000
    })]

    const page = await mountBuy()

    expect(page.text()).toContain('Starter')
    expect(page.text()).toContain('$15.00')
    expect(page.text()).toContain('20,000,000')
    expect(page.text()).toContain('tokens')
  })

  it('pre-selects the featured package when the customer arrived without a deep link', async () => {
    plane.packages = [
      pkg({ slug: 'starter', name: 'Starter', sort_order: 1 }),
      pkg({ slug: 'pro', name: 'Pro package', featured: true, sort_order: 2 })
    ]

    const page = await mountBuy()

    const checked = page.findAll('[role="radio"]').filter(radio => radio.attributes('aria-checked') === 'true')

    expect(checked).toHaveLength(1)
    expect(checked[0]!.text()).toContain('Pro package')
  })

  /** `/pricing` links here with the package the customer was reading about. */
  it('honours a ?package= deep link over the featured default', async () => {
    plane.query = { package: 'starter' }
    plane.packages = [
      pkg({ slug: 'starter', name: 'Starter', sort_order: 1 }),
      pkg({ slug: 'pro', name: 'Pro package', featured: true, sort_order: 2 })
    ]

    const page = await mountBuy()

    const checked = page.findAll('[role="radio"]').filter(radio => radio.attributes('aria-checked') === 'true')

    expect(checked).toHaveLength(1)
    expect(checked[0]!.text()).toContain('Starter')
  })

  /** A retired slug in a stale link must not leave the page with nothing selected. */
  it('falls back to a real package when the deep link names one that is gone', async () => {
    plane.query = { package: 'retired-package' }
    plane.packages = [pkg({ slug: 'starter', name: 'Starter' })]

    const page = await mountBuy()

    expect(page.findAll('[role="radio"]').filter(r => r.attributes('aria-checked') === 'true')).toHaveLength(1)
    expect(page.text()).toContain('Starter')
  })

  it('lists packages in the order the control plane sorted them', async () => {
    plane.packages = [
      pkg({ slug: 'c', name: 'Third package', sort_order: 30 }),
      pkg({ slug: 'a', name: 'First package', sort_order: 10 }),
      pkg({ slug: 'b', name: 'Second package', sort_order: 20 })
    ]

    const page = await mountBuy()
    const text = page.text()

    expect(text.indexOf('First package')).toBeLessThan(text.indexOf('Second package'))
    expect(text.indexOf('Second package')).toBeLessThan(text.indexOf('Third package'))
  })

  /** Quota is an exact integer all the way to the screen; float64 would round this. */
  it('renders an advertised quantity beyond float precision exactly', async () => {
    plane.packages = [pkg({ slug: 'huge', advertised_units: '9007199254740993' })]

    const page = await mountBuy()

    expect(page.text()).toContain('9,007,199,254,740,993')
  })
})

describe('buy page promotion preview', () => {
  it('asks the control plane to price the code, for the selected package and quantity', async () => {
    plane.packages = [pkg({ slug: 'starter' })]

    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')

    expect(previewPromotion).toHaveBeenCalledWith({
      package_slug: 'starter',
      quantity: 1,
      promotion_code: 'LAUNCH25'
    })
  })

  /**
   * The core anti-invention assertion.
   *
   * The fixture is deliberately not self-consistent: 15.00 − 3.75 is 11.25, but
   * the server says the payable total is 9.00. A page doing its own arithmetic
   * would render 11.25 and quote a price SP Cambo never set. The server's number
   * is the only one allowed on screen.
   */
  it('shows the server\'s total verbatim rather than recomputing it', async () => {
    plane.preview = preview({
      subtotal: { minor: '1500', currency: 'USD', exponent: 2 },
      discount_total: { minor: '375', currency: 'USD', exponent: 2 },
      total: { minor: '900', currency: 'USD', exponent: 2 }
    })

    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')

    expect(page.text()).toContain('$9.00')
    expect(page.text()).not.toContain('$11.25')
  })

  it('names the promotion and the discount the server applied', async () => {
    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')

    expect(page.text()).toContain('Launch offer')
    expect(page.text()).toContain('−$3.75')
    expect(page.text()).toContain('$11.25')
  })

  it('shows bonus quantity granted by a promotion', async () => {
    plane.preview = preview({ bonus_units: '5000000' })

    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')

    expect(page.text()).toContain('+5,000,000')
  })

  /**
   * A refused code is the server's decision, and its reason is the only honest
   * explanation. Showing a discount here would promise a price that will not be
   * charged.
   */
  it('repeats the server\'s reason for refusing a code, and applies no discount', async () => {
    plane.preview = preview({
      valid: false,
      reason: 'This code has already been used on this account.',
      discount_total: { minor: '0', currency: 'USD', exponent: 2 }
    })

    const page = await mountBuy()
    await checkPromoCode(page, 'USED-CODE')

    expect(page.text()).toContain('This code has already been used on this account.')
    expect(page.text()).not.toContain('Discount')
  })

  it('reports honestly when promotion pricing is not published yet', async () => {
    plane.previewError = new SpApiError({
      code: 'endpoint_unavailable',
      status: 501,
      message: 'This part of the SP Cambo API is not available yet.'
    })

    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')

    expect(page.text()).toContain('cannot be checked yet')
  })

  /**
   * A discount priced for one package is not valid for another. Carrying the old
   * figure over would show a total the backend will not honour.
   */
  it('drops a checked discount when the customer selects a different package', async () => {
    plane.packages = [
      pkg({ slug: 'starter', name: 'Starter', sort_order: 1 }),
      pkg({ slug: 'pro', name: 'Pro package', sort_order: 2 })
    ]

    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')

    expect(page.text()).toContain('Launch offer')

    const pro = page.findAll('[role="radio"]').find(radio => radio.text().includes('Pro package'))
    await pro!.trigger('click')
    await nextTick()

    expect(page.text()).not.toContain('Launch offer')
    expect(page.text()).not.toContain('−$3.75')
  })
})

describe('buy page order creation', () => {
  it('creates the order for the selected package and sends the customer to pay', async () => {
    plane.packages = [pkg({ slug: 'starter' })]

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')

    expect(createOrder).toHaveBeenCalledWith({
      package_slug: 'starter',
      quantity: 1,
      promotion_code: undefined,
      idempotency_key: expect.stringMatching(/^order-/)
    })
    expect(routerPush).toHaveBeenCalledWith('/dashboard/checkout/ord_starter')
  })

  it('forwards a code the server accepted', async () => {
    const page = await mountBuy()
    await checkPromoCode(page, 'LAUNCH25')
    await clickButton(page, 'Continue to payment')

    expect(createOrder).toHaveBeenCalledWith(expect.objectContaining({ promotion_code: 'LAUNCH25' }))
  })

  /** A code the server refused must not reach order creation as if it applied. */
  it('does not forward a code the server refused', async () => {
    plane.preview = preview({ valid: false, reason: 'Expired campaign.' })

    const page = await mountBuy()
    await checkPromoCode(page, 'DEAD-CODE')
    await clickButton(page, 'Continue to payment')

    expect(createOrder).toHaveBeenCalledWith(expect.objectContaining({ promotion_code: undefined }))
  })

  /**
   * Two orders for one purchase means two live KHQR codes, and a customer who
   * scans the stale one has moved real money against an order SP Cambo is not
   * waiting on. An impatient double-press must create exactly one.
   *
   * This caught a real gap: the guard used to be released in a `finally`, which
   * re-armed the button in the window between the order being created and the
   * route actually changing. `POST /orders` accepts no idempotency key, so the
   * client-side guard is the only protection there is.
   */
  it('creates one order when the button is pressed twice in quick succession', async () => {
    const page = await mountBuy()

    const button = page.findAll('button').find(candidate => candidate.text().includes('Continue to payment'))

    await button!.trigger('click')
    await button!.trigger('click')
    await nextTick()

    expect(createOrder).toHaveBeenCalledTimes(1)
  })

  /**
   * The mirror of the guard above: a refused order is recoverable, so the button
   * has to come back. Holding it shut would strand a customer who hit a
   * transient failure with no way to retry except reloading the page.
   */
  it('retries an unconfirmed order with the same idempotency key', async () => {
    plane.orderError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')

    expect(createOrder).toHaveBeenCalledTimes(1)
    const firstKey = createOrder.mock.calls[0]![0].idempotency_key

    plane.orderError = null
    await clickButton(page, 'Continue to payment')

    expect(createOrder).toHaveBeenCalledTimes(2)
    expect(createOrder.mock.calls[1]![0].idempotency_key).toBe(firstKey)
    expect(routerPush).toHaveBeenCalledWith('/dashboard/checkout/ord_starter')
  })

  it('mints a new idempotency key when priced inputs change', async () => {
    plane.packages = [
      pkg({ slug: 'starter', sort_order: 1 }),
      pkg({ slug: 'pro', name: 'Pro package', sort_order: 2 })
    ]
    plane.orderError = new SpApiError({
      code: 'validation_failed',
      status: 422,
      message: 'The given data was invalid.'
    })

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')
    const firstKey = createOrder.mock.calls[0]![0].idempotency_key

    const pro = page.findAll('[role="radio"]').find(radio => radio.text().includes('Pro package'))
    await pro!.trigger('click')
    await nextTick()
    await clickButton(page, 'Continue to payment')

    expect(createOrder.mock.calls[1]![0].idempotency_key).not.toBe(firstKey)
  })

  it('mints a new key after an idempotency conflict', async () => {
    plane.orderError = new SpApiError({
      code: 'idempotency_conflict',
      status: 409,
      message: 'This safety key was already used for another purchase.'
    })

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')
    const firstKey = createOrder.mock.calls[0]![0].idempotency_key

    plane.orderError = null
    await clickButton(page, 'Continue to payment')

    expect(createOrder.mock.calls[1]![0].idempotency_key).not.toBe(firstKey)
  })

  it('reports a refused order and keeps the customer on the page', async () => {
    plane.orderError = new SpApiError({
      code: 'insufficient_stock',
      status: 409,
      message: 'This package is no longer available.'
    })

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')

    expect(page.text()).toContain('This package is no longer available.')
    expect(routerPush).not.toHaveBeenCalled()
    expect(toastAdd).toHaveBeenCalled()
  })

  /**
   * A refused order leaves the customer on the page with the button re-armed, so
   * the refusal is the only thing telling them not to press it again. Drawing it
   * in red is not enough — it has to be announced, or a customer who cannot see
   * the alert presses "Continue to payment" again against a package that is gone.
   */
  it('announces a refused order rather than only drawing it', async () => {
    plane.orderError = new SpApiError({
      code: 'insufficient_stock',
      status: 409,
      message: 'This package is no longer available.'
    })

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')

    const alert = page.find('[role="alert"]')

    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain('This package is no longer available.')
  })

  it('says ordering is not published rather than failing silently', async () => {
    plane.orderError = new SpApiError({
      code: 'endpoint_unavailable',
      status: 501,
      message: 'This part of the SP Cambo API is not available yet.'
    })

    const page = await mountBuy()
    await clickButton(page, 'Continue to payment')

    expect(page.text()).toContain('Ordering is not available yet')
    expect(routerPush).not.toHaveBeenCalled()
  })
})

describe('buy page honesty about missing data', () => {
  it('shows no prices at all when the catalogue is not published', async () => {
    plane.packagesError = new SpApiError({
      code: 'endpoint_unavailable',
      status: 501,
      message: 'This part of the SP Cambo API is not available yet.'
    })

    const page = await mountBuy()

    expect(page.text()).toContain('The package catalogue is not published yet')
    expect(page.text()).toContain('we do not display example prices')
    expect(page.text()).not.toContain('Continue to payment')
    expect(page.text()).not.toContain('$')
  })

  it('distinguishes being offline from an unpublished catalogue', async () => {
    plane.packagesError = new SpApiError({
      code: 'network_unreachable',
      status: 0,
      message: 'SP Cambo could not be reached. Check your connection and try again.'
    })

    const page = await mountBuy()

    expect(page.text()).toContain('could not be reached')
  })

  it('says nothing is on sale rather than showing an empty purchase form', async () => {
    plane.packages = []

    const page = await mountBuy()

    expect(page.text()).toContain('No packages on sale')
    expect(page.text()).not.toContain('Continue to payment')
  })
})
