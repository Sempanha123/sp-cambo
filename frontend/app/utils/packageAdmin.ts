import type { AdminModelAlias, AdminPackage, AdminPackageInput } from '~/types/admin'
import type { BillingMode } from '~/types/commerce'
import { parseOptionalInteger } from './catalogAdmin'
import { formatCount } from './format'

/**
 * The package builder's rules, kept out of the component so each one is testable.
 *
 * Three separate jobs live here, and they are separate on purpose:
 *
 * 1. **Round trip.** `packageFormFrom` and `buildPackageInput` must be exact
 *    inverses for every field the write contract accepts. `PUT /admin/packages/{id}`
 *    is a full replacement, so any field this pair loses is a field the operator
 *    silently erases by opening a package and pressing save.
 * 2. **Mirrored validation.** `packageFormProblems` restates the control plane's own
 *    rules so the operator is told before submitting, not after. It is deliberately
 *    a mirror and not a stricter policy: a rule enforced only here would make the
 *    API look inconsistent to anyone using it directly, and the server's 422 remains
 *    authoritative and is mapped back onto these same field names.
 * 3. **Margin projection.** `projectProfitability` mirrors
 *    `PackageProfitabilityService::analyze` so the builder can warn that publishing
 *    will need a written override *before* the control plane answers 409. It is a
 *    projection shown as such, never a stored or billed figure — the server
 *    recomputes and returns the authoritative `profitability` on every read and write.
 *
 * All arithmetic is exact-integer over BigInt. No commercial value is ever put
 * through binary floating point.
 */

/** Unit the operator edits a package lifetime in. Seconds is the stored unit. */
export type DurationUnit = 'day' | 'hour' | 'second'

export const DURATION_UNIT_SECONDS: Record<DurationUnit, number> = {
  day: 86_400,
  hour: 3_600,
  second: 1
}

/** Per-request ceiling keys, with the minimum the control plane enforces on each. */
export const PACKAGE_LIMIT_FIELDS = [
  { key: 'requests_per_minute', label: 'Requests per minute', min: 1 },
  { key: 'tokens_per_minute', label: 'Tokens per minute', min: 1 },
  { key: 'concurrency', label: 'Concurrent requests', min: 1 },
  { key: 'max_request_bytes', label: 'Max request size (bytes)', min: 1024 },
  { key: 'max_output_tokens', label: 'Max output tokens', min: 1 }
] as const

/** Metering weight keys, in microunits per token. Zero is a valid, meaningful weight. */
export const PACKAGE_WEIGHT_FIELDS = [
  { key: 'input_weight_microunits', label: 'Input' },
  { key: 'output_weight_microunits', label: 'Output' },
  { key: 'cache_read_weight_microunits', label: 'Cache read' },
  { key: 'cache_write_weight_microunits', label: 'Cache write' },
  { key: 'reasoning_weight_microunits', label: 'Reasoning' }
] as const

type LimitKey = typeof PACKAGE_LIMIT_FIELDS[number]['key']
type WeightKey = typeof PACKAGE_WEIGHT_FIELDS[number]['key']

/**
 * Every field as the operator edits it.
 *
 * Numbers are held as strings so a half-typed or invalid entry stays visible and is
 * reported, rather than being coerced to a number that was never intended. The
 * conversion happens once, in `buildPackageInput`, after validation has passed.
 */
export interface PackageFormState {
  slug: string
  name: string
  subtitle: string
  badge: string
  billing_mode: BillingMode
  family: string
  family_label: string
  advertised_units: string
  unit_label: string
  price_minor: string
  compare_at_price_minor: string
  currency: string
  currency_exponent: string
  duration_amount: string
  duration_unit: DurationUnit
  /** Blank = unlimited; otherwise exact remaining package inventory. */
  stock_quantity: string
  limits: Record<LimitKey, string>
  weights: Record<WeightKey, string>
  auto_creates_api_key: boolean
  featured: boolean
  sort_order: string
  starts_date: string
  ends_date: string
  enabled: boolean
  customer_visible: boolean
  minimum_margin_bps: string
  profitability_override_reason: string
  allowed_model_alias_ids: number[]
}

const emptyLimits = (): Record<LimitKey, string> => ({
  requests_per_minute: '',
  tokens_per_minute: '',
  concurrency: '',
  max_request_bytes: '',
  max_output_tokens: ''
})

const emptyWeights = (): Record<WeightKey, string> => ({
  input_weight_microunits: '',
  output_weight_microunits: '',
  cache_read_weight_microunits: '',
  cache_write_weight_microunits: '',
  reasoning_weight_microunits: ''
})

/**
 * A new package, drafted rather than published.
 *
 * `enabled` and `customer_visible` both start false so creating a package never
 * puts something on sale as a side effect of filling in a form. Nothing about the
 * commercial values is guessed: price, quantity and family are left blank for the
 * operator to state.
 */
export function emptyPackageForm(): PackageFormState {
  return {
    slug: '',
    name: '',
    subtitle: '',
    badge: '',
    billing_mode: 'TOKEN_QUOTA',
    family: '',
    family_label: '',
    advertised_units: '',
    unit_label: '',
    price_minor: '',
    compare_at_price_minor: '',
    currency: 'USD',
    currency_exponent: '2',
    duration_amount: '',
    duration_unit: 'day',
    stock_quantity: '',
    limits: emptyLimits(),
    weights: emptyWeights(),
    auto_creates_api_key: true,
    featured: false,
    sort_order: '0',
    starts_date: '',
    ends_date: '',
    enabled: false,
    customer_visible: false,
    minimum_margin_bps: '',
    profitability_override_reason: '',
    allowed_model_alias_ids: []
  }
}

/**
 * The largest whole unit a lifetime can be expressed in without losing precision.
 *
 * A package stores exact seconds, and 1 day means exactly 86400 of them. Showing a
 * 90-minute package as "1 day" and writing that back would silently extend it, so a
 * lifetime that is not a whole number of days is edited in hours, and one that is not
 * a whole number of hours is edited in seconds.
 */
export function splitDuration(seconds: number): { amount: number, unit: DurationUnit } {
  if (seconds > 0 && seconds % DURATION_UNIT_SECONDS.day === 0) {
    return { amount: seconds / DURATION_UNIT_SECONDS.day, unit: 'day' }
  }

  if (seconds > 0 && seconds % DURATION_UNIT_SECONDS.hour === 0) {
    return { amount: seconds / DURATION_UNIT_SECONDS.hour, unit: 'hour' }
  }

  return { amount: seconds, unit: 'second' }
}

/** Loads a package into the form exactly as the control plane returned it. */
export function packageFormFrom(item: AdminPackage): PackageFormState {
  const duration = splitDuration(item.duration_seconds)
  const limits = emptyLimits()
  const weights = emptyWeights()

  for (const field of PACKAGE_LIMIT_FIELDS) {
    const value = item.limits?.[field.key]

    if (typeof value === 'number') {
      limits[field.key] = String(value)
    }
  }

  for (const field of PACKAGE_WEIGHT_FIELDS) {
    const value = item.billing_rules?.[field.key]

    if (typeof value === 'number') {
      weights[field.key] = String(value)
    }
  }

  return {
    slug: item.slug,
    name: item.name,
    subtitle: item.subtitle ?? '',
    badge: item.badge ?? '',
    billing_mode: item.billing_mode,
    family: item.family,
    family_label: item.family_label,
    advertised_units: item.advertised_units,
    unit_label: item.unit_label,
    price_minor: item.price_minor,
    compare_at_price_minor: item.compare_at_price_minor ?? '',
    currency: item.currency,
    currency_exponent: String(item.currency_exponent),
    duration_amount: String(duration.amount),
    duration_unit: duration.unit,
    stock_quantity: item.stock_quantity ?? '',
    limits,
    weights,
    auto_creates_api_key: item.auto_creates_api_key,
    featured: item.featured,
    sort_order: String(item.sort_order),
    // Slicing the UTC instant keeps the date the control plane recorded, without
    // reinterpreting it in the operator's local zone.
    starts_date: item.starts_at?.slice(0, 10) ?? '',
    ends_date: item.ends_at?.slice(0, 10) ?? '',
    enabled: item.enabled,
    customer_visible: item.customer_visible,
    minimum_margin_bps: String(item.minimum_margin_bps),
    profitability_override_reason: item.profitability_override_reason ?? '',
    allowed_model_alias_ids: [...item.allowed_model_alias_ids]
  }
}

/**
 * Copies a package into a new draft.
 *
 * The slug is cleared because it is unique, and publication is cleared because a
 * clone must not put a second package on sale by inheriting a flag. Everything
 * commercial is carried over — that is the point of cloning.
 */
export function clonePackageForm(item: AdminPackage): PackageFormState {
  return {
    ...packageFormFrom(item),
    slug: '',
    name: `${item.name} copy`,
    enabled: false,
    customer_visible: false,
    // The old justification does not describe the new package.
    profitability_override_reason: ''
  }
}

const optionalText = (value: string): string | null => {
  const trimmed = value.trim()

  return trimmed === '' ? null : trimmed
}

/** Only the limits the operator actually filled in; a blank field records no ceiling. */
const collectIntegers = <K extends string>(
  entries: Array<{ key: K }>,
  source: Record<K, string>
): Partial<Record<K, number>> => {
  const collected: Partial<Record<K, number>> = {}

  for (const { key } of entries) {
    const parsed = parseOptionalInteger(source[key])

    if (typeof parsed === 'number') {
      collected[key] = parsed
    }
  }

  return collected
}

/**
 * The request body for a validated form.
 *
 * Only ever called after `packageFormProblems` returns empty, so each numeric parse
 * is known to succeed; the `?? 0` fallbacks are unreachable defaults that keep the
 * function total rather than throwing on a caller that skipped validation.
 */
export function buildPackageInput(state: PackageFormState): AdminPackageInput {
  const amount = parseOptionalInteger(state.duration_amount) ?? 0
  const weights = collectIntegers([...PACKAGE_WEIGHT_FIELDS], state.weights)

  return {
    slug: state.slug.trim(),
    name: state.name.trim(),
    subtitle: optionalText(state.subtitle),
    badge: optionalText(state.badge),
    billing_mode: state.billing_mode,
    family: state.family.trim(),
    family_label: state.family_label.trim(),
    advertised_units: parseOptionalInteger(state.advertised_units) ?? 0,
    unit_label: state.unit_label.trim(),
    price_minor: parseOptionalInteger(state.price_minor) ?? 0,
    compare_at_price_minor: parseOptionalInteger(state.compare_at_price_minor) ?? null,
    currency: state.currency.trim().toUpperCase(),
    currency_exponent: parseOptionalInteger(state.currency_exponent) ?? 0,
    duration_seconds: amount * DURATION_UNIT_SECONDS[state.duration_unit],
    stock_quantity: parseOptionalInteger(state.stock_quantity) ?? null,
    // Always an object: the control plane validates `limits` as `present`.
    limits: collectIntegers([...PACKAGE_LIMIT_FIELDS], state.limits),
    // Null rather than `{}` when nothing is weighted, which is how a package with no
    // explicit metering rules is stored.
    billing_rules: Object.keys(weights).length > 0 ? weights : null,
    auto_creates_api_key: state.auto_creates_api_key,
    featured: state.featured,
    sort_order: parseOptionalInteger(state.sort_order) ?? 0,
    starts_at: state.starts_date ? `${state.starts_date}T00:00:00Z` : null,
    ends_at: state.ends_date ? `${state.ends_date}T23:59:59Z` : null,
    enabled: state.enabled,
    customer_visible: state.customer_visible,
    minimum_margin_bps: parseOptionalInteger(state.minimum_margin_bps) ?? 0,
    profitability_override_reason: optionalText(state.profitability_override_reason),
    allowed_model_alias_ids: [...state.allowed_model_alias_ids]
  }
}

export interface PackageFieldProblem {
  name: string
  message: string
}

interface IntegerRule {
  min: number
  max?: number
  required: boolean
}

/**
 * A whole number within range, or a problem naming the field.
 *
 * `parseOptionalInteger` returns `undefined` for anything that is not a plain
 * non-negative integer, so a decimal, a thousands separator or a stray character is
 * reported instead of being truncated on its way into a price or a quota.
 */
const integerProblem = (
  name: string,
  value: string,
  rule: IntegerRule
): PackageFieldProblem | null => {
  const parsed = parseOptionalInteger(value)

  if (parsed === undefined) {
    return { name, message: 'Enter a whole number, with no decimal point or separators.' }
  }

  if (parsed === null) {
    return rule.required ? { name, message: 'This is required.' } : null
  }

  if (parsed < rule.min) {
    return { name, message: `Must be ${formatCount(rule.min)} or more.` }
  }

  if (rule.max !== undefined && parsed > rule.max) {
    return { name, message: `Must be ${formatCount(rule.max)} or less.` }
  }

  return null
}

const textProblem = (
  name: string,
  value: string,
  rule: { max: number, required: boolean, label: string }
): PackageFieldProblem | null => {
  const trimmed = value.trim()

  if (trimmed === '') {
    return rule.required ? { name, message: `${rule.label} is required.` } : null
  }

  return trimmed.length > rule.max
    ? { name, message: `Keep this to ${formatCount(rule.max)} characters or fewer.` }
    : null
}

/**
 * Every rule `PackageController::validated` applies, restated for the operator.
 *
 * `existingSlugs` mirrors the server's uniqueness rule and must exclude the package
 * being edited, or saving an unchanged package would report a false conflict.
 */
export function packageFormProblems(
  state: PackageFormState,
  context: { existingSlugs?: string[] } = {}
): PackageFieldProblem[] {
  const problems: Array<PackageFieldProblem | null> = []
  const slug = state.slug.trim()

  problems.push(textProblem('slug', state.slug, { max: 100, required: true, label: 'A slug' }))

  if (slug !== '' && (context.existingSlugs ?? []).includes(slug)) {
    problems.push({ name: 'slug', message: 'Another package already uses this slug.' })
  }

  problems.push(textProblem('name', state.name, { max: 150, required: true, label: 'A name' }))
  problems.push(textProblem('subtitle', state.subtitle, { max: 255, required: false, label: 'A subtitle' }))
  problems.push(textProblem('badge', state.badge, { max: 100, required: false, label: 'A badge' }))
  problems.push(textProblem('family', state.family, { max: 100, required: true, label: 'A family key' }))
  problems.push(textProblem('family_label', state.family_label, { max: 100, required: true, label: 'A family label' }))
  problems.push(textProblem('unit_label', state.unit_label, { max: 50, required: true, label: 'A unit label' }))

  problems.push(integerProblem('advertised_units', state.advertised_units, { min: 1, required: true }))
  problems.push(integerProblem('price_minor', state.price_minor, { min: 0, required: true }))
  problems.push(integerProblem('compare_at_price_minor', state.compare_at_price_minor, { min: 0, required: false }))

  if (!/^[A-Za-z]{3}$/.test(state.currency.trim())) {
    problems.push({ name: 'currency', message: 'Enter a three-letter currency code.' })
  }

  problems.push(integerProblem('currency_exponent', state.currency_exponent, { min: 0, max: 6, required: true }))
  problems.push(integerProblem('duration_amount', state.duration_amount, { min: 1, required: true }))
  problems.push(integerProblem('stock_quantity', state.stock_quantity, { min: 0, max: 1_000_000_000, required: false }))
  problems.push(integerProblem('sort_order', state.sort_order, { min: 0, required: true }))
  problems.push(integerProblem('minimum_margin_bps', state.minimum_margin_bps, { min: 0, max: 10_000, required: true }))

  for (const field of PACKAGE_LIMIT_FIELDS) {
    problems.push(integerProblem(`limits.${field.key}`, state.limits[field.key], {
      min: field.min,
      required: false
    }))
  }

  for (const field of PACKAGE_WEIGHT_FIELDS) {
    problems.push(integerProblem(`weights.${field.key}`, state.weights[field.key], {
      min: 0,
      required: false
    }))
  }

  if (state.starts_date && state.ends_date && state.ends_date <= state.starts_date) {
    problems.push({ name: 'ends_date', message: 'The end date must fall after the start date.' })
  }

  // Laravel treats an empty array as absent for a `required` rule, so a package with
  // no model is rejected server-side — and rightly: it could not serve a request.
  if (state.allowed_model_alias_ids.length === 0) {
    problems.push({
      name: 'allowed_model_alias_ids',
      message: 'Select at least one model. A package that allows no model cannot serve a request.'
    })
  }

  const reason = state.profitability_override_reason.trim()

  if (reason !== '' && reason.length < 10) {
    problems.push({ name: 'profitability_override_reason', message: 'Use at least 10 characters, or leave it blank.' })
  } else if (reason.length > 2000) {
    problems.push({ name: 'profitability_override_reason', message: 'Keep the note to 2000 characters or fewer.' })
  }

  return problems.filter((problem): problem is PackageFieldProblem => problem !== null)
}

/**
 * Server field name to form field name.
 *
 * The write contract and the form agree on most names, but not all: a lifetime is
 * edited as an amount plus a unit, the schedule is edited as dates, and the metering
 * weights sit under `weights` rather than `billing_rules`. Laravel also indexes array
 * rejections (`allowed_model_alias_ids.0`), which no field is called. Without this
 * translation a 422 lands on no field and the operator sees a rejected save with
 * nothing marked. Anything unrecognised is returned unchanged, which surfaces it on
 * the form-level banner rather than losing it.
 */
export function packageFieldName(serverKey: string): string {
  const weight = /^billing_rules\.(.+)$/.exec(serverKey)

  if (weight) {
    return `weights.${weight[1]}`
  }

  if (serverKey === 'allowed_model_alias_ids' || serverKey.startsWith('allowed_model_alias_ids.')) {
    return 'allowed_model_alias_ids'
  }

  switch (serverKey) {
    case 'duration_seconds':
      return 'duration_amount'
    case 'starts_at':
      return 'starts_date'
    case 'ends_at':
      return 'ends_date'
    default:
      return serverKey
  }
}

/**
 * Projected margin for a form that has not been saved.
 *
 * Field-for-field mirror of `PackageProfitabilityService::analyze`, including the
 * parts that are surprising:
 *
 * - the worst case is the single highest upstream per-million rate across every
 *   selected alias and token class, scaled by the advertised quantity — a ceiling
 *   on cost, not a forecast;
 * - the reasoning rate is **not** part of that set, matching the backend;
 * - an alias whose upstream cost is unverified counts as no known cost at all,
 *   however many rates it has, and makes the whole package unreviewable;
 * - `profitable: null` means unknown and must never be shown as zero or as a pass.
 */
export interface ProjectedProfitability {
  /** False when no margin can be computed. Every figure below is then null. */
  reviewable: boolean
  profitable: boolean | null
  worstCaseCostMinor: string | null
  marginMinor: string | null
  marginBps: number | null
  /** Selected aliases with no verified upstream cost. */
  missingCostAliases: string[]
  /** True unless profitability is positively established. Publication then needs a reason. */
  overrideRequired: boolean
}

const MILLION = 1_000_000n

/**
 * `units × costPerMillion ÷ 1e6`, rounded up, in exact integer arithmetic.
 *
 * Mirrors `PackageProfitabilityService::ceilScaledCost`, which splits the whole and
 * fractional millions rather than dividing once. Rounding up is deliberate: a cost
 * ceiling that rounded down could show a positive margin on a package that has none.
 */
function ceilScaledCost(units: bigint, costPerMillion: bigint): bigint {
  const whole = units / MILLION
  const remainder = units % MILLION

  return (whole * costPerMillion) + (((remainder * costPerMillion) + (MILLION - 1n)) / MILLION)
}

/** An exact non-negative integer string, or null for anything else. */
const toBigInt = (value: string | null | undefined): bigint | null => {
  if (typeof value !== 'string' || !/^\d+$/.test(value.trim())) {
    return null
  }

  return BigInt(value.trim())
}

/**
 * The upstream rates profitability is computed from, excluding reasoning.
 *
 * The exclusion is the backend's, not a simplification: `analyze` builds its cost
 * set from the input, output, cache-read and cache-write rates only.
 */
const upstreamCostSet = (alias: AdminModelAlias): bigint[] => {
  const upstream = alias.upstream_cost

  if (!upstream || !upstream.verified_at) {
    return []
  }

  return [
    upstream.input_per_million_minor,
    upstream.output_per_million_minor,
    upstream.cache_read_per_million_minor,
    upstream.cache_write_per_million_minor
  ]
    .map(toBigInt)
    .filter((value): value is bigint => value !== null)
}

export function projectProfitability(input: {
  priceMinor: string
  advertisedUnits: string
  minimumMarginBps: number
  /** The aliases selected on the form, resolved from `GET /admin/model-aliases`. */
  aliases: AdminModelAlias[]
}): ProjectedProfitability {
  const missingCostAliases: string[] = []
  let worstPerMillion = 0n

  for (const alias of input.aliases) {
    const costs = upstreamCostSet(alias)

    if (costs.length === 0) {
      missingCostAliases.push(alias.public_alias)
      continue
    }

    for (const cost of costs) {
      if (cost > worstPerMillion) {
        worstPerMillion = cost
      }
    }
  }

  const price = toBigInt(input.priceMinor)
  const units = toBigInt(input.advertisedUnits)

  const reviewable = input.aliases.length > 0
    && missingCostAliases.length === 0
    && price !== null
    && units !== null

  if (!reviewable || price === null || units === null) {
    return {
      reviewable: false,
      profitable: null,
      worstCaseCostMinor: null,
      marginMinor: null,
      marginBps: null,
      missingCostAliases,
      overrideRequired: true
    }
  }

  const cost = ceilScaledCost(units, worstPerMillion)
  const margin = price - cost
  // A zero price has no margin percentage to express, matching the backend.
  const marginBps = price === 0n ? null : Number((margin * 10_000n) / price)
  const profitable = marginBps === null ? null : marginBps >= input.minimumMarginBps

  return {
    reviewable: true,
    profitable,
    worstCaseCostMinor: cost.toString(),
    marginMinor: margin.toString(),
    marginBps,
    missingCostAliases,
    overrideRequired: profitable !== true
  }
}

/**
 * Selected aliases priced in a different currency or scale than the package.
 *
 * Worth surfacing because the control plane's worst-case cost compares raw minor
 * units across aliases without converting between currencies. A package priced in
 * USD whose alias costs are recorded in KHR yields a margin figure that is not
 * meaningful, and nothing else in the response says so.
 */
export function aliasCurrencyMismatches(
  aliases: AdminModelAlias[],
  packageCurrency: string,
  packageExponent: number
): string[] {
  const currency = packageCurrency.trim().toUpperCase()

  return aliases
    .filter(alias => alias.currency !== null
      && (alias.currency.toUpperCase() !== currency || alias.exponent !== packageExponent))
    .map(alias => alias.public_alias)
}

/**
 * Whether saving would publish the package, which is what the 409 gate applies to.
 *
 * Both flags together, matching `assertPublishable`: an enabled-but-hidden package
 * is not published and is never blocked on margin.
 */
export function willPublish(state: PackageFormState): boolean {
  return state.enabled && state.customer_visible
}
