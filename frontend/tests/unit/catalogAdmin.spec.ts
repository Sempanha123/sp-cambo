import { describe, expect, it } from 'vitest'
import type { AdminPackage, PackageProfitability } from '~/types/admin'
import {
  aliasesMissingUpstreamCost,
  isPackageAtRisk,
  isPackageLive,
  parseOptionalInteger,
  resolvePackageScope
} from '~/utils/catalogAdmin'

const profitability = (overrides: Partial<PackageProfitability> = {}): PackageProfitability => ({
  reviewable: true,
  profitable: true,
  price_minor: '1000',
  worst_case_cost_minor: '400',
  margin_minor: '600',
  margin_bps: 6000,
  minimum_margin_bps: 2000,
  missing_cost_aliases: [],
  override_required: false,
  ...overrides
})

const pkg = (overrides: Partial<AdminPackage> & { id: string }): AdminPackage => ({
  slug: `package-${overrides.id}`,
  name: 'Package',
  subtitle: null,
  badge: null,
  billing_mode: 'TOKEN_QUOTA',
  family: 'standard',
  family_label: 'Standard',
  advertised_units: '20000000',
  unit_label: 'tokens',
  price_minor: '1000',
  compare_at_price_minor: null,
  currency: 'USD',
  currency_exponent: 2,
  price: { minor: '1000', currency: 'USD', exponent: 2 },
  compare_at_price: null,
  duration_seconds: 86_400,
  limits: null,
  billing_rules: null,
  auto_creates_api_key: false,
  featured: false,
  sort_order: 1,
  starts_at: null,
  ends_at: null,
  allowed_model_alias_ids: [1],
  allowed_model_aliases: ['sp-fast'],
  enabled: true,
  customer_visible: true,
  minimum_margin_bps: 2000,
  profitability_override_reason: null,
  profitability: profitability(),
  ...overrides
})

describe('isPackageLive', () => {
  it('requires both flags, because either one alone stops a sale', () => {
    expect(isPackageLive(pkg({ id: '1', enabled: true, customer_visible: true }))).toBe(true)
    expect(isPackageLive(pkg({ id: '2', enabled: false, customer_visible: true }))).toBe(false)
    expect(isPackageLive(pkg({ id: '3', enabled: true, customer_visible: false }))).toBe(false)
    expect(isPackageLive(pkg({ id: '4', enabled: false, customer_visible: false }))).toBe(false)
  })
})

describe('isPackageAtRisk', () => {
  it('leaves a proven, profitable package alone', () => {
    expect(isPackageAtRisk(pkg({ id: '1' }))).toBe(false)
  })

  it('flags a package selling below its own margin floor', () => {
    const item = pkg({
      id: '1',
      profitability: profitability({ profitable: false, margin_bps: 500, override_required: true })
    })

    expect(isPackageAtRisk(item)).toBe(true)
  })

  it('treats an uncomputable margin as risk, not as reassurance', () => {
    /*
     * `profitable: null` means no upstream cost was verified, so the real cost is
     * unknown. Reading that as "not known to be unprofitable" would hide exactly
     * the case where SP Cambo could be selling at a loss without knowing it.
     */
    const item = pkg({
      id: '1',
      profitability: profitability({
        reviewable: false,
        profitable: null,
        worst_case_cost_minor: null,
        margin_minor: null,
        margin_bps: null,
        missing_cost_aliases: ['sp-fast'],
        override_required: true
      })
    })

    expect(isPackageAtRisk(item)).toBe(true)
  })

  it('does not flag an unprofitable package that nobody can buy', () => {
    const hidden = pkg({
      id: '1',
      customer_visible: false,
      profitability: profitability({ profitable: false, margin_bps: -100 })
    })

    // Worth fixing, but not an active commercial risk — no customer can reach it.
    expect(isPackageAtRisk(hidden)).toBe(false)
  })
})

describe('aliasesMissingUpstreamCost', () => {
  it('deduplicates across packages and sorts, so the same gap is named once', () => {
    const items = [
      pkg({ id: '1', profitability: profitability({ missing_cost_aliases: ['sp-pro', 'sp-fast'] }) }),
      pkg({ id: '2', profitability: profitability({ missing_cost_aliases: ['sp-fast'] }) }),
      pkg({ id: '3', profitability: profitability({ missing_cost_aliases: ['sp-air'] }) })
    ]

    expect(aliasesMissingUpstreamCost(items)).toEqual(['sp-air', 'sp-fast', 'sp-pro'])
  })

  it('returns nothing when every cost is verified', () => {
    expect(aliasesMissingUpstreamCost([pkg({ id: '1' })])).toEqual([])
  })

  it('returns nothing for an empty catalogue', () => {
    expect(aliasesMissingUpstreamCost([])).toEqual([])
  })
})

describe('resolvePackageScope', () => {
  const choices = [
    { id: 4, slug: 'starter' },
    { id: 7, slug: 'pro' },
    { id: 9, slug: 'scale' }
  ]

  it('maps slugs to the ids a write needs', () => {
    expect(resolvePackageScope(['pro', 'starter'], choices)).toEqual({
      ids: [7, 4],
      unresolved: []
    })
  })

  it('reports an unmatched slug instead of dropping it', () => {
    /*
     * This is the whole point of the function. An empty or short `package_ids`
     * does not mean "leave the scope alone" — `PromotionService` only restricts a
     * promotion that has at least one package attached, so dropping the unmatched
     * slug would widen the discount to every package in the catalogue.
     */
    expect(resolvePackageScope(['pro', 'retired-bundle'], choices)).toEqual({
      ids: [7],
      unresolved: ['retired-bundle']
    })
  })

  it('reports every slug as unresolved when the package list is empty', () => {
    expect(resolvePackageScope(['pro'], [])).toEqual({ ids: [], unresolved: ['pro'] })
  })

  it('distinguishes an unscoped promotion from an unresolvable one', () => {
    // No slugs at all is a legitimate "applies to everything", not a failure.
    expect(resolvePackageScope([], choices)).toEqual({ ids: [], unresolved: [] })
  })

  it('does not repeat an id when the same slug appears twice', () => {
    expect(resolvePackageScope(['pro', 'pro'], choices)).toEqual({ ids: [7], unresolved: [] })
  })
})

describe('parseOptionalInteger', () => {
  it('reads a whole number', () => {
    expect(parseOptionalInteger('2500')).toBe(2500)
    expect(parseOptionalInteger('0')).toBe(0)
  })

  it('treats blank as absent rather than zero', () => {
    // An empty cap means "no cap"; zero would mean a cap of nothing at all.
    expect(parseOptionalInteger('')).toBeNull()
    expect(parseOptionalInteger('   ')).toBeNull()
  })

  it('trims surrounding whitespace', () => {
    expect(parseOptionalInteger('  42  ')).toBe(42)
  })

  it('rejects a decimal instead of truncating it', () => {
    /*
     * `10.5` reaching an `integer` validator as `10` would be a different price
     * than the operator typed, accepted without complaint. Rejecting is the only
     * safe answer.
     */
    expect(parseOptionalInteger('10.5')).toBeUndefined()
    expect(parseOptionalInteger('10.0')).toBeUndefined()
  })

  it('rejects separators, signs and stray characters', () => {
    expect(parseOptionalInteger('1,000')).toBeUndefined()
    expect(parseOptionalInteger('1 000')).toBeUndefined()
    expect(parseOptionalInteger('+5')).toBeUndefined()
    expect(parseOptionalInteger('-5')).toBeUndefined()
    expect(parseOptionalInteger('25%')).toBeUndefined()
    expect(parseOptionalInteger('1e3')).toBeUndefined()
    expect(parseOptionalInteger('abc')).toBeUndefined()
  })

  it('rejects a value too large to survive the round trip through Number', () => {
    // 2^53 - 1 is the last integer a JS number represents exactly.
    expect(parseOptionalInteger('9007199254740991')).toBe(9_007_199_254_740_991)
    expect(parseOptionalInteger('9007199254740993')).toBeUndefined()
  })
})
