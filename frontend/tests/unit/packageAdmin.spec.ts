import { describe, expect, it } from 'vitest'
import type { AdminModelAlias, AdminPackage } from '~/types/admin'
import {
  aliasCurrencyMismatches,
  buildPackageInput,
  clonePackageForm,
  emptyPackageForm,
  packageFieldName,
  packageFormFrom,
  packageFormProblems,
  projectProfitability,
  splitDuration,
  willPublish
} from '~/utils/packageAdmin'

/**
 * The package builder's two dangerous operations.
 *
 * `PUT /admin/packages/{id}` is a full replacement, so the round trip is a
 * correctness property, not a convenience: any field `packageFormFrom` drops or
 * `buildPackageInput` fails to send is a field the operator erases by opening a
 * package and pressing save. The first block below asserts that on a package with
 * every optional field populated, and again on one with every optional field null.
 *
 * The margin projection is the second. It exists so the operator learns that
 * publishing needs a written override before the control plane answers 409, which
 * only helps if it agrees with `PackageProfitabilityService::analyze` — including
 * the parts of that service that are counter-intuitive.
 */

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

/** A package with every optional field populated, so nothing can be lost unnoticed. */
const fullPackage = (overrides: Partial<AdminPackage> = {}): AdminPackage => ({
  id: '7',
  slug: 'claude-coding-20m',
  name: 'Claude Coding 20M',
  subtitle: 'For sustained agent sessions',
  badge: 'Popular',
  billing_mode: 'TOKEN_QUOTA',
  family: 'claude-coding',
  family_label: 'Claude Coding',
  advertised_units: '20000000',
  unit_label: 'tokens',
  price_minor: '4900',
  compare_at_price_minor: '6900',
  currency: 'USD',
  currency_exponent: 2,
  price: { minor: '4900', currency: 'USD', exponent: 2 },
  compare_at_price: { minor: '6900', currency: 'USD', exponent: 2 },
  duration_seconds: 2_592_000,
  stock_quantity: null,
  stock_status: 'UNLIMITED',
  limits: {
    requests_per_minute: 60,
    tokens_per_minute: 200_000,
    concurrency: 4,
    max_request_bytes: 1_048_576,
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
  sort_order: 3,
  starts_at: '2026-09-01T00:00:00Z',
  ends_at: '2026-12-31T23:59:59Z',
  allowed_model_alias_ids: [4, 9],
  allowed_model_aliases: ['claude-coding', 'sp-auto'],
  enabled: true,
  customer_visible: true,
  minimum_margin_bps: 2500,
  profitability_override_reason: 'Launch pricing approved by the owner for August.',
  profitability: {
    reviewable: true,
    profitable: true,
    price_minor: '4900',
    worst_case_cost_minor: '2000',
    margin_minor: '2900',
    margin_bps: 5918,
    minimum_margin_bps: 2500,
    missing_cost_aliases: [],
    override_required: false
  },
  ...overrides
})

describe('package form round trip', () => {
  it('sends back exactly what it read when nothing is edited', () => {
    const item = fullPackage()

    expect(buildPackageInput(packageFormFrom(item))).toEqual({
      slug: item.slug,
      name: item.name,
      subtitle: item.subtitle,
      badge: item.badge,
      billing_mode: item.billing_mode,
      family: item.family,
      family_label: item.family_label,
      advertised_units: 20_000_000,
      unit_label: item.unit_label,
      price_minor: 4900,
      compare_at_price_minor: 6900,
      currency: 'USD',
      currency_exponent: 2,
      duration_seconds: 2_592_000,
      stock_quantity: null,
      limits: item.limits,
      billing_rules: item.billing_rules,
      auto_creates_api_key: true,
      featured: true,
      sort_order: 3,
      starts_at: '2026-09-01T00:00:00Z',
      ends_at: '2026-12-31T23:59:59Z',
      enabled: true,
      customer_visible: true,
      minimum_margin_bps: 2500,
      profitability_override_reason: item.profitability_override_reason,
      allowed_model_alias_ids: [4, 9]
    })
  })

  it('preserves absent optional fields as null rather than inventing values', () => {
    const bare = fullPackage({
      subtitle: null,
      badge: null,
      compare_at_price_minor: null,
      compare_at_price: null,
      limits: null,
      billing_rules: null,
      starts_at: null,
      ends_at: null,
      profitability_override_reason: null
    })

    const input = buildPackageInput(packageFormFrom(bare))

    expect(input.subtitle).toBeNull()
    expect(input.badge).toBeNull()
    expect(input.compare_at_price_minor).toBeNull()
    expect(input.starts_at).toBeNull()
    expect(input.ends_at).toBeNull()
    expect(input.profitability_override_reason).toBeNull()
    expect(input.billing_rules).toBeNull()
    // `limits` is validated as `present` server-side, so it must be sent as an
    // object even when the package records no ceiling at all.
    expect(input.limits).toEqual({})
  })

  it('keeps a partially limited package partial instead of filling in zeros', () => {
    const item = fullPackage({ limits: { concurrency: 2 }, billing_rules: { input_weight_microunits: 0 } })

    const input = buildPackageInput(packageFormFrom(item))

    expect(input.limits).toEqual({ concurrency: 2 })
    // Zero is a real weight — that token class is not metered — and must survive.
    expect(input.billing_rules).toEqual({ input_weight_microunits: 0 })
  })

  it('does not round-trip a lifetime through a unit that would change it', () => {
    // 90 minutes is a whole number of neither days nor hours. Editing it as "1 day"
    // or "1 hour" and saving would silently change how long customers keep access,
    // so it drops to the one unit that survives the trip.
    const form = packageFormFrom(fullPackage({ duration_seconds: 5400 }))

    expect(form.duration_unit).toBe('second')
    expect(form.duration_amount).toBe('5400')
    expect(buildPackageInput(form).duration_seconds).toBe(5400)
  })

  it('expresses a lifetime in the largest unit that keeps it exact', () => {
    expect(splitDuration(86_400)).toEqual({ amount: 1, unit: 'day' })
    expect(splitDuration(259_200)).toEqual({ amount: 3, unit: 'day' })
    expect(splitDuration(3600)).toEqual({ amount: 1, unit: 'hour' })
    expect(splitDuration(7200)).toEqual({ amount: 2, unit: 'hour' })
    expect(splitDuration(5400)).toEqual({ amount: 5400, unit: 'second' })
    expect(splitDuration(90)).toEqual({ amount: 90, unit: 'second' })
    expect(splitDuration(1)).toEqual({ amount: 1, unit: 'second' })
  })
})

describe('cloning a package', () => {
  it('carries the commercial configuration but never the identity or the sale', () => {
    const clone = clonePackageForm(fullPackage())

    expect(clone.slug).toBe('')
    expect(clone.price_minor).toBe('4900')
    expect(clone.advertised_units).toBe('20000000')
    expect(clone.allowed_model_alias_ids).toEqual([4, 9])
    // A clone must not put a second package on sale by inheriting a flag.
    expect(clone.enabled).toBe(false)
    expect(clone.customer_visible).toBe(false)
    // The old justification does not describe the new package.
    expect(clone.profitability_override_reason).toBe('')
  })

  it('does not alias the source package model selection', () => {
    const source = fullPackage()
    const clone = clonePackageForm(source)

    clone.allowed_model_alias_ids.push(99)

    expect(source.allowed_model_alias_ids).toEqual([4, 9])
  })
})

describe('package form validation', () => {
  const valid = () => packageFormFrom(fullPackage())
  const namesOf = (state: ReturnType<typeof valid>, context?: { existingSlugs?: string[] }) =>
    packageFormProblems(state, context).map(problem => problem.name)

  it('accepts a package loaded straight from the control plane', () => {
    expect(packageFormProblems(valid())).toEqual([])
  })

  it('requires the commercial values a new package cannot default', () => {
    const names = namesOf(emptyPackageForm())

    expect(names).toContain('slug')
    expect(names).toContain('name')
    expect(names).toContain('advertised_units')
    expect(names).toContain('price_minor')
    expect(names).toContain('unit_label')
    expect(names).toContain('duration_amount')
    expect(names).toContain('minimum_margin_bps')
    expect(names).toContain('allowed_model_alias_ids')
  })

  it('rejects a package that allows no model, which could not serve a request', () => {
    const state = valid()

    state.allowed_model_alias_ids = []

    expect(namesOf(state)).toContain('allowed_model_alias_ids')
  })

  it('rejects a duplicate slug, and does not flag the package being edited', () => {
    const state = valid()

    expect(namesOf(state, { existingSlugs: ['other-package'] })).not.toContain('slug')
    expect(namesOf(state, { existingSlugs: ['claude-coding-20m'] })).toContain('slug')
  })

  it('refuses a fractional quantity rather than truncating it into a quota', () => {
    const state = valid()

    state.advertised_units = '20.5'

    expect(packageFormProblems(state)[0]).toEqual({
      name: 'advertised_units',
      message: 'Enter a whole number, with no decimal point or separators.'
    })
  })

  it('refuses a thousands separator in a price', () => {
    const state = valid()

    state.price_minor = '4,900'

    expect(namesOf(state)).toContain('price_minor')
  })

  it('allows a free package but not a negative one', () => {
    const state = valid()

    state.price_minor = '0'
    expect(namesOf(state)).not.toContain('price_minor')

    state.price_minor = '-100'
    expect(namesOf(state)).toContain('price_minor')
  })

  it('mirrors the server bounds on margin floor and currency exponent', () => {
    const state = valid()

    state.minimum_margin_bps = '10001'
    expect(namesOf(state)).toContain('minimum_margin_bps')

    state.minimum_margin_bps = '10000'
    expect(namesOf(state)).not.toContain('minimum_margin_bps')

    state.currency_exponent = '7'
    expect(namesOf(state)).toContain('currency_exponent')
  })

  it('mirrors the minimum request size, which differs from the other limits', () => {
    const state = valid()

    state.limits.max_request_bytes = '512'
    expect(namesOf(state)).toContain('limits.max_request_bytes')

    state.limits.max_request_bytes = '1024'
    expect(namesOf(state)).not.toContain('limits.max_request_bytes')
  })

  it('accepts a zero metering weight, which means that class is not metered', () => {
    const state = valid()

    state.weights.reasoning_weight_microunits = '0'

    expect(namesOf(state)).not.toContain('weights.reasoning_weight_microunits')
  })

  it('rejects an end date on or before the start date', () => {
    const state = valid()

    state.starts_date = '2026-09-01'
    state.ends_date = '2026-09-01'

    expect(namesOf(state)).toContain('ends_date')
  })

  it('requires an override reason to be usable or absent, never a stub', () => {
    const state = valid()

    state.profitability_override_reason = 'too short'
    expect(namesOf(state)).toContain('profitability_override_reason')

    state.profitability_override_reason = ''
    expect(namesOf(state)).not.toContain('profitability_override_reason')
  })

  it('requires a three-letter currency code', () => {
    const state = valid()

    state.currency = 'US'
    expect(namesOf(state)).toContain('currency')

    state.currency = 'usd'
    expect(namesOf(state)).not.toContain('currency')
    expect(buildPackageInput(state).currency).toBe('USD')
  })
})

describe('projected profitability', () => {
  /**
   * 20M units at a worst-case 500 minor per million is 10000 minor of cost. A 49.00
   * package therefore loses money, and the projection has to say so before the
   * operator hits the control plane's 409.
   */
  it('takes the highest upstream rate across every selected alias and token class', () => {
    const projected = projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [alias({ id: '1' })]
    })

    expect(projected.reviewable).toBe(true)
    expect(projected.worstCaseCostMinor).toBe('10000')
    expect(projected.marginMinor).toBe('-5100')
    expect(projected.profitable).toBe(false)
    expect(projected.overrideRequired).toBe(true)
  })

  it('agrees with the margin the control plane returned for the same package', () => {
    // The fixture's own analysis: 20M units, worst case 2000 minor, price 4900.
    const cheap = alias({
      id: 'cheap',
      upstream_cost: {
        input_per_million_minor: '50',
        output_per_million_minor: '100',
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: null,
        verified_at: '2026-08-01T00:00:00Z'
      }
    })

    const projected = projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [cheap]
    })

    expect(projected.worstCaseCostMinor).toBe('2000')
    expect(projected.marginMinor).toBe('2900')
    expect(projected.marginBps).toBe(5918)
    expect(projected.profitable).toBe(true)
    expect(projected.overrideRequired).toBe(false)
  })

  /**
   * The backend builds its cost set from input, output, cache-read and cache-write
   * only. Including the reasoning rate here would report a worse margin than the
   * control plane will, and block a publication it would have allowed.
   */
  it('excludes the reasoning rate, matching the backend cost set', () => {
    const reasoningHeavy = alias({
      id: 'reason',
      upstream_cost: {
        input_per_million_minor: '100',
        output_per_million_minor: '100',
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: '900000',
        verified_at: '2026-08-01T00:00:00Z'
      }
    })

    expect(projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [reasoningHeavy]
    }).worstCaseCostMinor).toBe('2000')
  })

  /**
   * An unverified cost is not a low cost. Treating it as reviewable would show a
   * healthy margin for a package whose true cost nobody has checked.
   */
  it('treats an unverified upstream cost as no known cost at all', () => {
    const unverified = alias({
      id: 'unverified',
      upstream_cost: {
        input_per_million_minor: '100',
        output_per_million_minor: '500',
        cache_read_per_million_minor: null,
        cache_write_per_million_minor: null,
        reasoning_per_million_minor: null,
        verified_at: null
      }
    })

    const projected = projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [unverified]
    })

    expect(projected.reviewable).toBe(false)
    expect(projected.profitable).toBeNull()
    expect(projected.marginMinor).toBeNull()
    expect(projected.missingCostAliases).toEqual(['alias-unverified'])
    expect(projected.overrideRequired).toBe(true)
  })

  it('treats an alias with no pricing record at all as missing its cost', () => {
    const unpriced = alias({ id: 'unpriced', currency: null, exponent: null, sell: null, upstream_cost: null })

    expect(projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [unpriced]
    }).missingCostAliases).toEqual(['alias-unpriced'])
  })

  it('reports one unpriced alias among priced ones, because the worst case is unknown', () => {
    const projected = projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [alias({ id: '1' }), alias({ id: '2', upstream_cost: null })]
    })

    expect(projected.reviewable).toBe(false)
    expect(projected.missingCostAliases).toEqual(['alias-2'])
  })

  it('cannot review a package that allows no model', () => {
    expect(projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: []
    }).reviewable).toBe(false)
  })

  it('reports no margin percentage for a free package rather than dividing by zero', () => {
    const projected = projectProfitability({
      priceMinor: '0',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [alias({ id: '1' })]
    })

    expect(projected.marginBps).toBeNull()
    expect(projected.profitable).toBeNull()
    expect(projected.overrideRequired).toBe(true)
  })

  /** Rounding down here would show a positive margin on a package that has none. */
  it('rounds a partial million of cost up, never down', () => {
    const projected = projectProfitability({
      priceMinor: '100',
      // One unit over a whole million, at 1000 minor per million.
      advertisedUnits: '1000001',
      minimumMarginBps: 0,
      aliases: [alias({
        id: 'ceil',
        upstream_cost: {
          input_per_million_minor: '1000',
          output_per_million_minor: '0',
          cache_read_per_million_minor: null,
          cache_write_per_million_minor: null,
          reasoning_per_million_minor: null,
          verified_at: '2026-08-01T00:00:00Z'
        }
      })]
    })

    expect(projected.worstCaseCostMinor).toBe('1001')
  })

  it('stays exact on quantities and rates far past the float-safe range', () => {
    const projected = projectProfitability({
      priceMinor: '999999999999999999999',
      advertisedUnits: '200000000000000000000',
      minimumMarginBps: 0,
      aliases: [alias({
        id: 'huge',
        upstream_cost: {
          input_per_million_minor: '1000000000000',
          output_per_million_minor: null,
          cache_read_per_million_minor: null,
          cache_write_per_million_minor: null,
          reasoning_per_million_minor: null,
          verified_at: '2026-08-01T00:00:00Z'
        }
      })]
    })

    expect(projected.worstCaseCostMinor).toBe('200000000000000000000000000')
  })

  it('ignores a non-numeric rate instead of coercing it', () => {
    const projected = projectProfitability({
      priceMinor: '4900',
      advertisedUnits: '20000000',
      minimumMarginBps: 2500,
      aliases: [alias({
        id: 'bad',
        upstream_cost: {
          input_per_million_minor: 'not a number',
          output_per_million_minor: null,
          cache_read_per_million_minor: null,
          cache_write_per_million_minor: null,
          reasoning_per_million_minor: null,
          verified_at: '2026-08-01T00:00:00Z'
        }
      })]
    })

    expect(projected.reviewable).toBe(false)
    expect(projected.missingCostAliases).toEqual(['alias-bad'])
  })
})

describe('alias currency mismatch', () => {
  /**
   * The control plane compares raw minor units across aliases without converting
   * currencies, so a package priced in USD whose alias costs are in KHR gets a
   * margin figure that means nothing. Nothing in the response says so.
   */
  it('names aliases priced in another currency or scale', () => {
    const aliases = [
      alias({ id: 'usd' }),
      alias({ id: 'khr', currency: 'KHR', exponent: 0 }),
      alias({ id: 'scale', currency: 'USD', exponent: 4 })
    ]

    expect(aliasCurrencyMismatches(aliases, 'USD', 2)).toEqual(['alias-khr', 'alias-scale'])
  })

  it('does not flag an unpriced alias, which has no currency to disagree with', () => {
    expect(aliasCurrencyMismatches([alias({ id: 'none', currency: null, exponent: null })], 'USD', 2)).toEqual([])
  })

  it('compares the currency code case-insensitively', () => {
    expect(aliasCurrencyMismatches([alias({ id: 'lower', currency: 'usd' })], 'USD', 2)).toEqual([])
  })
})

describe('publication gate', () => {
  it('only counts as publishing when the package is both enabled and visible', () => {
    const state = emptyPackageForm()

    expect(willPublish(state)).toBe(false)

    state.enabled = true
    expect(willPublish(state)).toBe(false)

    state.customer_visible = true
    expect(willPublish(state)).toBe(true)
  })
})

describe('server field mapping', () => {
  /**
   * A 422 that lands on no field looks to the operator like a save that failed for no
   * reason, so every key the write contract can reject has to reach the form field
   * that produced it.
   */
  it('translates the names the form does not share with the contract', () => {
    expect(packageFieldName('duration_seconds')).toBe('duration_amount')
    expect(packageFieldName('starts_at')).toBe('starts_date')
    expect(packageFieldName('ends_at')).toBe('ends_date')
    expect(packageFieldName('billing_rules.output_weight_microunits')).toBe('weights.output_weight_microunits')
  })

  it('collapses an indexed model rejection onto the field that selects them', () => {
    expect(packageFieldName('allowed_model_alias_ids')).toBe('allowed_model_alias_ids')
    expect(packageFieldName('allowed_model_alias_ids.0')).toBe('allowed_model_alias_ids')
    expect(packageFieldName('allowed_model_alias_ids.7')).toBe('allowed_model_alias_ids')
  })

  it('leaves the names that already agree alone, including the nested limits', () => {
    expect(packageFieldName('slug')).toBe('slug')
    expect(packageFieldName('price_minor')).toBe('price_minor')
    expect(packageFieldName('limits.max_request_bytes')).toBe('limits.max_request_bytes')
    expect(packageFieldName('profitability_override_reason')).toBe('profitability_override_reason')
    expect(packageFieldName('something_new')).toBe('something_new')
  })
})
