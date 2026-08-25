/**
 * SP Cambo commercial contracts: catalogue, entitlements, orders, payments, usage,
 * API keys and reseller provisioning.
 *
 * These are implemented by the Laravel control plane. `docs/architecture/API_CONTRACT.md`
 * is authoritative for the wire shapes, and the controllers under
 * `backend/app/Http/Controllers/Api/V1/` are authoritative over that document — verify
 * against the source before changing a field here.
 *
 * A field typed optional is one the control plane does not publish today. Absent must
 * always read as "not stated", never as a value: pages render an honest "not available"
 * state rather than substituting a default, because a guessed price, quota or capability
 * is worse than a blank. Outstanding gaps are itemised in `docs/ai/CLAUDE_TO_CODEX.md`.
 *
 * Numeric policy (mirrors docs/product/BILLING_AND_ENTITLEMENTS.md):
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
  price: MoneyAmount
  /** Optional pre-discount price for campaign display. */
  compare_at_price: MoneyAmount | null
  /** Exact lifetime in seconds. 86400 means exactly 24 hours from activation. */
  duration_seconds: number
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

export interface PlaygroundQuota {
  enabled: boolean
  limit: number
  remaining: number
  reset_at: string
  max_output_tokens: number
  free_model_aliases: string[]
  redeem_token_remaining: number
  paid_token_remaining: number
  paid_credit_remaining: number
  fallback_available: boolean
  default_model_alias: string | null
  allow_model_switching: boolean
}

export interface BalanceSummary {
  token_quota: {
    /** Spendable metered units across all non-expired TOKEN_QUOTA lots. */
    remaining_units: string
    reserved_units: string
    original_units: string
  }
  credit_balance: {
    remaining: MoneyAmount
    reserved: MoneyAmount
  }
  /** Earliest expiry across spendable lots, ISO-8601 UTC. */
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
}

export type ApiKeyStatus = 'ACTIVE' | 'DISABLED' | 'REVOKED' | 'EXPIRED'

/** `GET /me/api-keys` — never contains a full secret. */
export interface ApiKeySummary {
  id: string
  label: string
  /** Safe display prefix, e.g. `sk-spc-`. */
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
}

/**
 * `POST /me/api-keys` — the only response that may ever contain `secret`.
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
  api_key_label: string
  api_key_prefix: string
  state: RequestState
  endpoint: string
  started_at: string
  duration_ms: number | null
  /**
   * Provider-reported request metadata. A null category is unsettled or unreported;
   * zero is a recorded zero. The server-recorded total must never be replaced with a
   * browser-calculated sum, including for historical rows that predate its capture.
   */
  input_tokens: number | null
  output_tokens: number | null
  cache_read_tokens: number | null
  cache_write_tokens: number | null
  reasoning_tokens: number | null
  total_tokens: number | null
  metered_units: string | null
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
  metered_units: string
  credit_charge: MoneyAmount
  buckets: Array<{
    at: string
    requests: number
    input_tokens: number
    output_tokens: number
    metered_units: string
  }>
  by_model: Array<{
    public_model: string
    requests: number
    metered_units: string
    credit_charge: MoneyAmount
  }>
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
