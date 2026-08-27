import { describe, expect, it } from 'vitest'
import { SpApiError, messageForCode, toSpApiError } from '~/utils/spApiError'

/** Shape of a thrown `$fetch` failure, as ofetch presents it. */
const fetchError = (status: number, data?: unknown) => ({ status, data })

describe('toSpApiError — backend code', () => {
  it('prefers the backend machine code over the HTTP status', () => {
    const error = toSpApiError(fetchError(402, { message: 'No quota left.', code: 'insufficient_tokens' }))

    expect(error.code).toBe('insufficient_tokens')
    expect(error.status).toBe(402)
  })

  it('ignores an unrecognised code and classifies by status instead', () => {
    const error = toSpApiError(fetchError(403, { message: 'Nope.', code: 'some_future_code' }))

    expect(error.code).toBe('forbidden')
  })

  it('preserves an invalid lifecycle transition so the caller can resynchronise', () => {
    const error = toSpApiError(fetchError(409, {
      message: 'This customer is already closed.',
      code: 'invalid_status_transition'
    }))

    expect(error.code).toBe('invalid_status_transition')
    expect(error.isConflict).toBe(true)
    expect(error.message).toBe('This customer is already closed.')
  })


  it('preserves the database migration-required code so stale local schemas are actionable', () => {
    const error = toSpApiError(fetchError(503, {
      message: 'SP Cambo needs the latest database update before this feature can be used.',
      code: 'database_migration_required'
    }))

    expect(error.code).toBe('database_migration_required')
    expect(error.message).toContain('database update')
    expect(error.message).not.toContain('SQLSTATE')
  })

  it('accepts the documented alias spellings rather than degrading a clear failure', () => {
    expect(toSpApiError(fetchError(402, { message: 'x', code: 'token_quota_exhausted' })).code)
      .toBe('insufficient_tokens')
    expect(toSpApiError(fetchError(402, { message: 'x', code: 'credit_balance_exhausted' })).code)
      .toBe('insufficient_credits')
    expect(toSpApiError(fetchError(401, { message: 'x', code: 'unauthorized' })).code)
      .toBe('unauthenticated')
    expect(toSpApiError(fetchError(429, { message: 'x', code: 'too_many_requests' })).code)
      .toBe('rate_limit_exceeded')
  })
})

describe('toSpApiError — status classification', () => {
  it('maps the statuses the control plane actually returns', () => {
    expect(toSpApiError(fetchError(401)).code).toBe('session_expired')
    expect(toSpApiError(fetchError(402)).code).toBe('payment_pending')
    expect(toSpApiError(fetchError(403)).code).toBe('forbidden')
    expect(toSpApiError(fetchError(419)).code).toBe('session_expired')
    expect(toSpApiError(fetchError(422)).code).toBe('validation_failed')
    expect(toSpApiError(fetchError(409)).code).toBe('conflict')
    expect(toSpApiError(fetchError(429)).code).toBe('rate_limit_exceeded')
    expect(toSpApiError(fetchError(501)).code).toBe('endpoint_unavailable')
    expect(toSpApiError(fetchError(503)).code).toBe('server_error')
  })

  it('treats a missing status as an unreachable network, which is retryable', () => {
    const error = toSpApiError(new Error('Failed to fetch'))

    expect(error.code).toBe('network_unreachable')
    expect(error.retryable).toBe(true)
    expect(error.isUnavailable).toBe(true)
  })

  it('reads a 404 as a missing record by default', () => {
    expect(toSpApiError(fetchError(404)).code).toBe('not_found')
  })

  it('reads a 404 on a collection route as an unshipped endpoint', () => {
    const error = toSpApiError(fetchError(404), true)

    expect(error.code).toBe('endpoint_unavailable')
    expect(error.isUnavailable).toBe(true)
  })

  it('accepts statusCode as well as status', () => {
    expect(toSpApiError({ statusCode: 422 }).code).toBe('validation_failed')
  })
})

describe('toSpApiError — validation payloads', () => {
  it('treats a field-error payload as validation whatever the status says', () => {
    const error = toSpApiError(fetchError(400, {
      message: 'The given data was invalid.',
      errors: { email: ['This email is already registered.'] }
    }))

    expect(error.code).toBe('validation_failed')
    expect(error.isValidation).toBe(true)
    expect(error.fieldError('email')).toBe('This email is already registered.')
  })

  it('has no field error for an untouched field', () => {
    const error = toSpApiError(fetchError(422, { message: 'x', errors: { email: ['Bad.'] } }))

    expect(error.fieldError('password')).toBeUndefined()
  })

  it('does not treat an empty errors object as validation', () => {
    expect(toSpApiError(fetchError(500, { message: 'x', errors: {} })).code).toBe('server_error')
  })
})

describe('toSpApiError — message safety', () => {
  it('never surfaces a framework message for a server fault', () => {
    const error = toSpApiError(fetchError(500, {
      message: 'SQLSTATE[HY000] [1045] Access denied for user \'sp\'@\'10.0.0.4\''
    }))

    expect(error.code).toBe('server_error')
    expect(error.message).toBe(messageForCode('server_error'))
    expect(error.message).not.toContain('SQLSTATE')
    expect(error.message).not.toContain('10.0.0.4')
  })

  it('never surfaces a raw message for an unclassifiable failure', () => {
    const error = toSpApiError(fetchError(418, { message: 'Internal handler blew up at /var/www/app.php:88' }))

    expect(error.code).toBe('unknown_error')
    expect(error.message).toBe(messageForCode('unknown_error'))
  })

  it('does show a customer-safe backend message when the code is a known one', () => {
    const error = toSpApiError(fetchError(402, {
      message: 'Your token package is spent.',
      code: 'insufficient_tokens'
    }))

    expect(error.message).toBe('Your token package is spent.')
  })

  it('falls back to default copy when the backend sends a blank message', () => {
    const error = toSpApiError(fetchError(403, { message: '   ' }))

    expect(error.message).toBe(messageForCode('forbidden'))
  })

  /**
   * A 409 explains itself and nothing else can: "this key was already used for
   * different inputs" is the entire content of the answer. Classifying it as
   * `unknown_error` would replace that with generic copy, so the one sentence
   * that tells the operator what happened has to survive.
   */
  it('keeps the backend explanation for a state clash', () => {
    const error = toSpApiError(fetchError(409, {
      message: 'Allocation idempotency key was already used for different inputs.',
      code: 'idempotency_conflict'
    }))

    expect(error.code).toBe('idempotency_conflict')
    expect(error.message).toBe('Allocation idempotency key was already used for different inputs.')
  })

  it('still classifies a 409 that carries no code', () => {
    const error = toSpApiError(fetchError(409, { message: 'That customer is no longer actively managed.' }))

    expect(error.code).toBe('conflict')
    expect(error.message).toBe('That customer is no longer actively managed.')
  })
})

describe('SpApiError classification helpers', () => {
  it('separates a state clash from a fault, because retrying cannot fix a clash', () => {
    expect(new SpApiError({ code: 'conflict', status: 409, message: 'x' }).isConflict).toBe(true)
    expect(new SpApiError({ code: 'idempotency_conflict', status: 409, message: 'x' }).isConflict).toBe(true)
    expect(new SpApiError({ code: 'invalid_status_transition', status: 409, message: 'x' }).isConflict).toBe(true)
    expect(new SpApiError({ code: 'server_error', status: 500, message: 'x' }).isConflict).toBe(false)
    expect(new SpApiError({ code: 'conflict', status: 409, message: 'x' }).retryable).toBe(false)
  })

  it('reports an expired or absent session as a session problem', () => {
    expect(new SpApiError({ code: 'session_expired', status: 401, message: 'x' }).isSessionExpired).toBe(true)
    expect(new SpApiError({ code: 'unauthenticated', status: 401, message: 'x' }).isSessionExpired).toBe(true)
    expect(new SpApiError({ code: 'forbidden', status: 403, message: 'x' }).isSessionExpired).toBe(false)
  })

  it('separates "not shipped" and "unreachable" from a genuine failure', () => {
    expect(new SpApiError({ code: 'endpoint_unavailable', status: 404, message: 'x' }).isUnavailable).toBe(true)
    expect(new SpApiError({ code: 'network_unreachable', status: 0, message: 'x' }).isUnavailable).toBe(true)
    expect(new SpApiError({ code: 'server_error', status: 500, message: 'x' }).isUnavailable).toBe(false)
  })

  it('passes an SpApiError through unchanged, so a re-thrown error is not reclassified', () => {
    const original = new SpApiError({ code: 'insufficient_credits', status: 402, message: 'Spent.' })

    expect(toSpApiError(original)).toBe(original)
  })

  it('is a real Error, so it survives being thrown and caught', () => {
    const error = new SpApiError({ code: 'forbidden', status: 403, message: 'Nope.' })

    expect(error).toBeInstanceOf(Error)
    expect(error.name).toBe('SpApiError')
  })
})

describe('messageForCode', () => {
  it('has customer-safe copy for every code the UI can produce', () => {
    const codes = [
      'validation_failed',
      'unauthenticated',
      'session_expired',
      'forbidden',
      'account_suspended',
      'not_found',
      'rate_limit_exceeded',
      'conflict',
      'idempotency_conflict',
      'invalid_status_transition',
      'profitability_review_required',
      'payment_pending',
      'payment_verification_failed',
      'insufficient_tokens',
      'insufficient_credits',
      'database_migration_required',
      'server_error',
      'network_unreachable',
      'endpoint_unavailable',
      'unknown_error'
    ] as const

    for (const code of codes) {
      const message = messageForCode(code)

      expect(message.length).toBeGreaterThan(0)
      expect(message).not.toMatch(/localhost|127\.0\.0\.1|omniroute|sk-/i)
    }
  })
})
