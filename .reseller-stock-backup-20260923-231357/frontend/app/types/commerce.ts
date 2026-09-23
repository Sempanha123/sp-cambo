/**
 * SP Cambo commercial contracts: catalogue, entitlements, orders, payments, usage,
 * API keys and reseller provisioning.
 *
 * These are implemented by the Laravel control plane. The controllers under
 * `backend/app/Http/Controllers/Api/V1/` are authoritative for the wire shapes —
 * verify against the source before changing a field here.
 *
 * A field typed optional is one the control plane does not publish today. Absent must
 * always read as "not stated", never as a value: pages render an honest "not available"
 * state rather than substituting a default, because a guessed price, quota or capability
 * is worse than a blank.
 *
 * Numeric policy:
 * - money is transported as integer minor units in a decimal string;
 * - token/credit quantities are transported as integer decimal strings;
 * - the frontend never performs binary-float arithmetic on money or quota.
 */

/** Integer minor units, e.g. `{ minor: '1050', currency: 'USD', exponent: 2 }`. */
export interface MoneyAmount {
  minor: string
  currency: string
  exponent: number
}

export type BillingMode = 'TOKEN_QUOTA' | 'CREDIT_BALANCE'

export type ModelFamily = string

export interface PublicModelCapabilities {
  streaming: boolean
  tools: boolean
  vision: boolean
  reasoning: boolean
  context_tokens: number | null
  max_output_tokens: number | null
  /**
   * Which inference surfaces accept this alias.
   *
   * The control plane gates each gateway protocol on the matching flag here, so an
   * alias that does not state one is refused on that protocol with
   * `model_unavailable`. Optional because the catalogue may not publish every flag:
   * absence must read as "not stated" and say nothing either way.
   */
  messages_api?: boolean
  responses_api?: boolean
  chat_completions_api?: boolean
  /** Preferred protocol for SP Cambo's hosted Playground when several are verified. */
  playground_protocol?: 'messages' | 'responses' | 'chat_completions' | null
}

/** `GET /catalog/models` — public, no auth. Internal OmniRoute ids must never appear. */
export interface PublicModel {
  /** Stable customer-facing alias, e.g. the value used for ANTHROPIC_MODEL. */
  public_alias: string
  display_name: string
  /** Admin-defined marketing family label. Never a provider account identifier. */
  family: ModelFamily
  family_label: string
  description: string | null
  capabilities: PublicModelCapabilities
  /** Present only for models sold on credit pricing. */
  credit_pricing: {
    input_per_million: MoneyAmount
    output_per_million: MoneyAmount
    cache_read_per_million: MoneyAmount | null
    cache_write_per_million: MoneyAmount | null
    /**
     * Reasoning tokens are billed separately when the model reports them. A null
     * here does not mean they are free: the control plane falls back to the output
     * rate, so the UI must say that rather than omit the category.
     */
    reasoning_per_million: MoneyAmount | null
  } | null
  /** Admin-configured default limits shown to customers. */
  limits: {
    requests_per_minute: number | null
    tokens_per_minute: number | null
    concurrency: number | null
    /** Customer-facing unit name used by SP Cambo local request/response settlement. */
    billing_unit_label?: string
    /** 10,000 bps = 1.00x. R43 new input/output settles at 1.00x; locally reused input uses the published 0.25x smart-reuse rate. */
    billing_multipliers_bps?: Partial<Record<'input' | 'output' | 'cache_read' | 'cache_write' | 'reasoning', number>>
    billing_usage_classes?: Array<'input' | 'output' | 'cache_read' | 'cache_write' | 'reasoning'>
  }
  status: 'available' | 'degraded' | 'unavailable'
}

/** `GET /catalog/packages` — public, no auth. */
export interface PublicPackage {
  id: string
  slug: string
  name: string
  subtitle: string | null
  badge: string | null
  billing_mode: BillingMode
  family: ModelFamily
  family_label: string
  /** Marketing quantity, e.g. "20000000" advertised as 20M. */
  advertised_units: string
  /** Human unit label defined by admin, e.g. "tokens" or "credits". */
  unit_label: string
  /** Optional marketing quantity. It never changes the entitlement or billing math. */
  display_units?: string | null
  /** Optional marketing unit label, e.g. Credits. */
  display_unit_label?: string | null
  /** Customer-facing package class. Settlement mode can still be TOKEN_QUOTA for quota-backed Credits. */
  package_kind?: 'SP_TOKENS' | 'SP_CREDITS' | 'WALLET_CREDIT'
  /** Exact funded money for CREDIT_BALANCE packages; null for token quota packages. */
  credit_amount: MoneyAmount | null
  price: MoneyAmount
  /** Optional pre-discount price for campaign display. */
  compare_at_price: MoneyAmount | null
  /** Exact lifetime in seconds. 86400 means exactly 24 hours from activation. */
  duration_seconds: number
  /** null = unlimited stock; otherwise exact remaining purchasable package units. */
  stock_remaining: string | null
  allowed_model_aliases: string[]
  limits: {
    requests_per_minute: number | null
    tokens_per_minute: number | null
    concurrency: number | null
    max_request_bytes: number | null
    max_output_tokens: number | null
  }
  auto_creates_api_key: boolean
  featured: boolean
  sort_order: number
}

/** `GET /me/balance` — aggregate of spendable entitlement lots. */

export interface PlaygroundModel {
  public_alias: string
  display_name: string
  capabilities: PublicModelCapabilities
  limits: Record<string, number | null>
}

export interface PlaygroundChatSummary {
  id: number
  client_key: string | null
  title: string
  model_alias: string | null
  message_count: number
  last_message_at: string | null
  expires_at: string | null
}

export interface PlaygroundChat extends PlaygroundChatSummary {
  system_prompt: string | null
  messages: Array<{ role: 'user' | 'assistant', content: string }>
  created_at: string | null
  updated_at: string | null
}

export interface PlaygroundQuota {
  enabled: boolean
  limit: number
  remaining: number
  reset_at: string
  max_output_tokens: number
  free_model_aliases: string[]
  /** Daily quota can remain available while the configured free route is temporarily unavailable. */
  free_models_available: boolean
  free_model_message: string | null
  redeem_token_remaining: number
  paid_token_remaining: number
  paid_credit_remaining: number
  fallback_available: boolean
  fallback_model_aliases: string[]
  available_model_aliases: string[]
  available_models: PlaygroundModel[]
  /** Complete published customer-facing catalogue. Locked rows remain visible in the picker. */
  catalog_models?: Array<PlaygroundModel & { available: boolean, lock_reason: string | null }>
  funded_model_statuses?: Array<{
    public_alias: string
    display_name: string
    available: boolean
    reason: string | null
    token_remaining: number
    credit_remaining: number
  }>
  unavailable_funded_models?: Array<{
    public_alias: string
    display_name: string
    available: boolean
    reason: string | null
    token_remaining: number
    credit_remaining: number
  }>
  model_balances: Array<{
    alias: string
    free_eligible: boolean
    balance_available: boolean
    token_remaining: number
    credit_remaining: number
    next_expires_at: string | null
  }>
  default_model_alias: string | null
  allow_model_switching: boolean
}

export interface BalanceSummary {
  token_quota: {
    /** Spendable metered units across allocated/legacy non-expired TOKEN_QUOTA lots. Unassigned purchases are excluded until the customer chooses access. */
    remaining_units: string
    reserved_units: string
    original_units: string
  }
  credit_balance: {
    remaining: MoneyAmount
    reserved: MoneyAmount
  }
  /** SP Credit quota is quota-backed, not cash. 1 SP Credit = the published local-unit size. */
  sp_credit_quota?: {
    remaining: string
    reserved: string
    original: string
    billable_units_per_credit: string
  }
  /** Earliest expiry across allocated/legacy spendable lots, ISO-8601 UTC. */
  next_expires_at: string | null
  active_lot_count: number
  /** Monotonic version so realtime events can be reconciled against REST. */
  version: number
}

export type EntitlementLotStatus = 'ACTIVE' | 'DEPLETED' | 'EXPIRED' | 'REVOKED' | 'PENDING'

/** `GET /me/entitlements` */
export interface EntitlementLot {
  id: string
  billing_mode: BillingMode
  package_name: string
  family_label: string
  original_units: string
  remaining_units: string
  reserved_units: string
  unit_label: string
  /** Present for CREDIT_BALANCE lots. */
  remaining_amount: MoneyAmount | null
  activated_at: string | null
  expires_at: string | null
  allowed_model_aliases: string[]
  status: EntitlementLotStatus
  /**
   * Where the lot came from, verbatim from `entitlement_lots.source_type`.
   *
   * Deliberately a plain string: the column is unconstrained, and the control
   * plane writes `ORDER` for a purchase and `RESELLER_TRANSFER` for a reseller
   * allocation today, with more expected. `lotSourceLabel` names the values it
   * recognises and reads anything else as a neutral "Granted" rather than
   * printing a raw enum name at a customer.
   */
  source: string
  access_scope: 'ACCOUNT' | 'PLAYGROUND' | 'API_KEY' | 'UNASSIGNED'
  fulfillment_claim_id: string | null
  bound_api_key: { id: string, label: string, masked_key: string } | null
}

export type ApiKeyStatus = 'ACTIVE' | 'DISABLED' | 'REVOKED' | 'EXPIRED'

/** `GET /me/api-keys` — never contains a full secret; re-copy requires an explicit owner-only reveal request. */
export interface ApiKeySummary {
  id: string
  label: string
  /** Safe display prefix, e.g. `sk-`. */
  prefix: string
  last_four: string
  status: ApiKeyStatus
  created_at: string
  last_used_at: string | null
  expires_at: string | null
  allowed_model_aliases: string[]
  limits: {
    requests_per_minute: number | null
    tokens_per_minute: number | null
    concurrency: number | null
    max_request_bytes: number | null
    max_output_tokens: number | null
  }
  bound_entitlement_id: string | null
  secret_recopy_available: boolean
}

export interface ApiKeyDetails {
  key: ApiKeySummary
  balance_source: 'loading' | 'no_spendable_balance' | 'legacy_account_entitlements' | 'dedicated_and_legacy_entitlements'
  token_quota_remaining: string | null
  credit_balances: MoneyAmount[]
  funding_status?: 'deferred' | 'ready' | 'unavailable'
  funding_message?: string | null
  funding_diagnostic_id?: string | null
  funding: Array<{
    id: string
    package_name: string
    source: string
    access_scope: 'ACCOUNT' | 'PLAYGROUND' | 'API_KEY' | 'UNASSIGNED'
    dedicated_to_this_key: boolean
    billing_mode: BillingMode
    original_units: string
    remaining_units: string
    reserved_units: string
    unit_label: string
    currency: string | null
    currency_exponent: number | null
    allowed_model_aliases: string[]
    activated_at: string | null
    expires_at: string | null
    days_remaining: number | null
  }>
  server_time: string
}

/**
 * Explicit secret-bearing response used only by create, rotate, or owner re-copy.
 * The frontend must not persist it anywhere after the reveal modal closes.
 */
export interface ApiKeyCreated {
  key: ApiKeySummary
  secret: string
}

/** `GET /me/api-keys/{id}/status` — non-billable validation. */
export interface ApiKeyStatusReport {
  valid: boolean
  status: ApiKeyStatus
  expires_at: string | null
  allowed_model_aliases: string[]
  token_quota_remaining: string | null
  credit_remaining: MoneyAmount | null
  credit_balances?: MoneyAmount[]
  limits: ApiKeySummary['limits']
  service_status: 'operational' | 'degraded' | 'unavailable'
}

export type RequestState
  = | 'received'
    | 'reserved'
    | 'connecting'
    | 'streaming'
    | 'reconciling'
    | 'settled'
    | 'failed'
    | 'released'

/** `GET /me/activity` — metadata only, never prompt or response content. */
export interface RequestActivity {
  id: string
  public_model: string
  api_key_id: string | null
  api_key_label: string
  api_key_prefix: string
  state: RequestState
  endpoint: string
  started_at: string
  finished_at: string | null
  duration_ms: number | null
  /**
   * SP Cambo local tokenizer-like estimates. These are intentionally provider-
   * independent and are not presented as exact OpenAI/Anthropic/Google tokenizer
   * counts. A null category means the request has not settled yet.
   */
  input_tokens: number | null
  output_tokens: number | null
  cache_read_tokens: number | null
  /** Tokens actually saved by SP Cambo smart reuse for this settled request. */
  saved_tokens: string | null
  /** Actual Token-quota units charged for this request; null when unsettled. */
  billed_tokens: string | null
  savings_rate_percent: number | null
  cache_write_tokens: number | null
  reasoning_tokens: number | null
  total_tokens: number | null
  reserved_units: string | null
  metered_units: string | null
  sp_credits_used: string | null
  credit_charge: MoneyAmount | null
  /** True while the numbers above are interim estimates. */
  estimated: boolean
  error_code: string | null
}

/** `GET /me/usage/summary` */
export interface UsageSummary {
  range: { from: string, to: string }
  requests: number
  input_tokens: number
  output_tokens: number
  cached_input_tokens: number
  saved_tokens: string
  billed_tokens: string
  savings_rate_percent: number
  credits_saved: string
  metered_units: string
  sp_credits_used: string
  credit_charge: MoneyAmount
  buckets: Array<{
    at: string
    requests: number
    input_tokens: number
    output_tokens: number
    cached_input_tokens: number
    saved_tokens: string
    billed_tokens: string
    savings_rate_percent: number
    metered_units: string
  }>
  by_model: Array<{
    public_model: string
    requests: number
    cached_input_tokens: number
    saved_tokens: string
    billed_tokens: string
    savings_rate_percent: number
    metered_units: string
    sp_credits_used: string
    credit_charge: MoneyAmount
  }>
}

export interface ApiKeyUsageSummary extends UsageSummary {
  key: {
    id: string
    label: string
    prefix: string
    last_four: string
    status: ApiKeyStatus
    created_at: string
    last_used_at: string | null
    expires_at: string | null
    allowed_model_aliases: string[]
    limits: ApiKeySummary['limits']
  }
}

export type OrderStatus
  = | 'PENDING_PAYMENT'
    | 'VERIFYING'
    | 'PAID'
    | 'FULFILLED'
    | 'FAILED'
    | 'EXPIRED'
    | 'CANCELLED'

/** `POST /orders` / `GET /orders/{id}` — server-calculated totals only. */
export interface Order {
  id: string
  reference: string
  status: OrderStatus
  created_at: string
  items: Array<{
    package_slug: string
    package_name: string
    quantity: number
    unit_price: MoneyAmount
    line_total: MoneyAmount
    /** Package includes post-payment API-key activation. */
    api_key_activation_included?: boolean
    /** Present after fulfillment when activation was included. */
    fulfillment_claim_id?: string | null
  }>
  subtotal: MoneyAmount
  discount_total: MoneyAmount
  total: MoneyAmount
  applied_promotion: { code: string, label: string } | null
  fulfilled_at: string | null
}

export type PaymentAttemptStatus
  = | 'PENDING'
    | 'VERIFYING'
    | 'PAID'
    | 'FAILED'
    | 'EXPIRED'

/** `POST /orders/{id}/payment` — Bakong KHQR attempt. */
export interface PaymentAttempt {
  id: string
  order_id: string
  status: PaymentAttemptStatus
  /** KHQR payload string for QR rendering. */
  qr_payload: string
  /** Optional server-rendered QR image (data URL or absolute URL). */
  qr_image_url: string | null
  amount: MoneyAmount
  merchant_display_name: string
  /** Server-authoritative expiry driving the countdown. */
  expires_at: string
  /** Server time at response, so the client can correct clock skew. */
  server_time: string
  last_checked_at: string | null
}

/** `POST /promotions/preview` — server calculates every discount. */
export interface PromotionPreview {
  code: string
  label: string
  valid: boolean
  reason: string | null
  subtotal: MoneyAmount
  discount_total: MoneyAmount
  total: MoneyAmount
  bonus_units: string | null
}

/** `GET /status` — public service status. */
export interface SystemStatus {
  updated_at: string
  overall: 'operational' | 'degraded' | 'maintenance' | 'outage'
  components: Array<{
    key: string
    label: string
    status: 'operational' | 'degraded' | 'maintenance' | 'outage'
    detail: string | null
  }>
}

/** `GET /me/external-identities` */
export interface ExternalIdentity {
  id: string
  provider: string
  provider_subject: string
  email: string | null
  name: string | null
  avatar_url: string | null
  created_at: string
}

export interface TelegramAccountStatus {
  linked: boolean
  username: string | null
  linked_at: string | null
}

export interface TelegramLinkToken {
  token: string
  expires_at: string
}
