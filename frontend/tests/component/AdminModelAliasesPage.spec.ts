// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { AdminModelAlias, ModelAliasPricingInput } from '~/types/admin'
import { SpApiError } from '~/utils/spApiError'
import ModelAliasesPage from '~/pages/admin/model-aliases.vue'

/**
 * The model pricing page, mounted for real.
 *
 * `tests/unit/modelAliasAdmin.spec.ts` already proves the pure functions: the round
 * trip, the validator, the exact-integer margin. What it cannot prove is that this
 * page carries the right alias's values into the right request.
 *
 * Two failure modes here are silent and expensive, which is why they get a mounted
 * test rather than a unit test:
 *
 * - `PUT /admin/model-aliases/{id}/pricing` is `updateOrCreate` with a full attribute
 *   set, so it **replaces** the pricing record. A rate the page fails to carry back is
 *   a rate the operator erases by opening an alias and pressing save, and the response
 *   is a 200.
 * - `upstream_cost_verified_at` is a gate rather than a note. Null means the alias
 *   counts as having *no known cost at all*, so every package allowing it stops being
 *   reviewable and can then only be published with a written override. A save that
 *   drops the instant — or worse, stamps one alias's verification onto another — moves
 *   that gate without anyone asking for it.
 */

const VERIFIED_AT = '2026-08-01T14:30:00Z'

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
    cache_read_per_million_minor: '30',
    cache_write_per_million_minor: '375',
    reasoning_per_million_minor: '1500'
  },
  upstream_cost: {
    input_per_million_minor: '100',
    output_per_million_minor: '500',
    cache_read_per_million_minor: '10',
    cache_write_per_million_minor: '125',
    reasoning_per_million_minor: '500',
    verified_at: VERIFIED_AT
  },
  ...overrides
})

/** Every optional rate populated, so a dropped one shows up as a difference. */
const CODING = alias({ id: '7', public_alias: 'claude-coding', display_name: 'Claude Coding' })

/** Verified on a different day, at a different time, to catch a leaked instant. */
const REVIEW = alias({
  id: '13',
  public_alias: 'claude-review',
  display_name: 'Claude Review',
  upstream_cost: {
    input_per_million_minor: '200',
    output_per_million_minor: '900',
    cache_read_per_million_minor: null,
    cache_write_per_million_minor: null,
    reasoning_per_million_minor: null,
    verified_at: '2026-07-15T09:05:00Z'
  }
})

/** On sale, priced, but no upstream cost has ever been checked. */
const THINKING = alias({
  id: '9',
  public_alias: 'claude-thinking',
  display_name: 'Claude Thinking',
  upstream_cost: {
    input_per_million_minor: '100',
    output_per_million_minor: '500',
    cache_read_per_million_minor: null,
    cache_write_per_million_minor: null,
    reasoning_per_million_minor: null,
    verified_at: null
  }
})

/** No pricing record at all — not the same as being free. Hidden, so not on sale. */
const LEGACY = alias({
  id: '11',
  public_alias: 'claude-legacy',
  display_name: 'Claude Legacy',
  customer_visible: false,
  currency: null,
  exponent: null,
  sell: null,
  upstream_cost: null
})

/** What an untouched save of `CODING` must send, field for field. */
const ROUND_TRIP: ModelAliasPricingInput = {
  currency: 'USD',
  exponent: 2,
  input_per_million_minor: 300,
  output_per_million_minor: 1500,
  cache_read_per_million_minor: 30,
  cache_write_per_million_minor: 375,
  reasoning_per_million_minor: 1500,
  upstream_input_per_million_minor: 100,
  upstream_output_per_million_minor: 500,
  upstream_cache_read_per_million_minor: 10,
  upstream_cache_write_per_million_minor: 125,
  upstream_reasoning_per_million_minor: 500,
  upstream_cost_verified_at: VERIFIED_AT,
  reason: 'Checked against the provider pricing page on 22 August.'
}

const REASON = ROUND_TRIP.reason

/** What the mocked control plane will answer with, set per test. */
const plane = {
  aliases: [CODING] as AdminModelAlias[],
  listError: null as SpApiError | null,
  saveError: null as SpApiError | null,
  /** Overrides the alias the save responds with, when the point is the response. */
  saved: null as AdminModelAlias | null
}

const { listAliases, updatePricing, toastAdd } = vi.hoisted(() => ({
  listAliases: vi.fn(),
  updatePricing: vi.fn(),
  toastAdd: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  admin: {
    modelAliases: listAliases,
    updateModelAliasPricing: updatePricing
  }
}))

mockNuxtImport('useToast', () => () => ({ add: toastAdd }))

enableAutoUnmount(afterEach)

beforeEach(() => {
  plane.aliases = [CODING]
  plane.listError = null
  plane.saveError = null
  plane.saved = null

  toastAdd.mockReset()

  listAliases.mockReset().mockImplementation(async () => {
    if (plane.listError) {
      throw plane.listError
    }

    return plane.aliases
  })

  updatePricing.mockReset().mockImplementation(async (id: string): Promise<AdminModelAlias> => {
    if (plane.saveError) {
      throw plane.saveError
    }

    return plane.saved ?? plane.aliases.find(entry => entry.id === id) ?? CODING
  })

  /*
   * `useSpResource` keys into Nuxt's payload, which is shared for the whole file.
   * Without clearing it, the second test would render the first test's aliases.
   */
  clearNuxtData()
  clearNuxtState()
})

/** The page's own setup state. The editor is a teleported dialog, so this drives it. */
interface AliasesVm {
  openEdit: (alias: AdminModelAlias) => void
  form: {
    currency: string
    exponent: string
    sell: Record<string, string>
    upstream: Record<string, string>
    upstream_verified: boolean
    upstream_verified_date: string
    reason: string
  }
  formOpen: boolean
  formError: string | null
  saving: boolean
}

const mountPage = async () => {
  const page = await mountSuspended(ModelAliasesPage)

  await nextTick()
  await nextTick()

  return page
}

/** The dialog teleports to the body, so the whole document is the surface. */
const bodyText = () => document.body.textContent ?? ''

const settle = async () => {
  for (let tick = 0; tick < 4; tick += 1) {
    await nextTick()
  }

  await new Promise(resolve => setTimeout(resolve, 0))
  await nextTick()
}

/**
 * Submits through the real form element.
 *
 * Not by calling the page's own handler: that skips `UForm`'s validation, which is
 * what enforces the mandatory audit reason, so a test driving the handler directly
 * would report a save the operator could never make.
 */
const submitForm = async () => {
  const form = document.body.querySelector('form')

  expect(form, 'the pricing form was not rendered').not.toBeNull()

  form!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))

  await settle()
}

/** Opens the editor for one alias and types the audit reason, changing nothing else. */
const openWithReason = async (vm: AliasesVm, target: AdminModelAlias, reason = REASON) => {
  vm.openEdit(target)
  await settle()

  vm.form.reason = reason
  await nextTick()
}

describe('model pricing round trip', () => {
  it('sends every rate back unchanged when only the reason is typed', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)
    await submitForm()

    expect(updatePricing, 'an untouched, valid pricing record did not submit').toHaveBeenCalledTimes(1)
    expect(updatePricing).toHaveBeenCalledWith('7', ROUND_TRIP)
  })

  /**
   * The instant, not the date. Re-saving an unrelated rate must not quietly move a
   * verification recorded at 14:30 back to midnight — that is a different claim
   * about when the cost was last checked.
   */
  it('keeps the exact verification instant when the date is not moved', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)

    vm.form.sell.output = '1600'
    await nextTick()
    await submitForm()

    expect(updatePricing).toHaveBeenCalledWith('7', {
      ...ROUND_TRIP,
      output_per_million_minor: 1600,
      upstream_cost_verified_at: VERIFIED_AT
    })
  })

  /**
   * The dangerous carry-over. If the instant captured when the dialog opened
   * survived from one alias to the next, saving the second alias would stamp it with
   * the first alias's verification date — a claim nobody made, about a cost nobody
   * re-checked, on the exact field that decides whether packages are reviewable.
   */
  it('uses the verification instant of the alias actually being edited', async () => {
    plane.aliases = [CODING, REVIEW]

    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)
    await submitForm()

    expect(updatePricing).toHaveBeenLastCalledWith('7', ROUND_TRIP)

    await openWithReason(vm, REVIEW)
    await submitForm()

    expect(updatePricing).toHaveBeenCalledTimes(2)
    expect(updatePricing).toHaveBeenLastCalledWith('13', expect.objectContaining({
      upstream_input_per_million_minor: 200,
      upstream_output_per_million_minor: 900,
      upstream_cache_read_per_million_minor: null,
      upstream_cost_verified_at: '2026-07-15T09:05:00Z'
    }))
  })

  it('records midnight UTC when the operator moves the verification date', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)

    vm.form.upstream_verified_date = '2026-08-22'
    await nextTick()
    await submitForm()

    expect(updatePricing).toHaveBeenCalledWith('7', {
      ...ROUND_TRIP,
      upstream_cost_verified_at: '2026-08-22T00:00:00Z'
    })
  })

  /**
   * An alias with no pricing record opens blank rather than at zero, because zero is
   * a real price — free — and inventing it would put a rate on the storefront that
   * nobody set.
   */
  it('opens an unpriced model with no rates rather than with zeros', async () => {
    plane.aliases = [LEGACY]

    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    vm.openEdit(LEGACY)
    await settle()

    expect(vm.form.sell.input).toBe('')
    expect(vm.form.upstream.input).toBe('')
    expect(vm.form.upstream_verified).toBe(false)
  })
})

describe('model pricing verification gate', () => {
  /**
   * Clearing the verification is a commercial act: it removes every package allowing
   * this model from profitability analysis. It must reach the API as null, and the
   * operator must be told what it costs — before and after the write.
   */
  it('sends null when the verification is cleared, and says what that removes', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING, 'Provider rates are being renegotiated, so the old check no longer holds.')

    vm.form.upstream_verified = false
    await settle()

    expect(bodyText()).toContain('no calculable margin')
    expect(bodyText()).toContain('written override')

    plane.saved = alias({
      id: '7',
      public_alias: 'claude-coding',
      upstream_cost: {
        input_per_million_minor: '100',
        output_per_million_minor: '500',
        cache_read_per_million_minor: '10',
        cache_write_per_million_minor: '125',
        reasoning_per_million_minor: '500',
        verified_at: null
      }
    })

    await submitForm()

    expect(updatePricing).toHaveBeenCalledWith('7', expect.objectContaining({
      upstream_cost_verified_at: null,
      // The rates themselves survive; only the claim about them is withdrawn.
      upstream_input_per_million_minor: 100,
      upstream_output_per_million_minor: 500
    }))

    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({
      color: 'warning',
      description: expect.stringContaining('still have no calculable margin')
    }))
  })

  /**
   * The verdict comes from the response, not from what the form intended. If the
   * control plane stored no verification, reporting a green success would tell the
   * operator the packages are reviewable when they are not.
   */
  it('reports the verification the control plane returned, not the one submitted', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    plane.saved = THINKING

    await openWithReason(vm, CODING)
    await submitForm()

    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({ color: 'warning' }))
    expect(toastAdd).not.toHaveBeenCalledWith(expect.objectContaining({ color: 'success' }))
  })

  it('re-reads the list after a save instead of trusting its own copy', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    listAliases.mockClear()

    await openWithReason(vm, CODING)
    await submitForm()

    expect(listAliases).toHaveBeenCalled()
  })
})

describe('model pricing refusals', () => {
  /** Every pricing write is audited. A save with no reason is not a save. */
  it('will not save without a reason for the audit trail', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    vm.openEdit(CODING)
    await settle()

    await submitForm()

    expect(updatePricing, 'wrote pricing with no audit reason').not.toHaveBeenCalled()
    expect(bodyText()).toContain('at least 10 characters')
  })

  it('will not save a rate that is not a whole number of minor units', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)

    vm.form.sell.input = '3.50'
    await nextTick()
    await submitForm()

    expect(updatePricing, 'sent a rate it had to round to send').not.toHaveBeenCalled()
    expect(bodyText()).toContain('whole number of minor units')
  })

  /**
   * A 422 key no field is called leaves the operator with a rejected save and nothing
   * marked. The write contract is flat; the form is grouped; the mapping between them
   * is part of this page's job.
   */
  it('lands a rejected upstream rate on the field that produced it', async () => {
    plane.saveError = new SpApiError({
      code: 'validation_failed',
      status: 422,
      message: 'Please check the highlighted fields and try again.',
      errors: {
        upstream_input_per_million_minor: ['The upstream input rate must be at least 0.'],
        exponent: ['The exponent may not be greater than 6.']
      }
    })

    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)
    await submitForm()

    expect(bodyText()).toContain('The upstream input rate must be at least 0.')
    expect(bodyText()).toContain('The exponent may not be greater than 6.')
    // A field-level rejection is shown against the fields, not duplicated as a banner.
    expect(vm.formError).toBeNull()
  })

  /**
   * A refused save must keep the dialog and its values. Closing it would cost the
   * operator every rate they typed, and reporting success would be a lie about what
   * customers are charged.
   */
  it('keeps the editor open and reports a refusal rather than reporting success', async () => {
    plane.saveError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not complete that request. Please try again.'
    })

    const page = await mountPage()
    const vm = page.vm as unknown as AliasesVm

    await openWithReason(vm, CODING)

    vm.form.sell.output = '1700'
    await nextTick()
    await submitForm()

    expect(vm.formOpen, 'the editor closed on a failed save').toBe(true)
    expect(vm.form.sell.output, 'the typed rate was discarded').toBe('1700')
    expect(vm.formError).toContain('could not complete')
    expect(vm.saving).toBe(false)
    expect(toastAdd).not.toHaveBeenCalled()
  })
})

describe('model pricing honesty about cost', () => {
  it('names every model on sale whose upstream cost has never been checked', async () => {
    plane.aliases = [CODING, THINKING, LEGACY]

    const page = await mountPage()

    expect(page.text()).toContain('1 model on sale with no verified upstream cost')
    expect(page.text()).toContain('claude-thinking')
    // Hidden from customers, so its unknown cost is not currently costing anything.
    expect(page.text()).not.toContain('claude-legacy,')
  })

  it('treats an unverified cost as unknown rather than as a note on the record', async () => {
    plane.aliases = [THINKING]

    const page = await mountPage()

    expect(page.text()).toContain('counts as no known cost at all')
    expect(page.text()).toContain('Cost unverified')
  })

  it('says an unpriced model has no price rather than showing it as free', async () => {
    plane.aliases = [LEGACY]

    const page = await mountPage()

    expect(page.text()).toContain('Not priced')
    expect(page.text()).toContain('It is not the same as being free')
    expect(page.text()).not.toContain('$0.00')
  })

  it('says the permission is missing rather than showing an empty catalogue', async () => {
    plane.listError = new SpApiError({
      code: 'forbidden',
      status: 403,
      message: 'You do not have permission to perform this action.'
    })

    const page = await mountPage()

    expect(page.text()).toContain('does not hold the permission this area requires')
    expect(page.text()).toContain('catalog.manage')
    expect(page.text()).not.toContain('No models exist yet')
  })
})
