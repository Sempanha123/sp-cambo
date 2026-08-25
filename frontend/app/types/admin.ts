/**
 * Admin analytics, system-health and catalogue-management contracts.
 *
 * Every shape here is implemented today. Two different permissions guard them,
 * and the distinction matters because an account can hold one without the other:
 *
 * - `admin.view` — `GET /admin/overview`, `GET /admin/system-health`
 * - `catalog.manage` — `GET|POST /admin/packages`, `PUT /admin/packages/{id}`,
 *   `GET /admin/packages/{id}/profitability`, `GET|POST /admin/promotions`,
 *   `PUT /admin/promotions/{id}`, `GET /admin/model-aliases`,
 *   `PUT /admin/model-aliases/{id}/pricing`
 *
 * A session without the relevant permission receives 403 `forbidden`, which the
 * admin pages render as an explicit "not your role" state rather than an error.
 *
 * Shapes below are transcribed from the controllers, which are authoritative:
 * `backend/app/Http/Controllers/Api/V1/Admin/`. Money and quota follow the same
 * exact-integer policy as `types/commerce.ts` — minor-unit strings, never floats.
 */

import type { BillingMode, MoneyAmount } from './commerce'

/**
 * Exact fulfilled revenue. A single currency includes its exponent; mixed
 * currencies are kept separate in `by_currency` and never added together.
 */
export interface AdminRevenue {
  minor: string
  currency: string | null
  mixed_currency: boolean
  /** Null when there is no single currency/scale to represent. */
  exponent: number | null
  /** Exact totals separated by currency and exponent; never sums unlike money. */
  by_currency: MoneyAmount[]
}

export interface AdminOverview {
  updated_at: string
  users: {
    total: number
    active: number
  }
  orders: {
    total: number
    by_status: Record<string, number>
  }
  payments: {
    total: number
    by_status: Record<string, number>
  }
  fulfilled_revenue: AdminRevenue
  entitlements: {
    active_lots: number
  }
  reservations: {
    active: number
  }
  ledger_entries: number
}

export type HealthState = 'operational' | 'degraded' | 'maintenance' | 'outage'

/**
 * One measured or explicitly unmeasured component.
 *
 * `maintenance` is used by the control plane to mean "not currently measured" —
 * gateway, inference routing and payment verification report it until real
 * probes exist. The UI must say that plainly instead of implying health.
 */
export interface AdminHealthComponent {
  key: string
  label: string
  status: HealthState
  detail: string | null
  /** Queue only: seconds the oldest queued job has been waiting. */
  lag_seconds?: number
  /** Scheduler only: last recorded heartbeat. */
  last_heartbeat_at?: string | null
}

export interface AdminSystemHealth {
  updated_at: string
  overall: HealthState
  components: AdminHealthComponent[]
}

/**
 * Per-request throughput ceilings stored on a package.
 *
 * The control plane validates each key with `sometimes`, so any subset may be
 * stored and any key may be absent. Absent means "no ceiling recorded", which is
 * not the same as unlimited — the UI says "not set" rather than inventing either.
 */
export interface AdminPackageLimits {
  requests_per_minute?: number
  tokens_per_minute?: number
  concurrency?: number
  max_request_bytes?: number
  max_output_tokens?: number
}

/**
 * How a package converts real token usage into metered units, in microunits per
 * token. Nullable as a whole: a package may carry no explicit weights.
 */
export interface AdminPackageBillingRules {
  input_weight_microunits?: number
  output_weight_microunits?: number
  cache_read_weight_microunits?: number
  cache_write_weight_microunits?: number
  reasoning_weight_microunits?: number
}

/**
 * `PackageProfitabilityService::analyze` — the margin check that gates publication.
 *
 * Read `reviewable` first: when false the analysis could not be performed at all,
 * because the package has no aliases or because some alias has no *verified*
 * upstream cost. In that case `profitable`, `worst_case_cost_minor`, `margin_minor`
 * and `margin_bps` are all null and must be shown as unknown, never as zero.
 *
 * `worst_case_cost_minor` is deliberately pessimistic: the backend takes the single
 * highest upstream per-million rate across every allowed alias and every token
 * class, then scales it by `advertised_units` rounding up. It is a ceiling on cost,
 * not a forecast.
 *
 * Money fields carry no currency of their own — they are in the owning package's
 * currency, so format them with that package's `price.currency` and `price.exponent`.
 */
export interface PackageProfitability {
  /** False when the margin could not be computed; every figure below is then null. */
  reviewable: boolean
  profitable: boolean | null
  price_minor: string
  worst_case_cost_minor: string | null
  margin_minor: string | null
  /** Basis points of price retained as margin. 2500 is 25%. */
  margin_bps: number | null
  minimum_margin_bps: number
  /** Public aliases with no verified upstream cost. Non-empty implies `reviewable: false`. */
  missing_cost_aliases: string[]
  /** True unless profitability is positively established; publication then needs a written reason. */
  override_required: boolean
}

/**
 * `GET /admin/packages` — the operator view of a package, including hidden and
 * disabled ones that never appear in `GET /catalog/packages`.
 *
 * Every field `POST /admin/packages` and `PUT /admin/packages/{id}` accept is
 * returned here, so an edit form populated from this response replaces a package
 * with exactly what it read plus the operator's changes. That round trip is what
 * makes editing safe, and it is asserted in `tests/component/AdminPackageForm.spec.ts`.
 *
 * `profitability` is analysis, not stored configuration: it is recomputed on every
 * read and is not part of a write.
 */
export interface AdminPackage {
  id: string
  slug: string
  name: string
  subtitle: string | null
  badge: string | null
  billing_mode: BillingMode
  family: string
  family_label: string
  /** Integer decimal string, e.g. `'20000000'`. */
  advertised_units: string
  unit_label: string
  price_minor: string
  compare_at_price_minor: string | null
  currency: string
  currency_exponent: number
  price: MoneyAmount
  compare_at_price: MoneyAmount | null
  duration_seconds: number
  limits: AdminPackageLimits | null
  billing_rules: AdminPackageBillingRules | null
  auto_creates_api_key: boolean
  featured: boolean
  sort_order: number
  starts_at: string | null
  ends_at: string | null
  allowed_model_alias_ids: number[]
  allowed_model_aliases: string[]
  enabled: boolean
  customer_visible: boolean
  minimum_margin_bps: number
  /** The written justification recorded when an unprofitable package was published. */
  profitability_override_reason: string | null
  profitability: PackageProfitability
}

/**
 * `POST /admin/packages` and `PUT /admin/packages/{id}` request body.
 *
 * Full replacement, not a patch: the control plane validates every field below as
 * required unless it is nullable, so an omitted key is rejected rather than left
 * alone. `AdminPackage` returns all of them, which is what lets an edit resubmit
 * untouched values instead of inventing them.
 *
 * Integers are sent as numbers, not minor-unit strings: these fields reach Laravel
 * `integer` validators. Every one is bounded well inside `Number.MAX_SAFE_INTEGER`
 * (`advertised_units` is a token count, `price_minor` a minor-unit amount), and
 * `parseOptionalInteger` refuses anything that would not survive the round trip.
 *
 * `limits` is `present` server-side: send `{}` rather than omitting it, or the write
 * fails validation. A limit left out of the object means "no ceiling recorded",
 * which is not the same as unlimited.
 */
export interface AdminPackageInput {
  slug: string
  name: string
  subtitle: string | null
  badge: string | null
  billing_mode: BillingMode
  family: string
  family_label: string
  advertised_units: number
  unit_label: string
  price_minor: number
  compare_at_price_minor: number | null
  currency: string
  currency_exponent: number
  duration_seconds: number
  /** Always present, possibly empty. Absent keys are absent ceilings. */
  limits: AdminPackageLimits
  billing_rules: AdminPackageBillingRules | null
  auto_creates_api_key: boolean
  featured: boolean
  sort_order: number
  starts_at: string | null
  ends_at: string | null
  enabled: boolean
  customer_visible: boolean
  minimum_margin_bps: number
  /**
   * 10–2000 characters. Required to publish a package whose margin is not
   * positively established; the control plane answers 409
   * `profitability_review_required` without it and rolls the whole write back.
   */
  profitability_override_reason: string | null
  /** Internal alias ids from `GET /admin/model-aliases`. */
  allowed_model_alias_ids: number[]
}

export type AdminPromotionType = 'PERCENTAGE' | 'FIXED' | 'BONUS' | 'PRICE_OVERRIDE' | 'FREE'

/**
 * Admin promotion read/write contract. Monetary rules are exact integer minor
 * units with an explicit currency and exponent, including price overrides.
 */
export interface AdminPromotion {
  id: string
  code: string
  label: string
  type: AdminPromotionType
  currency: string
  currency_exponent: number
  /** Basis points off. 1500 is 15%. Null unless `type` is `PERCENTAGE`. */
  percentage_bps: number | null
  fixed_discount_minor: string | null
  price_override_minor: string | null
  bonus_units: string | null
  minimum_order_minor: string
  maximum_discount_minor: string | null
  max_redemptions: number | null
  per_user_limit: number | null
  new_customer_only: boolean
  stackable: boolean
  /** Higher wins when several promotions could apply. */
  priority: number
  starts_at: string | null
  ends_at: string | null
  enabled: boolean
  package_ids: number[]
  package_slugs: string[]
}

/**
 * `POST|PUT /admin/promotions` request body.
 *
 * `reason` is mandatory and at least 10 characters: every promotion write is
 * recorded in the audit trail against the operator who made it.
 */
export interface AdminPromotionInput {
  code: string
  label: string
  type: AdminPromotionType
  currency: string
  currency_exponent: number
  percentage_bps?: number | null
  fixed_discount_minor?: number | null
  price_override_minor?: number | null
  bonus_units?: number | null
  minimum_order_minor: number
  maximum_discount_minor?: number | null
  max_redemptions?: number | null
  per_user_limit?: number | null
  new_customer_only: boolean
  stackable: boolean
  priority: number
  starts_at?: string | null
  ends_at?: string | null
  enabled: boolean
  /** Internal package ids. Empty array scopes the promotion to every package. */
  package_ids: number[]
  reason: string
}

/**
 * Optional per-million rates, in minor units of the owning record's `currency`.
 *
 * Shared by both sides of a pricing record. `sell` additionally always carries
 * input and output, so it extends this with those two required.
 */
export interface ModelAliasOptionalRates {
  cache_read_per_million_minor: string | null
  cache_write_per_million_minor: string | null
  reasoning_per_million_minor: string | null
}

/** What customers are charged. Input and output are mandatory on write. */
export interface ModelAliasSellRates extends ModelAliasOptionalRates {
  input_per_million_minor: string
  output_per_million_minor: string
}

/**
 * What SP Cambo pays upstream. Every rate is nullable, and an unverified record
 * counts as no cost at all for profitability purposes regardless of what is set.
 */
export interface ModelAliasUpstreamRates extends ModelAliasOptionalRates {
  input_per_million_minor: string | null
  output_per_million_minor: string | null
  verified_at: string | null
}

/**
 * `GET /admin/model-aliases` and `PUT /admin/model-aliases/{id}/pricing` response.
 *
 * `upstream_cost.verified_at` is what gates profitability analysis: an alias whose
 * upstream cost is unverified is treated as having no known cost at all, however
 * many rates are filled in.
 *
 * `sell` and `upstream_cost` are null together, and `currency`/`exponent` with them,
 * when no pricing record exists yet. That is a real state — an alias can be created
 * before it is priced — and must read as "not priced", never as zero.
 *
 * Provider identity, internal model ids and upstream routes are deliberately absent
 * from this contract and must not be inferred or displayed.
 */
export interface AdminModelAlias {
  /** Internal id. Also the `allowed_model_alias_ids` value a package write takes. */
  id: string
  public_alias: string
  display_name: string
  status: 'active' | 'beta' | 'deprecated'
  enabled: boolean
  customer_visible: boolean
  publication_ready?: boolean
  publication_blockers?: string[]
  /** Null when the alias has no pricing record. */
  currency: string | null
  exponent: number | null
  sell: ModelAliasSellRates | null
  upstream_cost: ModelAliasUpstreamRates | null
}

/**
 * `PUT /admin/model-aliases/{id}/pricing` request body — flat, not nested.
 *
 * `updateOrCreate` with a full attribute set: an omitted nullable rate is stored as
 * null, so this replaces a pricing record rather than patching it.
 *
 * `reason` is mandatory at 10–2000 characters. Every pricing write is recorded in the
 * audit trail against the operator who made it, with before/after values.
 */
export interface ModelAliasPricingInput {
  currency: string
  exponent: number
  input_per_million_minor: number
  output_per_million_minor: number
  cache_read_per_million_minor: number | null
  cache_write_per_million_minor: number | null
  reasoning_per_million_minor: number | null
  upstream_input_per_million_minor: number | null
  upstream_output_per_million_minor: number | null
  upstream_cache_read_per_million_minor: number | null
  upstream_cache_write_per_million_minor: number | null
  upstream_reasoning_per_million_minor: number | null
  /**
   * When the upstream rates above were last checked against the provider's own
   * published prices. Null means unverified, which excludes this alias from every
   * profitability calculation regardless of the rates recorded.
   */
  upstream_cost_verified_at: string | null
  reason: string
}

export interface ProviderConnectionRevision {
  id: string
  provider_id: string
  route_version: number
  origin: string
  connection_type: 'omniroute' | 'openai_compatible'
  credential_suffix: string | null
  credential_configured: boolean
  timeout_ms: number
  policy_version: number
  lifecycle_status: 'PENDING' | 'READY' | 'DRAINING' | 'REVOKED'
  last_probe_status: string | null
  last_probe_at: string | null
  resolve_until: string | null
  created_at: string
  updated_at: string
}

export interface ProviderConnectionRevisionInput {
  route_version: number
  origin: string
  connection_type: 'omniroute' | 'openai_compatible'
  credential: string
  timeout_ms: number
  policy_version?: number
  resolve_until?: string | null
}

/** Update an unused PENDING revision. Omit/blank credential to keep the stored secret. */
export interface ProviderConnectionRevisionUpdateInput {
  route_version: number
  origin: string
  connection_type: 'omniroute' | 'openai_compatible'
  credential?: string
  timeout_ms: number
  policy_version?: number
  resolve_until?: string | null
}

export type ProviderConnectionStatusTransition = 'DRAINING' | 'REVOKED'

export interface ProviderConnectionStatusUpdateInput {
  lifecycle_status: ProviderConnectionStatusTransition
  reason: string
}

export type ProviderConnectionProbeResult = ProviderConnectionRevision & {
  probe_success: boolean
  probe_message: string
  probe_endpoint_kind?: 'health' | 'models' | null
  auto_activated?: boolean
  active_connection_revision_id?: string | null
}

export interface ProviderActiveConnectionUpdateInput {
  revision_id: string
}

export interface AdminProviderModelCapabilities {
  streaming: boolean
  tools: boolean
  vision: boolean
  reasoning: boolean
  context_tokens: number
  max_output_tokens: number
}

export interface AdminProviderModelLimits {
  requests_per_minute: number | null
  tokens_per_minute: number | null
  concurrency: number | null
}

export interface AdminProviderModel {
  id: string
  provider_id: string
  internal_model_id: string
  display_name: string
  commercial_resale_verified: boolean
  commercial_resale_verified_at: string | null
  alias_count: number
  capabilities: AdminProviderModelCapabilities
  limits: AdminProviderModelLimits
  created_at: string
  updated_at: string
}

export interface ProviderModelInput {
  internal_model_id: string
  display_name: string
  commercial_resale_verified: boolean
  capabilities: AdminProviderModelCapabilities
  limits: AdminProviderModelLimits
}

export interface DiscoveredProviderModel {
  internal_model_id: string
  display_name: string
  registered_model_id: string | null
  already_registered: boolean
}

export interface ProviderModelImportResult {
  created: string[]
  already_registered: string[]
  models: AdminProviderModel[]
}

export interface AdminProviderAliasCapabilities {
  streaming: boolean
  tools: boolean
  vision: boolean
  reasoning: boolean
  messages_api: boolean
  responses_api: boolean
  chat_completions_api: boolean
  context_tokens: number
  max_output_tokens: number
}

export interface AdminProviderAliasLimits {
  requests_per_minute: number | null
  tokens_per_minute: number | null
  concurrency: number | null
}

export interface AdminProviderAlias {
  id: string
  provider_id: string
  public_alias: string
  display_name: string
  capabilities: AdminProviderAliasCapabilities
  limits: AdminProviderAliasLimits
  enabled: boolean
  customer_visible: boolean
  mapped_model_id: string | null
  publication_ready: boolean
  publication_blockers: string[]
  created_at: string
  updated_at: string
}

export interface ProviderAliasInput {
  model_id: string
  public_alias: string
  display_name: string
  capabilities: AdminProviderAliasCapabilities
  limits: AdminProviderAliasLimits
  enabled: boolean
  customer_visible: boolean
}

export interface AdminProvider {
  id: string
  name: string
  slug: string
  enabled: boolean
  active_connection_revision_id: string | null
  created_at: string
  updated_at: string
}

export interface AdminPlaygroundSettings {
  enabled: boolean
  daily_token_quota: number
  max_output_tokens: number
  allowed_model_aliases: string[]
  gateway_base_url: string | null
  default_model_alias: string | null
  allow_model_switching: boolean
}

export interface AdminRedeemCode {
  id: string
  masked_code: string
  code?: string
  label: string
  billing_mode: BillingMode
  units: string
  duration_seconds: number
  allowed_model_aliases: string[]
  max_redemptions: number | null
  per_user_limit: number
  redemptions: number
  starts_at: string | null
  ends_at: string | null
  enabled: boolean
  created_at: string | null
}

export interface AdminRedeemCodeInput {
  label: string
  billing_mode: BillingMode
  units: number
  duration_seconds: number
  allowed_model_alias_ids: number[]
  billing_rules?: Record<string, unknown> | null
  max_redemptions: number | null
  per_user_limit: number
  starts_at: string | null
  ends_at: string | null
  enabled: boolean
}

export interface AdminRedeemCodeUpdateInput {
  label: string
  max_redemptions: number | null
  per_user_limit: number
  starts_at: string | null
  ends_at: string | null
  enabled: boolean
}
