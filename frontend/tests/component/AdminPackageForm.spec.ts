// @vitest-environment nuxt
import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import SpAdminPackageForm from '~/components/SpAdminPackageForm.vue'
import type { AdminModelAlias, AdminPackage, AdminPackageInput } from '~/types/admin'
import { emptyPackageForm, packageFormFrom } from '~/utils/packageAdmin'

/**
 * The package write form, tested through the wiring rather than the pure functions.
 *
 * `packageAdmin.spec.ts` already proves `packageFormFrom` and `buildPackageInput` are
 * inverses. What that cannot prove is that this component actually carries the seed
 * into the request: a prop bound to the wrong field, a seed captured once and never
 * refreshed, or a `v-model` dropped from an input all leave every unit test green while
 * the operator silently erases a package by opening it and pressing save. Since
 * `PUT /admin/packages/{id}` is a full replacement, that is the failure mode worth a
 * mounted test.
 *
 * The other three cases are refusals — the places this form must *not* submit.
 */

enableAutoUnmount(afterEach)

const alias = (overrides: Partial<AdminModelAlias> & { id: string }): AdminModelAlias => ({
  public_alias: `alias-${overrides.id}`,
  display_name: `Alias ${overrides.id}`,
  status: 'available',
  enabled: true,
  customer_visible: true,
  currency: 'USD',
  exponent: 2,
  sell: {
    input_per_million_minor: '300',
    output_per_million_minor: '1500',
    cache_read_per_million_minor: null,
    cache_write_per_million_minor: null,
    reasoning_per_million_minor: null
  },
  upstream_cost: {
    input_per_million_minor: '100',
    output_per_million_minor: '500',
    cache_read_per_million_minor: null,
    cache_write_per_million_minor: null,
    reasoning_per_million_minor: null,
    verified_at: '2026-08-01T00:00:00Z'
  },
  ...overrides
})

const VERIFIED = alias({ id: '7', public_alias: 'claude-coding' })

const UNVERIFIED = alias({
  id: '9',
  public_alias: 'claude-thinking',
  upstream_cost: {
    input_per_million_minor: '100',
    output_per_million_minor: '500',
    cache_read_per_million_minor: null,
    cache_write_per_million_minor: null,
    reasoning_per_million_minor: null,
    verified_at: null
  }
})

/**
 * A package with every optional field populated, so a dropped field shows up as a
 * difference rather than as a null that happened to match.
 */
const PACKAGE: AdminPackage = {
  id: '3',
  slug: 'developer-20m',
  name: 'Developer 20M',
  subtitle: 'For a single developer',
  badge: 'Most popular',
  billing_mode: 'TOKEN_QUOTA',
  family: 'developer',
  family_label: 'Developer',
  advertised_units: '20000000',
  unit_label: 'tokens',
  price_minor: '49000',
  compare_at_price_minor: '69000',
  currency: 'USD',
  currency_exponent: 2,
  price: { minor: '49000', currency: 'USD', exponent: 2 },
  compare_at_price: { minor: '69000', currency: 'USD', exponent: 2 },
  duration_seconds: 2_592_000,
  stock_quantity: '25',
  stock_status: 'IN_STOCK',
  limits: {
    requests_per_minute: 60,
    tokens_per_minute: 120_000,
    concurrency: 4,
    max_request_bytes: 262_144,
    max_output_tokens: 8192
  },
  billing_rules: {
    input_weight_microunits: 1_000_000,
    output_weight_microunits: 4_000_000,
    cache_read_weight_microunits: 100_000,
    cache_write_weight_microunits: 1_250_000,
    reasoning_weight_microunits: 4_000_000
  },
  auto_creates_api_key: true,
  featured: true,
  sort_order: 20,
  starts_at: '2026-08-01T00:00:00Z',
  ends_at: '2026-12-31T23:59:59Z',
  allowed_model_alias_ids: [7],
  allowed_model_aliases: ['claude-coding'],
  enabled: true,
  customer_visible: true,
  minimum_margin_bps: 2500,
  profitability_override_reason: null,
  profitability: {
    reviewable: true,
    profitable: true,
    price_minor: '49000',
    worst_case_cost_minor: '10000',
    margin_minor: '39000',
    margin_bps: 7959,
    minimum_margin_bps: 2500,
    missing_cost_aliases: [],
    override_required: false
  }
}

/** What an untouched save of `PACKAGE` must send, field for field. */
const ROUND_TRIP: AdminPackageInput = {
  slug: 'developer-20m',
  name: 'Developer 20M',
  subtitle: 'For a single developer',
  badge: 'Most popular',
  billing_mode: 'TOKEN_QUOTA',
  family: 'developer',
  family_label: 'Developer',
  advertised_units: 20_000_000,
  unit_label: 'tokens',
  price_minor: 49_000,
  compare_at_price_minor: 69_000,
  currency: 'USD',
  currency_exponent: 2,
  duration_seconds: 2_592_000,
  stock_quantity: 25,
  limits: {
    requests_per_minute: 60,
    tokens_per_minute: 120_000,
    concurrency: 4,
    max_request_bytes: 262_144,
    max_output_tokens: 8192
  },
  billing_rules: {
    input_weight_microunits: 1_000_000,
    output_weight_microunits: 4_000_000,
    cache_read_weight_microunits: 100_000,
    cache_write_weight_microunits: 1_250_000,
    reasoning_weight_microunits: 4_000_000
  },
  auto_creates_api_key: true,
  featured: true,
  sort_order: 20,
  starts_at: '2026-08-01T00:00:00Z',
  ends_at: '2026-12-31T23:59:59Z',
  enabled: true,
  customer_visible: true,
  minimum_margin_bps: 2500,
  profitability_override_reason: null,
  allowed_model_alias_ids: [7]
}

type Props = InstanceType<typeof SpAdminPackageForm>['$props']

const mount = (props: Partial<Props> = {}) =>
  mountSuspended(SpAdminPackageForm, {
    props: {
      open: true,
      initial: packageFormFrom(PACKAGE),
      heading: 'Edit Developer 20M',
      description: 'The whole package is replaced on save.',
      submitLabel: 'Save package',
      aliases: [VERIFIED, UNVERIFIED],
      aliasesUnavailable: false,
      existingSlugs: [],
      saving: false,
      errorMessage: null,
      ...props
    }
  })

/** The dialog teleports to the body, so the whole document is the surface. */
const bodyText = () => document.body.textContent ?? ''

/**
 * Submits through the real form element.
 *
 * Deliberately not by calling the component's own handler: a submit path that is only
 * reachable from a test proves nothing about the button the operator presses.
 */
const submitForm = async () => {
  const form = document.body.querySelector('form')

  expect(form, 'the form was not rendered').not.toBeNull()

  form!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))

  await nextTick()
  await nextTick()
  await nextTick()
}

const submitButton = () =>
  [...document.body.querySelectorAll('button')]
    .find(button => (button.textContent ?? '').trim() === 'Save package')

describe('package form round trip', () => {
  it('sends back exactly what it was seeded with when nothing is edited', async () => {
    const wrapper = await mount()

    await submitForm()

    const emitted = wrapper.emitted('submit')

    expect(emitted, 'an untouched, valid package did not submit').toBeTruthy()
    expect(emitted![0]![0]).toEqual(ROUND_TRIP)
  })

  it('carries an edited field through without disturbing the rest', async () => {
    const seed = packageFormFrom(PACKAGE)

    seed.price_minor = '59000'

    const wrapper = await mount({ initial: seed })

    await submitForm()

    expect(wrapper.emitted('submit')![0]![0]).toEqual({ ...ROUND_TRIP, price_minor: 59_000 })
  })

  /**
   * The seed is re-read on open, not captured once at mount. A form that kept its
   * first seed would let an operator open package A, close it, open package B and
   * overwrite B with A's values — and every field would look plausible.
   */
  it('re-seeds when reopened, so a previous package leaves nothing behind', async () => {
    const wrapper = await mount()

    await submitForm()

    expect(wrapper.emitted('submit')![0]![0]).toEqual(ROUND_TRIP)

    await wrapper.setProps({ open: false })
    await wrapper.setProps({
      initial: packageFormFrom({ ...PACKAGE, slug: 'team-100m', name: 'Team 100M' }),
      open: true
    })
    await nextTick()

    await submitForm()

    const emitted = wrapper.emitted('submit')!

    expect(emitted, 'the reopened form did not submit').toHaveLength(2)
    expect(emitted[1]![0]).toEqual({ ...ROUND_TRIP, slug: 'team-100m', name: 'Team 100M' })
  })
})

describe('package form refusals', () => {
  /**
   * The 409 gate, stated before the write rather than after it. The control plane
   * refuses to publish a package whose margin is not established without a written
   * reason and rolls the whole write back, so submitting would cost the operator
   * every edit in the form.
   */
  it('will not publish without an established margin until a reason is written', async () => {
    const seed = packageFormFrom(PACKAGE)

    // Unverified upstream cost: no margin can be calculated for this package at all.
    seed.allowed_model_alias_ids = [9]

    const wrapper = await mount({ initial: seed })

    expect(bodyText()).toContain('Publishing this needs a written reason')

    await submitForm()

    expect(wrapper.emitted('submit'), 'published an unreviewable package with no reason').toBeFalsy()

    const textarea = document.body.querySelector('textarea')

    expect(textarea, 'no reason field to fill in').not.toBeNull()

    textarea!.value = 'Launch pricing for the developer tier, approved for this quarter.'
    textarea!.dispatchEvent(new Event('input', { bubbles: true }))
    await nextTick()

    await submitForm()

    expect(wrapper.emitted('submit')![0]![0]).toMatchObject({
      allowed_model_alias_ids: [9],
      profitability_override_reason: 'Launch pricing for the developer tier, approved for this quarter.'
    })
  })

  /**
   * The same package not going on sale is never blocked: `assertPublishable` only
   * fires when a package is both enabled and customer-visible, and mirroring that
   * matters as much as mirroring the refusal.
   */
  it('does not demand a reason for a package that is not going on sale', async () => {
    const seed = packageFormFrom(PACKAGE)

    seed.allowed_model_alias_ids = [9]
    seed.customer_visible = false

    const wrapper = await mount({ initial: seed })

    expect(bodyText()).not.toContain('Publishing this needs a written reason')

    await submitForm()

    expect(wrapper.emitted('submit')![0]![0]).toMatchObject({
      customer_visible: false,
      profitability_override_reason: null
    })
  })

  /**
   * `allowed_model_alias_ids` decides which models a package grants. A non-integer id
   * cannot be sent without guessing, and a guess here would change what the customer
   * is allowed to call, so the save is blocked instead.
   */
  it('blocks the save when a model id is not an integer', async () => {
    const wrapper = await mount({
      aliases: [VERIFIED, alias({ id: 'not-an-integer', public_alias: 'claude-broken' })]
    })

    expect(bodyText()).toContain('Model selection cannot be saved')
    expect(submitButton()?.hasAttribute('disabled')).toBe(true)

    await submitForm()

    expect(wrapper.emitted('submit'), 'sent a model selection it could not resolve').toBeFalsy()
  })

  it('refuses a package that allows no model at all', async () => {
    const seed = emptyPackageForm()

    seed.slug = 'empty'
    seed.name = 'Empty'
    seed.family = 'developer'
    seed.family_label = 'Developer'
    seed.unit_label = 'tokens'
    seed.advertised_units = '1000'
    seed.price_minor = '100'
    seed.duration_amount = '30'
    seed.minimum_margin_bps = '2500'

    const wrapper = await mount({ initial: seed })

    await submitForm()

    expect(wrapper.emitted('submit'), 'submitted a package granting no model').toBeFalsy()
    expect(bodyText()).toContain('A package that allows no model cannot serve a request')
  })

  it('shows the model list as unreadable rather than as empty when it failed to load', async () => {
    await mount({ aliases: [], aliasesUnavailable: true })

    expect(bodyText()).toContain('The model list could not be loaded')
  })

  it('saves the API access activation flag using the current fulfilment flow', async () => {
    const seed = packageFormFrom(PACKAGE)
    seed.auto_creates_api_key = true

    const wrapper = await mount({ initial: seed })

    expect(bodyText()).toContain('Prepare API access automatically after payment')
    expect(bodyText()).not.toContain('Fulfilment does not issue keys yet')

    await submitForm()

    expect(wrapper.emitted('submit')![0]![0]).toMatchObject({ auto_creates_api_key: true })
  })
})

describe('package form server rejections', () => {
  /**
   * A 422 key that no field is called leaves the operator with a rejected save and
   * nothing marked, so the mapping from contract name to form field is part of the
   * component's contract with the page.
   */
  it('lands a rejected metering weight on the field that produced it', async () => {
    const wrapper = await mount()
    const vm = wrapper.vm as unknown as {
      setServerErrors: (errors: Record<string, string[]>) => void
    }

    vm.setServerErrors({
      'billing_rules.output_weight_microunits': ['The output weight must be at least 1.'],
      'duration_seconds': ['The duration is too short.']
    })
    await nextTick()

    expect(bodyText()).toContain('The output weight must be at least 1.')
    expect(bodyText()).toContain('The duration is too short.')
  })

  it('shows a conflict the control plane reported as a banner, not against a field', async () => {
    await mount({ errorMessage: 'This needs a profitability review before it can be published.' })

    expect(bodyText()).toContain('This needs a profitability review before it can be published.')
  })
})
