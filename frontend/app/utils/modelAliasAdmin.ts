import type { AdminModelAlias, ModelAliasPricingInput } from '~/types/admin'
import { parseOptionalInteger } from './catalogAdmin'
import { formatCount } from './format'

/**
 * The model-alias pricing editor's rules, kept out of the component so each is testable.
 *
 * `PUT /admin/model-aliases/{id}/pricing` is `updateOrCreate` with a full attribute
 * set, so it replaces a pricing record rather than patching it: any rate this module
 * fails to carry back is a rate the operator erases by opening an alias and pressing
 * save. That makes the round trip a correctness property, not a convenience.
 *
 * Two things here are subtler than they look:
 *
 * - **`upstream_cost_verified_at` is a gate, not a note.** An alias whose upstream
 *   cost is unverified counts as having *no known cost at all* in
 *   `PackageProfitabilityService::analyze`, however many rates are filled in — so
 *   every package allowing it becomes unreviewable. Clearing the verification is a
 *   commercial act and is presented as one.
 * - **Sell and upstream share one currency and exponent.** They are columns of the
 *   same record, so comparing them is exact and meaningful; comparing across aliases
 *   is not, which is why that check lives in `packageAdmin.ts` instead.
 *
 * All arithmetic is exact-integer over BigInt. No rate is ever put through a float.
 */

/** The five per-million rate classes, in the order the control plane stores them. */
export const ALIAS_RATE_FIELDS = [
  { key: 'input', label: 'Input', required: true },
  { key: 'output', label: 'Output', required: true },
  { key: 'cache_read', label: 'Cache read', required: false },
  { key: 'cache_write', label: 'Cache write', required: false },
  { key: 'reasoning', label: 'Reasoning', required: false }
] as const

export type AliasRateKey = typeof ALIAS_RATE_FIELDS[number]['key']

/**
 * The rates profitability is actually computed from.
 *
 * Reasoning is excluded because the backend excludes it: `analyze` builds its cost
 * set from input, output, cache-read and cache-write only. Mirrored here so the
 * advisory notes agree with the margin the control plane will report.
 */
export const PROFITABILITY_RATE_KEYS: AliasRateKey[] = ['input', 'output', 'cache_read', 'cache_write']

type RateMap = Record<AliasRateKey, string>

/**
 * Every field as the operator edits it.
 *
 * Rates are held as strings so a half-typed or invalid entry stays visible and is
 * reported, rather than being coerced to a number nobody intended. Conversion
 * happens once, in `buildAliasPricingInput`, after validation has passed.
 */
export interface AliasPricingFormState {
  currency: string
  exponent: string
  /** What customers are charged. Input and output are mandatory. */
  sell: RateMap
  /** What SP Cambo pays upstream. Every class is optional. */
  upstream: RateMap
  /** Whether the upstream rates above have been checked against the provider. */
  upstream_verified: boolean
  /** `yyyy-mm-dd`, meaningful only while `upstream_verified` is true. */
  upstream_verified_date: string
  reason: string
}

const emptyRates = (): RateMap => ({
  input: '',
  output: '',
  cache_read: '',
  cache_write: '',
  reasoning: ''
})

/**
 * A blank pricing record for an alias that has never been priced.
 *
 * Nothing commercial is guessed: every rate is left empty for the operator to state.
 * `USD` and exponent 2 are the transport shape of a currency, not a price.
 */
export function emptyAliasPricingForm(): AliasPricingFormState {
  return {
    currency: 'USD',
    exponent: '2',
    sell: emptyRates(),
    upstream: emptyRates(),
    upstream_verified: false,
    upstream_verified_date: '',
    reason: ''
  }
}

/** True when an alias carries no pricing record at all — a real state, not zero. */
export function isAliasUnpriced(alias: AdminModelAlias): boolean {
  return alias.sell === null && alias.upstream_cost === null
}

/**
 * Loads an alias into the form exactly as the control plane returned it.
 *
 * An alias with no pricing record yields a blank form rather than zeros, because a
 * rate of zero is a real price — free — and must never be invented on the operator's
 * behalf.
 */
export function aliasPricingFormFrom(alias: AdminModelAlias): AliasPricingFormState {
  if (isAliasUnpriced(alias)) {
    return emptyAliasPricingForm()
  }

  const sell = emptyRates()
  const upstream = emptyRates()

  sell.input = alias.sell?.input_per_million_minor ?? ''
  sell.output = alias.sell?.output_per_million_minor ?? ''
  sell.cache_read = alias.sell?.cache_read_per_million_minor ?? ''
  sell.cache_write = alias.sell?.cache_write_per_million_minor ?? ''
  sell.reasoning = alias.sell?.reasoning_per_million_minor ?? ''

  upstream.input = alias.upstream_cost?.input_per_million_minor ?? ''
  upstream.output = alias.upstream_cost?.output_per_million_minor ?? ''
  upstream.cache_read = alias.upstream_cost?.cache_read_per_million_minor ?? ''
  upstream.cache_write = alias.upstream_cost?.cache_write_per_million_minor ?? ''
  upstream.reasoning = alias.upstream_cost?.reasoning_per_million_minor ?? ''

  const verifiedAt = alias.upstream_cost?.verified_at ?? null

  return {
    currency: alias.currency ?? 'USD',
    exponent: alias.exponent === null || alias.exponent === undefined ? '2' : String(alias.exponent),
    sell,
    upstream,
    upstream_verified: verifiedAt !== null,
    // Slicing the UTC instant keeps the date the control plane recorded, without
    // reinterpreting it in the operator's local zone.
    upstream_verified_date: verifiedAt?.slice(0, 10) ?? '',
    reason: ''
  }
}

export interface AliasPricingContext {
  /**
   * The instant the loaded alias recorded, if any.
   *
   * Sent back verbatim when the operator has not moved the date, so re-saving an
   * unrelated rate does not quietly reset a verification from 14:30 to midnight.
   */
  originalVerifiedAt?: string | null
}

const rate = (value: string): number | null => parseOptionalInteger(value) ?? null

/**
 * The request body for a validated form.
 *
 * Only ever called after `aliasPricingProblems` returns empty, so each parse is known
 * to succeed; the `?? 0` fallbacks on the two mandatory rates are unreachable defaults
 * that keep the function total rather than throwing on a caller that skipped validation.
 */
export function buildAliasPricingInput(
  state: AliasPricingFormState,
  context: AliasPricingContext = {}
): ModelAliasPricingInput {
  return {
    currency: state.currency.trim().toUpperCase(),
    exponent: parseOptionalInteger(state.exponent) ?? 0,
    input_per_million_minor: parseOptionalInteger(state.sell.input) ?? 0,
    output_per_million_minor: parseOptionalInteger(state.sell.output) ?? 0,
    cache_read_per_million_minor: rate(state.sell.cache_read),
    cache_write_per_million_minor: rate(state.sell.cache_write),
    reasoning_per_million_minor: rate(state.sell.reasoning),
    upstream_input_per_million_minor: rate(state.upstream.input),
    upstream_output_per_million_minor: rate(state.upstream.output),
    upstream_cache_read_per_million_minor: rate(state.upstream.cache_read),
    upstream_cache_write_per_million_minor: rate(state.upstream.cache_write),
    upstream_reasoning_per_million_minor: rate(state.upstream.reasoning),
    upstream_cost_verified_at: resolveVerifiedAt(state, context),
    reason: state.reason.trim()
  }
}

/**
 * The verification instant to store.
 *
 * Unverified sends null, which is what removes every package allowing this alias from
 * profitability analysis. Verified keeps the original instant when the date is
 * unchanged, and otherwise records midnight UTC on the date the operator chose.
 */
function resolveVerifiedAt(state: AliasPricingFormState, context: AliasPricingContext): string | null {
  if (!state.upstream_verified) {
    return null
  }

  const original = context.originalVerifiedAt ?? null

  if (original !== null && original.slice(0, 10) === state.upstream_verified_date) {
    return original
  }

  return state.upstream_verified_date === '' ? null : `${state.upstream_verified_date}T00:00:00Z`
}

export interface AliasPricingProblem {
  name: string
  message: string
}

const integerProblem = (
  name: string,
  value: string,
  rule: { required: boolean, max?: number }
): AliasPricingProblem | null => {
  const parsed = parseOptionalInteger(value)

  if (parsed === undefined) {
    return { name, message: 'Enter a whole number of minor units, with no decimal point or separators.' }
  }

  if (parsed === null) {
    return rule.required ? { name, message: 'This rate is required.' } : null
  }

  if (rule.max !== undefined && parsed > rule.max) {
    return { name, message: `Must be ${formatCount(rule.max)} or less.` }
  }

  return null
}

/**
 * Every rule `ModelPricingController::update` applies, restated for the operator.
 *
 * Deliberately a mirror and not a stricter policy: a rule enforced only here would
 * make the API look inconsistent to anyone using it directly. The server remains the
 * authority and its 422 is mapped back onto these same field names. Judgements that
 * are worth raising but are not rejections — selling below cost, verifying a cost
 * with no rates — are returned by `aliasPricingAdvisories` instead.
 */
export function aliasPricingProblems(state: AliasPricingFormState): AliasPricingProblem[] {
  const problems: Array<AliasPricingProblem | null> = []

  if (!/^[A-Za-z]{3}$/.test(state.currency.trim())) {
    problems.push({ name: 'currency', message: 'Enter a three-letter currency code.' })
  }

  problems.push(integerProblem('exponent', state.exponent, { required: true, max: 6 }))

  for (const field of ALIAS_RATE_FIELDS) {
    problems.push(integerProblem(`sell.${field.key}`, state.sell[field.key], { required: field.required }))
    problems.push(integerProblem(`upstream.${field.key}`, state.upstream[field.key], { required: false }))
  }

  if (state.upstream_verified && state.upstream_verified_date === '') {
    problems.push({
      name: 'upstream_verified_date',
      message: 'Record the date the upstream rates were checked, or clear the verification.'
    })
  }

  const reason = state.reason.trim()

  if (reason.length < 10) {
    problems.push({
      name: 'reason',
      message: 'Record why, in at least 10 characters. This is written to the audit trail with the before and after rates.'
    })
  } else if (reason.length > 2000) {
    problems.push({ name: 'reason', message: 'Keep the note to 2000 characters or fewer.' })
  }

  return problems.filter((problem): problem is AliasPricingProblem => problem !== null)
}

/**
 * Server field name to form field name.
 *
 * The write contract is flat (`upstream_input_per_million_minor`) while the form is
 * grouped (`upstream.input`), so a 422 has to be translated or it lands on no field
 * and the operator sees a rejected save with nothing marked. Anything unrecognised is
 * returned unchanged, which surfaces it on the form-level banner rather than losing it.
 */
export function aliasPricingFieldName(serverKey: string): string {
  const upstream = /^upstream_(.+)_per_million_minor$/.exec(serverKey)

  if (upstream) {
    return `upstream.${upstream[1]}`
  }

  const sell = /^(.+)_per_million_minor$/.exec(serverKey)

  if (sell) {
    return `sell.${sell[1]}`
  }

  return serverKey === 'upstream_cost_verified_at' ? 'upstream_verified_date' : serverKey
}

/** An exact non-negative integer, or null for a blank or invalid entry. */
const toBigInt = (value: string): bigint | null => {
  const trimmed = value.trim()

  return /^\d+$/.test(trimmed) ? BigInt(trimmed) : null
}

export interface RateComparison {
  key: AliasRateKey
  label: string
  sell: string | null
  upstream: string | null
  /** True when both rates are known and the sell rate is at or below cost. */
  belowCost: boolean
  /** Exact sell minus upstream, or null when either side is unknown. */
  marginMinor: string | null
}

/**
 * Sell against cost, class by class, in exact integers.
 *
 * Both sides are columns of one pricing record and therefore share a currency and
 * scale, so this subtraction is meaningful — unlike a comparison across two aliases.
 * An unknown side yields null rather than zero: no cost recorded is not free.
 */
export function rateComparisons(state: AliasPricingFormState): RateComparison[] {
  return ALIAS_RATE_FIELDS.map((field) => {
    const sell = toBigInt(state.sell[field.key])
    const upstream = toBigInt(state.upstream[field.key])
    const known = sell !== null && upstream !== null

    return {
      key: field.key,
      label: field.label,
      sell: sell === null ? null : sell.toString(),
      upstream: upstream === null ? null : upstream.toString(),
      belowCost: known && sell <= upstream,
      marginMinor: known ? (sell - upstream).toString() : null
    }
  })
}

/**
 * Honest notes about a pricing record that the control plane will accept anyway.
 *
 * Every one of these is a real commercial consequence rather than a style preference,
 * and none blocks a save: the operator may have a reason, and the API would accept it
 * from any other client regardless of what this page thinks.
 */
export function aliasPricingAdvisories(state: AliasPricingFormState): string[] {
  const notes: string[] = []
  const comparisons = rateComparisons(state)

  const costed = PROFITABILITY_RATE_KEYS.some(key => toBigInt(state.upstream[key]) !== null)

  if (!state.upstream_verified) {
    notes.push(
      'Upstream cost is not verified, so every package allowing this model has no calculable margin. '
      + 'Publishing one will need a written override.'
    )
  } else if (!costed) {
    notes.push(
      'The verification is recorded but no upstream input, output or cache rate is, so margin still '
      + 'cannot be calculated for packages allowing this model.'
    )
  }

  const belowCost = comparisons.filter(comparison => comparison.belowCost)

  if (belowCost.length > 0) {
    notes.push(
      `${belowCost.map(comparison => comparison.label.toLowerCase()).join(', ')} `
      + `${belowCost.length === 1 ? 'is priced' : 'are priced'} at or below what SP Cambo pays upstream. `
      + 'Every request using that token class loses money.'
    )
  }

  return notes
}

/**
 * Aliases customers can reach whose upstream cost is unverified.
 *
 * Both flags matter: an alias that is disabled or hidden is not being sold, so its
 * unknown cost is not currently costing anything.
 */
export function aliasesNeedingCostVerification(aliases: AdminModelAlias[]): AdminModelAlias[] {
  return aliases.filter(alias =>
    alias.enabled
    && alias.customer_visible
    && (alias.upstream_cost === null || alias.upstream_cost.verified_at === null))
}
