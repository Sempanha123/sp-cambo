import { describe, expect, it } from 'vitest'
import type { AdminModelAlias } from '~/types/admin'
import {
  aliasPricingAdvisories,
  aliasPricingFieldName,
  aliasPricingFormFrom,
  aliasPricingProblems,
  aliasesNeedingCostVerification,
  buildAliasPricingInput,
  emptyAliasPricingForm,
  isAliasUnpriced,
  rateComparisons
} from '~/utils/modelAliasAdmin'

/**
 * The alias pricing editor's dangerous operations.
 *
 * `PUT /admin/model-aliases/{id}/pricing` is `updateOrCreate` with a full attribute
 * set, so the round trip is a correctness property: any rate dropped on the way in or
 * out is a rate the operator erases by opening an alias and pressing save. The
 * verification instant is the second: clearing it removes every package allowing this
 * alias from profitability analysis, so it must never move by accident.
 */

const alias = (overrides: Partial<AdminModelAlias> = {}): AdminModelAlias => ({
  id: '4',
  public_alias: 'claude-coding',
  display_name: 'Claude Coding',
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
    verified_at: '2026-08-01T14:30:00Z'
  },
  ...overrides
})

describe('alias pricing round trip', () => {
  it('sends back exactly what it read when nothing is edited', () => {
    const item = alias()
    const form = aliasPricingFormFrom(item)

    form.reason = 'Re-saved without changing any rate.'

    expect(buildAliasPricingInput(form, { originalVerifiedAt: item.upstream_cost!.verified_at })).toEqual({
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
      // The exact instant, not midnight on the same date.
      upstream_cost_verified_at: '2026-08-01T14:30:00Z',
      reason: 'Re-saved without changing any rate.'
    })
  })

  it('keeps absent optional rates absent instead of storing zero', () => {
    const bare = alias({
      sell: {
        input_per_million_minor: '300',
        output_per_million_minor: '1500',
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: null
      },
      upstream_cost: {
        input_per_million_minor: null,
        output_per_million_minor: null,
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: null,
        verified_at: null
      }
    })

    const input = buildAliasPricingInput(aliasPricingFormFrom(bare))

    expect(input.cache_read_per_million_minor).toBeNull()
    expect(input.cache_write_per_million_minor).toBeNull()
    expect(input.reasoning_per_million_minor).toBeNull()
    expect(input.upstream_input_per_million_minor).toBeNull()
    expect(input.upstream_cost_verified_at).toBeNull()
  })

  it('preserves a zero rate, which means that class is free rather than unpriced', () => {
    const free = alias({
      sell: {
        input_per_million_minor: '0',
        output_per_million_minor: '1500',
        cache_read_per_million_minor: '0',
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: null
      }
    })

    const input = buildAliasPricingInput(aliasPricingFormFrom(free))

    expect(input.input_per_million_minor).toBe(0)
    expect(input.cache_read_per_million_minor).toBe(0)
  })

  it('reads an alias with no pricing record as blank, not as zero', () => {
    const unpriced = alias({ currency: null, exponent: null, sell: null, upstream_cost: null })

    expect(isAliasUnpriced(unpriced)).toBe(true)
    expect(aliasPricingFormFrom(unpriced)).toEqual(emptyAliasPricingForm())
    // A blank mandatory rate is reported rather than defaulted to free.
    expect(aliasPricingProblems(aliasPricingFormFrom(unpriced)).map(problem => problem.name))
      .toContain('sell.input')
  })

  it('records midnight UTC on a date the operator changed', () => {
    const item = alias()
    const form = aliasPricingFormFrom(item)

    form.upstream_verified_date = '2026-08-20'

    expect(buildAliasPricingInput(form, { originalVerifiedAt: item.upstream_cost!.verified_at })
      .upstream_cost_verified_at).toBe('2026-08-20T00:00:00Z')
  })

  it('clears the verification when it is switched off, whatever date is still shown', () => {
    const item = alias()
    const form = aliasPricingFormFrom(item)

    form.upstream_verified = false

    expect(buildAliasPricingInput(form, { originalVerifiedAt: item.upstream_cost!.verified_at })
      .upstream_cost_verified_at).toBeNull()
  })

  it('uppercases a lowercased currency code', () => {
    const form = aliasPricingFormFrom(alias({ currency: 'khr', exponent: 0 }))

    expect(buildAliasPricingInput(form).currency).toBe('KHR')
    expect(buildAliasPricingInput(form).exponent).toBe(0)
  })
})

describe('alias pricing validation', () => {
  const valid = () => {
    const form = aliasPricingFormFrom(alias())

    form.reason = 'Provider published new rates for August.'

    return form
  }

  const namesOf = (state: ReturnType<typeof valid>) => aliasPricingProblems(state).map(problem => problem.name)

  it('accepts a record loaded straight from the control plane plus a reason', () => {
    expect(aliasPricingProblems(valid())).toEqual([])
  })

  it('requires both customer rates, because a request cannot be billed without them', () => {
    const state = valid()

    state.sell.input = ''
    state.sell.output = ''

    const names = namesOf(state)

    expect(names).toContain('sell.input')
    expect(names).toContain('sell.output')
  })

  it('leaves every upstream rate optional', () => {
    const state = valid()

    state.upstream.input = ''
    state.upstream.output = ''
    state.upstream.cache_read = ''
    state.upstream.cache_write = ''
    state.upstream.reasoning = ''

    expect(namesOf(state)).toEqual([])
  })

  it('refuses a fractional rate rather than truncating it into a price', () => {
    const state = valid()

    state.sell.output = '15.5'

    expect(aliasPricingProblems(state)[0]).toEqual({
      name: 'sell.output',
      message: 'Enter a whole number of minor units, with no decimal point or separators.'
    })
  })

  it('refuses a thousands separator and a negative rate', () => {
    const state = valid()

    state.sell.output = '1,500'
    expect(namesOf(state)).toContain('sell.output')

    state.sell.output = '-1500'
    expect(namesOf(state)).toContain('sell.output')
  })

  it('accepts a zero rate, which prices that class as free', () => {
    const state = valid()

    state.sell.input = '0'

    expect(namesOf(state)).not.toContain('sell.input')
  })

  it('mirrors the server bounds on currency and exponent', () => {
    const state = valid()

    state.currency = 'US'
    expect(namesOf(state)).toContain('currency')

    state.currency = 'usd'
    expect(namesOf(state)).not.toContain('currency')

    state.exponent = '7'
    expect(namesOf(state)).toContain('exponent')

    state.exponent = ''
    expect(namesOf(state)).toContain('exponent')
  })

  it('requires a date when the cost is marked verified', () => {
    const state = valid()

    state.upstream_verified_date = ''
    expect(namesOf(state)).toContain('upstream_verified_date')

    state.upstream_verified = false
    expect(namesOf(state)).not.toContain('upstream_verified_date')
  })

  it('requires an audit reason of at least ten characters', () => {
    const state = valid()

    state.reason = 'new rates'
    expect(namesOf(state)).toContain('reason')

    state.reason = 'x'.repeat(2001)
    expect(namesOf(state)).toContain('reason')
  })
})

describe('sell against cost', () => {
  it('subtracts exactly, and reports an unknown side as unknown rather than free', () => {
    const state = aliasPricingFormFrom(alias({
      upstream_cost: {
        input_per_million_minor: '100',
        output_per_million_minor: null,
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: null,
        verified_at: '2026-08-01T00:00:00Z'
      }
    }))

    const byKey = new Map(rateComparisons(state).map(comparison => [comparison.key, comparison]))

    expect(byKey.get('input')!.marginMinor).toBe('200')
    expect(byKey.get('input')!.belowCost).toBe(false)
    expect(byKey.get('output')!.marginMinor).toBeNull()
    expect(byKey.get('output')!.belowCost).toBe(false)
  })

  it('counts selling at exactly cost as below cost, because it earns nothing', () => {
    const state = aliasPricingFormFrom(alias())

    state.sell.output = state.upstream.output

    expect(rateComparisons(state).find(comparison => comparison.key === 'output')!.belowCost).toBe(true)
  })

  it('stays exact on rates far past the float-safe range', () => {
    const state = emptyAliasPricingForm()

    state.sell.input = '90071992547409919999'
    state.upstream.input = '90071992547409910000'

    expect(rateComparisons(state).find(comparison => comparison.key === 'input')!.marginMinor).toBe('9999')
  })
})

describe('alias pricing advisories', () => {
  it('says plainly that an unverified cost blocks every margin calculation', () => {
    const state = aliasPricingFormFrom(alias())

    state.upstream_verified = false

    expect(aliasPricingAdvisories(state).join(' ')).toContain('not verified')
  })

  it('flags a verification recorded with no upstream rate behind it', () => {
    const state = aliasPricingFormFrom(alias({
      upstream_cost: {
        input_per_million_minor: null,
        output_per_million_minor: null,
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        // Reasoning is not part of the backend cost set, so this is still no cost.
        reasoning_per_million_minor: '500',
        verified_at: '2026-08-01T00:00:00Z'
      }
    }))

    expect(aliasPricingAdvisories(state).join(' ')).toContain('no upstream input, output or cache rate')
  })

  it('names the token classes being sold at a loss', () => {
    const state = aliasPricingFormFrom(alias())

    state.sell.output = '400'

    const notes = aliasPricingAdvisories(state).join(' ')

    expect(notes).toContain('output')
    expect(notes).toContain('loses money')
  })

  it('says nothing when the record is verified and every class is above cost', () => {
    expect(aliasPricingAdvisories(aliasPricingFormFrom(alias()))).toEqual([])
  })
})

describe('aliases needing cost verification', () => {
  it('counts only the ones customers can actually reach', () => {
    const unverified = {
      input_per_million_minor: '100',
      output_per_million_minor: '500',
      cache_read_per_million_minor: null,
      cache_write_per_million_minor: null,
      reasoning_per_million_minor: null,
      verified_at: null
    }

    const needing = aliasesNeedingCostVerification([
      alias({ id: '1', public_alias: 'sold', upstream_cost: unverified }),
      alias({ id: '2', public_alias: 'unpriced', sell: null, upstream_cost: null }),
      alias({ id: '3', public_alias: 'hidden', customer_visible: false, upstream_cost: unverified }),
      alias({ id: '4', public_alias: 'disabled', enabled: false, upstream_cost: unverified }),
      alias({ id: '5', public_alias: 'verified' })
    ])

    expect(needing.map(item => item.public_alias)).toEqual(['sold', 'unpriced'])
  })
})

describe('server field mapping', () => {
  /**
   * A 422 that lands on no field looks to the operator like a save that failed for no
   * reason, so every key the flat write contract can reject has to reach the grouped
   * form field that produced it.
   */
  it('routes every rejected rate back to the field the operator typed it in', () => {
    expect(aliasPricingFieldName('input_per_million_minor')).toBe('sell.input')
    expect(aliasPricingFieldName('cache_write_per_million_minor')).toBe('sell.cache_write')
    expect(aliasPricingFieldName('upstream_output_per_million_minor')).toBe('upstream.output')
    expect(aliasPricingFieldName('upstream_cache_read_per_million_minor')).toBe('upstream.cache_read')
    expect(aliasPricingFieldName('upstream_cost_verified_at')).toBe('upstream_verified_date')
  })

  it('leaves an unrecognised key alone rather than dropping it', () => {
    expect(aliasPricingFieldName('currency')).toBe('currency')
    expect(aliasPricingFieldName('reason')).toBe('reason')
    expect(aliasPricingFieldName('something_new')).toBe('something_new')
  })
})
