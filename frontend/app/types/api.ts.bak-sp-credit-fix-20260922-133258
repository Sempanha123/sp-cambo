import type { MoneyAmount } from '~/types/commerce'

/**
 * Core SP Cambo control-plane types: the envelope, the error contract, and the
 * session/identity shapes shared by every other module.
 *
 * Commercial contracts — catalogue, entitlements, orders, payments, usage, keys,
 * reseller — live in `types/commerce.ts` and `types/reseller.ts`.
 */

export interface ApiEnvelope<T> {
  data: T
}

export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
  /** Stable SP Cambo machine code when the backend supplies one. */
  code?: string
}

/**
 * Stable machine codes the frontend reacts to. Presentation must key off these
 * rather than parsing human-readable messages.
 *
 * `network_unreachable` and `endpoint_unavailable` are frontend-side
 * classifications, not backend codes.
 */
export type SpErrorCode
  = | 'validation_failed'
    | 'csrf_token_mismatch'
    | 'unauthenticated'
    | 'session_expired'
    | 'forbidden'
    | 'account_suspended'
    | 'not_found'
    | 'rate_limit_exceeded'
    | 'playground_quota_exhausted'
    | 'playground_balance_exhausted'
    | 'conflict'
    | 'idempotency_conflict'
    | 'already_claimed'
    | 'invalid_status_transition'
    | 'profitability_review_required'
    | 'payment_pending'
    | 'payment_unavailable'
    | 'payment_not_available'
    | 'payment_verification_failed'
    | 'payment_verification_unavailable'
    | 'payment_fulfillment_recovery_required'
    | 'payment_replayed'
    | 'provider_probe_failed'
    | 'insufficient_tokens'
    | 'insufficient_credits'
    | 'database_migration_required'
    | 'inference_unavailable'
    | 'playground_run_failed'
    | 'playground_history_unavailable'
    | 'server_error'
    | 'network_unreachable'
    | 'endpoint_unavailable'
    | 'unknown_error'

export interface AuthenticatedUser {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  created_at: string
  /**
   * Sorted role names and deduplicated effective permission names published by
   * the control plane for elevated-surface discovery. Backend middleware remains
   * the authority for every request.
   */
  roles: string[]
  permissions: string[]
}

export interface AuthResponse {
  user: AuthenticatedUser
  /** Present for bearer clients; null for first-party HttpOnly cookie sessions. */
  token: string | null
}

export interface RegisterInput {
  name: string
  email: string
  password: string
  password_confirmation: string
  verification_code: string
  referral_code?: string | null
}

export interface LoginInput {
  email: string
  password: string
}

/** Superset of both credential forms, so one component can render either. */
export interface AuthFormState {
  name: string
  email: string
  password: string
  password_confirmation: string
  verification_code: string
  referral_code?: string | null
}

export interface HealthResponse {
  status: string
}

/**
 * `GET /me/sessions` — one row per live Sanctum bearer token.
 *
 * The browser transport is bearer mode, so these are real personal-access-token
 * sessions rather than cookie sessions. The token value itself is never returned;
 * only the safe id, its label and its timestamps.
 */
export interface SessionSummary {
  id: string
  name: string
  /** The session this browser is currently using. It cannot be revoked from here. */
  current: boolean
  last_used_at: string | null
  created_at: string
}

export interface PasswordChangeInput {
  current_password: string
  password: string
  password_confirmation: string
}

export interface PasswordResetInput {
  token: string
  email: string
  password: string
  password_confirmation: string
}

/** Google OAuth callback parameters */
export interface GoogleCallbackInput {
  code: string
  state: string
  [key: string]: unknown
}

/** Google OAuth linking callback parameters */
export interface GoogleLinkCallbackInput {
  code: string
  state: string
  [key: string]: unknown
}

// Public API key checker response
export interface PublicApiKeyStatus {
  valid: boolean
  masked_key?: string
  status?: string
  package?: string | null
  funding_source?: 'none' | 'account' | 'dedicated_key' | 'mixed'
  funding_note?: string | null
  allowed_models?: string[]
  model_details?: Array<{
    public_alias: string
    display_name: string
    status: string
    context_tokens: number | null
    max_output_tokens: number | null
    capability_basis: string | null
    features: string[]
  }>
  limits?: {
    requests_per_minute: number | null
    tokens_per_minute: number | null
    concurrency: number | null
    max_request_bytes: number | null
    max_output_tokens: number | null
  }
  created_at?: string
  expires_at?: string | null
  quota_remaining?: string | null
  credit_remaining?: MoneyAmount | null
  credit_balances?: MoneyAmount[]
  tokens_used?: { input: string, output: string, total: string, cached_input?: string, saved?: string, billed?: string, savings_rate_percent?: number }
  total_spend?: MoneyAmount | null
  total_spend_by_currency?: MoneyAmount[]
  last_used?: string | null
  active_requests?: number
  server_time?: string
  recent_requests?: Array<{
    request_id: string
    time: string
    finished_at: string | null
    endpoint: string
    model: string
    state: 'reserved' | 'connecting' | 'streaming' | 'reconciling' | 'settled' | 'failed' | 'released' | string
    status: 'success' | 'error' | 'pending'
    duration_ms: number | null
    input_tokens: string | null
    cached_input_tokens: string | null
    saved_tokens?: string | null
    billed_tokens?: string | null
    savings_rate_percent?: number | null
    output_tokens: string | null
    total_tokens: string | null
    reserved_units: string | null
    charge: MoneyAmount | null
    error_code: string | null
  }>
  error?: string
}
