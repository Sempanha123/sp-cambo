import type { ApiErrorBody, SpErrorCode } from '~/types/api'

/**
 * Normalised control-plane error.
 *
 * Presentation keys off `code`, never off the human-readable message, so the
 * backend can reword copy without breaking the UI.
 */
export class SpApiError extends Error {
  readonly code: SpErrorCode
  readonly status: number
  readonly errors: Record<string, string[]>
  readonly retryable: boolean

  constructor(options: {
    code: SpErrorCode
    status: number
    message: string
    errors?: Record<string, string[]>
    retryable?: boolean
  }) {
    super(options.message)
    this.name = 'SpApiError'
    this.code = options.code
    this.status = options.status
    this.errors = options.errors ?? {}
    this.retryable = options.retryable ?? false
  }

  /** The control plane has not shipped this endpoint yet, or it is unreachable. */
  get isUnavailable(): boolean {
    return this.code === 'endpoint_unavailable' || this.code === 'network_unreachable'
  }

  get isSessionExpired(): boolean {
    return this.code === 'session_expired' || this.code === 'unauthenticated'
  }

  get isValidation(): boolean {
    return this.code === 'validation_failed'
  }

  /**
   * The request clashed with state the server already holds.
   *
   * Distinct from a fault: nothing is broken and retrying the same request
   * unchanged will clash again, so the caller has to change something or accept
   * the state that is already there.
   */
  get isConflict(): boolean {
    return this.code === 'conflict'
      || this.code === 'idempotency_conflict'
      || this.code === 'invalid_status_transition'
      || this.code === 'profitability_review_required'
  }

  /** First validation message for a field, for inline form errors. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

const DEFAULT_MESSAGES: Record<SpErrorCode, string> = {
  validation_failed: 'Please check the highlighted fields and try again.',
  csrf_token_mismatch: 'Your security session expired. Refresh the page and try again.',
  unauthenticated: 'Please sign in to continue.',
  session_expired: 'Your session has expired. Please sign in again.',
  forbidden: 'You do not have permission to perform this action.',
  account_suspended: 'This account is suspended. Contact SP Cambo support.',
  not_found: 'That resource could not be found.',
  rate_limit_exceeded: 'Too many requests in a short time. Your daily token balance is not necessarily exhausted; wait a moment and try again.',
  playground_quota_exhausted: 'Daily free token limit reached. Wait for the daily reset, or use Tokens or Credits to continue.',
  playground_balance_exhausted: 'No Tokens or Credits are available for this model. Add a package or wait for free access to reset.',
  conflict: 'That request conflicts with the current state of this record.',
  idempotency_conflict: 'This safety key has already been used for a different request.',
  already_claimed: 'This purchased access has already been attached to an API key.',
  invalid_status_transition: 'That customer status has already changed and this transition is no longer available.',
  profitability_review_required: 'This needs a profitability review before it can be published.',
  payment_pending: 'This payment has not been confirmed yet.',
  payment_verification_failed: 'The payment could not be verified.',
  provider_probe_failed: 'The provider connection could not be verified.',
  insufficient_tokens: 'This entitlement has no remaining token quota.',
  insufficient_credits: 'This account has no remaining credit balance.',
  database_migration_required: 'SP Cambo needs the latest database update. Run php artisan migrate from the backend, then reload this page.',
  inference_unavailable: 'The selected model route is temporarily unavailable. Check the Gateway/provider status and try again.',
  playground_run_failed: 'The Playground request failed. Check the diagnostic reference in the error and backend log.',
  playground_history_unavailable: 'Chat history storage is temporarily unavailable. Run the Playground history check and restart SP Cambo.',
  server_error: 'SP Cambo could not complete that request. Please try again.',
  network_unreachable: 'SP Cambo could not be reached. Check your connection and try again.',
  endpoint_unavailable: 'This part of the SP Cambo API is not available yet.',
  unknown_error: 'Something went wrong. Please try again.'
}

/** Backend-supplied machine codes the frontend recognises verbatim. */
const KNOWN_CODES = new Set<string>([
  'validation_failed',
  'csrf_token_mismatch',
  'unauthenticated',
  'session_expired',
  'forbidden',
  'account_suspended',
  'not_found',
  'rate_limit_exceeded',
  'playground_quota_exhausted',
  'playground_balance_exhausted',
  'idempotency_conflict',
  'already_claimed',
  'invalid_status_transition',
  'profitability_review_required',
  'payment_pending',
  'payment_verification_failed',
  'provider_probe_failed',
  'insufficient_tokens',
  'insufficient_credits',
  'database_migration_required',
  'inference_unavailable',
  'playground_run_failed',
  'playground_history_unavailable',
  'server_error'
])

/**
 * Backend spellings that mean the same thing as a canonical code.
 *
 * The API may return `token_quota_exhausted`
 * for an exhausted token entitlement, so accept it rather than degrading a
 * perfectly clear 402 to `unknown_error` over a naming difference.
 */
const CODE_ALIASES: Record<string, SpErrorCode> = {
  // ProviderConnectionRevisionController uses this more specific spelling.
  // Treat it as the canonical probe failure so the Admin UI can preserve the
  // safe per-model/status explanation instead of degrading every 502 to the
  // generic server-error message.
  provider_connection_probe_failed: 'provider_probe_failed',
  token_quota_exhausted: 'insufficient_tokens',
  credit_balance_exhausted: 'insufficient_credits',
  unauthorized: 'unauthenticated',
  too_many_requests: 'rate_limit_exceeded',
  playground_rate_limited: 'rate_limit_exceeded',
  upstream_unavailable: 'inference_unavailable',
  upstream_rejected: 'inference_unavailable',
  model_unavailable: 'inference_unavailable',
  model_not_allowed: 'forbidden',
  billing_settlement_pending: 'inference_unavailable',
  playground_unavailable: 'inference_unavailable',
  playground_stream_interrupted: 'inference_unavailable',
  billing_unavailable: 'inference_unavailable',
  invalid_api_key: 'inference_unavailable',
  api_key_disabled: 'inference_unavailable',
  api_key_revoked: 'inference_unavailable',
  api_key_expired: 'inference_unavailable'
}

function codeForStatus(status: number, notFoundMeansUnavailable: boolean): SpErrorCode {
  if (status === 0) {
    return 'network_unreachable'
  }

  switch (status) {
    case 401:
      return 'session_expired'
    case 402:
      return 'payment_pending'
    case 403:
      return 'forbidden'
    case 404:
      return notFoundMeansUnavailable ? 'endpoint_unavailable' : 'not_found'
    case 419:
      return 'session_expired'
    case 422:
      return 'validation_failed'
    case 429:
      return 'rate_limit_exceeded'
    /*
     * A 409 says the request clashed with state the server already holds, which
     * is an answer rather than a fault. Without this arm it fell through to
     * `unknown_error`, and `unknown_error` deliberately replaces the backend
     * message with generic copy — so the one thing that explains the clash was
     * being discarded. The control plane already returns `idempotency_conflict`
     * and `profitability_review_required` at this status.
     */
    case 409:
      return 'conflict'
    case 501:
      return 'endpoint_unavailable'
    default:
      break
  }

  if (status >= 500) {
    return 'server_error'
  }

  return 'unknown_error'
}

interface RawFetchError {
  status?: number
  statusCode?: number
  data?: ApiErrorBody
  message?: string
}

/**
 * Converts any thrown `$fetch` failure into an `SpApiError`.
 *
 * `notFoundMeansUnavailable` should be true for collection endpoints, where a
 * 404 means the route does not exist rather than "this record is missing".
 */
export function toSpApiError(error: unknown, notFoundMeansUnavailable = false): SpApiError {
  if (error instanceof SpApiError) {
    return error
  }

  const raw = error as RawFetchError
  const status = raw.status ?? raw.statusCode ?? 0
  const body = raw.data

  const backendCode = body?.code
  const aliasedCode = backendCode ? CODE_ALIASES[backendCode] : undefined
  const code: SpErrorCode = aliasedCode
    ?? (backendCode && KNOWN_CODES.has(backendCode)
      ? backendCode as SpErrorCode
      : codeForStatus(status, notFoundMeansUnavailable))

  // A backend validation payload is authoritative even when the status differs.
  const errors = body?.errors
  const resolvedCode = errors && Object.keys(errors).length > 0 ? 'validation_failed' : code

  const firstValidationMessage = resolvedCode === 'validation_failed'
    ? Object.values(errors ?? {}).flat().find(message => typeof message === 'string' && message.trim().length > 0)
    : undefined

  // Never surface a raw framework/stack message for server faults. For validation,
  // prefer the concrete field reason over Laravel's generic 'given data was invalid'.
  const safeMessage = resolvedCode === 'server_error' || resolvedCode === 'unknown_error'
    ? DEFAULT_MESSAGES[resolvedCode]
    : firstValidationMessage?.trim() || body?.message?.trim() || DEFAULT_MESSAGES[resolvedCode]

  return new SpApiError({
    code: resolvedCode,
    status,
    message: safeMessage,
    errors,
    retryable: resolvedCode === 'network_unreachable' || resolvedCode === 'server_error'
  })
}

/** Default user-facing copy for a code, used by shared error surfaces. */
export function messageForCode(code: SpErrorCode): string {
  return DEFAULT_MESSAGES[code]
}
