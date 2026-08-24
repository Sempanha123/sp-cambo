<script setup lang="ts">
useSeoMeta({
  title: 'Errors',
  description: 'SP Cambo error envelope, the stable machine codes, what each one means and the correct client behaviour for each.'
})

/**
 * Three of the codes below can only be resolved by SP Cambo, so this page names the
 * channel — when there is one. Unset in a deployment that has not published support,
 * in which case the section is not written rather than written without an address.
 */
const support = useSupportChannel()

interface ErrorRow {
  code: string
  status: string
  meaning: string
  action: string
  retryable: boolean
}

const errorBody = `{
  "message": "This entitlement has no remaining token quota.",
  "code": "insufficient_tokens"
}`

const validationBody = `{
  "message": "Please check the highlighted fields and try again.",
  "code": "validation_failed",
  "errors": {
    "email": ["This email is already registered."]
  }
}`

const handling = `const res = await fetch(url, { headers })

if (!res.ok) {
  const body = await res.json().catch(() => ({}))

  switch (body.code) {
    case 'insufficient_tokens':
    case 'insufficient_credits':
      return stopAndPromptForTopUp()      // never retry: the answer will not change
    case 'rate_limit_exceeded':
    case 'concurrency_limit_exceeded':
      return retryAfterBackoff(res.headers.get('retry-after'))
    case 'session_expired':
    case 'unauthenticated':
      return reauthenticate()
    default:
      throw new Error(body.message ?? 'Request failed')
  }
}`

const anthropicError = `{
  "type": "error",
  "error": {
    "type": "invalid_request_error",
    "message": "The model does not support this inference protocol.",
    "sp_cambo_code": "model_unavailable"
  }
}`

const openAiError = `{
  "error": {
    "message": "The model does not support this inference protocol.",
    "type": "invalid_request_error",
    "code": "model_unavailable"
  }
}`

const serverCodes: ErrorRow[] = [
  {
    code: 'validation_failed',
    status: '422',
    meaning: 'The request body failed validation. Per-field messages are in errors.',
    action: 'Fix the input. Show the field messages rather than the summary.',
    retryable: false
  },
  {
    code: 'unauthenticated',
    status: '401',
    meaning: 'No credential was presented, or it is not valid.',
    action: 'Sign in again, or check the API key you are sending.',
    retryable: false
  },
  {
    code: 'session_expired',
    status: '401 / 419',
    meaning: 'The browser session is no longer valid.',
    action: 'Re-authenticate. This dashboard does this for you and returns you to the page you were on.',
    retryable: false
  },
  {
    code: 'forbidden',
    status: '403',
    meaning: 'Authenticated, but not permitted — often a key scoped to different models.',
    action: 'Check the key scope. Do not change the base URL; that is a different failure.',
    retryable: false
  },
  {
    code: 'account_suspended',
    status: '403',
    meaning: 'The account is suspended.',
    action: 'Contact SP Cambo. Retrying will not clear it.',
    retryable: false
  },
  {
    code: 'not_found',
    status: '404',
    meaning: 'The addressed record does not exist, or is not yours.',
    action: 'Check the identifier.',
    retryable: false
  },
  {
    code: 'rate_limit_exceeded',
    status: '429',
    meaning: 'A per-key or per-package rate limit was hit.',
    action: 'Back off and retry. Honour Retry-After when it is present.',
    retryable: true
  },
  {
    code: 'insufficient_tokens',
    status: '402',
    meaning: 'No remaining token quota on any usable entitlement.',
    action: 'Buy another package. Requests stop rather than becoming an overage bill.',
    retryable: false
  },
  {
    code: 'insufficient_credits',
    status: '402',
    meaning: 'No remaining credit balance.',
    action: 'Top up. Retrying without a top-up returns the same error.',
    retryable: false
  },
  {
    code: 'payment_pending',
    status: '402',
    meaning: 'The payment for this order has not been confirmed yet.',
    action: 'Wait for verification, or ask SP Cambo to re-check. Re-checking is always safe.',
    retryable: true
  },
  {
    code: 'payment_verification_failed',
    status: '402 / 422',
    meaning: 'The payment could not be verified against the payment network.',
    action: 'Do not re-pay on the strength of this alone. Check the order, then contact support.',
    retryable: false
  },
  {
    code: 'server_error',
    status: '5xx',
    meaning: 'A fault on the SP Cambo side.',
    action: 'Retry with backoff. If a request was metered and then failed, the reservation is released.',
    retryable: true
  }
]

const clientCodes: ErrorRow[] = [
  {
    code: 'network_unreachable',
    status: '—',
    meaning: 'The request never reached SP Cambo: offline, DNS, TLS or a blocked host.',
    action: 'Check connectivity and the base URL. Nothing was metered.',
    retryable: true
  },
  {
    code: 'endpoint_unavailable',
    status: '404 / 501',
    meaning: 'The endpoint has not been published yet.',
    action: 'Nothing to fix on your side. Pages that depend on one say so plainly.',
    retryable: false
  },
  {
    code: 'unknown_error',
    status: 'any',
    meaning: 'A failure that did not carry a recognised code.',
    action: 'Treat as unexpected. Report it if it persists.',
    retryable: false
  }
]

/**
 * Codes only the inference gateway sends. They are listed separately because they
 * arrive in the SDK-shaped body below rather than the control-plane envelope, so the
 * field you read them from is different.
 */
const gatewayCodes: ErrorRow[] = [
  {
    code: 'invalid_api_key',
    status: '401',
    meaning: 'No key was sent, or it is malformed, or SP Cambo does not recognise it.',
    action: 'Check the key. An SP Cambo key starts with sk-spc-.',
    retryable: false
  },
  {
    code: 'conflicting_api_keys',
    status: '401',
    meaning: 'Authorization and x-api-key were both sent, with different values.',
    action: 'Send one credential. A leftover environment variable from another provider is the usual cause.',
    retryable: false
  },
  {
    code: 'api_key_disabled',
    status: '403',
    meaning: 'The key exists but is disabled. Also api_key_revoked and api_key_expired.',
    action: 'Re-enable it, or use another. A revoked key never comes back.',
    retryable: false
  },
  {
    code: 'model_not_allowed',
    status: '403',
    meaning: 'The alias is outside this key\'s scope, or is no longer published.',
    action: 'Call GET /v1/models to see what this key may use.',
    retryable: false
  },
  {
    code: 'model_unavailable',
    status: '400',
    meaning: 'The alias does not support the protocol you called it through.',
    action: 'Use a protocol the alias supports. The catalogue lists them per alias.',
    retryable: false
  },
  {
    code: 'invalid_model',
    status: '400',
    meaning: 'The model field is missing or is not a valid alias.',
    action: 'Send a public alias, not a provider model id.',
    retryable: false
  },
  {
    code: 'unsupported_parameter',
    status: '400',
    meaning: 'A parameter outside the set this surface accepts. The message names it.',
    action: 'Remove it. It is rejected rather than dropped so it cannot silently not apply.',
    retryable: false
  },
  {
    code: 'invalid_max_output_tokens',
    status: '400',
    meaning: 'The maximum output field is not a positive integer.',
    action: 'Send a positive integer, or omit it and take the default.',
    retryable: false
  },
  {
    code: 'max_output_tokens_exceeded',
    status: '400',
    meaning: 'More output was requested than the key permits.',
    action: 'Lower the request. Your key\'s ceiling is on its card in the dashboard.',
    retryable: false
  },
  {
    code: 'request_too_large',
    status: '413',
    meaning: 'The body exceeds the service limit or your key\'s max_request_bytes.',
    action: 'Split the request. It is refused before any upstream call, so it costs nothing.',
    retryable: false
  },
  {
    code: 'concurrency_limit_exceeded',
    status: '429',
    meaning: 'Too many of your requests are in flight at once.',
    action: 'Wait for one to finish — Retry-After is short. Bound your own parallelism.',
    retryable: true
  },
  {
    code: 'rate_limiter_unavailable',
    status: '503',
    meaning: 'The limiter could not be reached, so the request was refused rather than admitted unchecked.',
    action: 'Retry with backoff. Nothing was metered.',
    retryable: true
  },
  {
    code: 'billing_unavailable',
    status: '503',
    meaning: 'The control plane could not be reached to reserve or settle the request.',
    action: 'Retry with backoff. No quota is spent on a request that was never reserved.',
    retryable: true
  },
  {
    code: 'upstream_rejected',
    status: '4xx',
    meaning: 'The provider refused the request itself. The status is passed through.',
    action: 'Fix the request. The reservation is released, so it is not charged.',
    retryable: false
  },
  {
    code: 'upstream_unavailable',
    status: '503',
    meaning: 'The provider was unavailable, over capacity or too slow to answer.',
    action: 'Retry with backoff.',
    retryable: true
  },
  {
    code: 'upstream_invalid_response',
    status: '502',
    meaning: 'The provider returned something that is not a valid response body.',
    action: 'Retry. The reservation is reconciled rather than charged as used.',
    retryable: true
  },
  {
    code: 'billing_settlement_pending',
    status: '502',
    meaning: 'The response carried no usage figures, so it could not be settled.',
    action: 'Retry. The reservation is held for reconciliation, not billed as an estimate.',
    retryable: true
  },
  {
    code: 'client_disconnected',
    status: '499',
    meaning: 'Your client closed the connection before the response finished.',
    action: 'Usually your own timeout or a cancelled request. The reservation is released.',
    retryable: false
  }
]
</script>

<template>
  <SpDocsShell
    title="Errors"
    description="Every failure carries a stable machine code. Branch on the code, not on the message."
  >
    <h2 id="envelope">
      The control-plane error envelope
    </h2>
    <p>
      Control-plane failures return a JSON body with a human <code>message</code> and a machine
      <code>code</code>:
    </p>
    <SpCodeBlock
      filename="402 Payment Required"
      :code="errorBody"
    />
    <p>
      Validation failures add an <code>errors</code> map keyed by field name:
    </p>
    <SpCodeBlock
      filename="422 Unprocessable Content"
      :code="validationBody"
    />
    <p>
      <code>code</code> is stable and safe to compare against. <code>message</code> is copy: it is
      written for a human reading a screen, it can be reworded at any time, and it may be localised.
      Never branch on it, and never regex it.
    </p>

    <h2 id="codes">
      Control-plane codes
    </h2>
    <SpDocsErrorTable
      :rows="serverCodes"
      status-label="HTTP"
    />

    <h2 id="client-side">
      Classified by the client
    </h2>
    <p>
      These three are produced by SP Cambo's own clients — this dashboard included — when a failure
      arrives without a usable code. They are not sent by the server, but you will see them in the UI
      and it is useful to know what they mean.
    </p>
    <SpDocsErrorTable
      :rows="clientCodes"
      status-label="Seen as"
    />

    <h2 id="handling">
      Handling them
    </h2>
    <SpCodeBlock
      filename="client.ts"
      :code="handling"
    />
    <p>
      The important distinction is between <em>retry</em> and <em>stop</em>. A quota error will return
      the same answer however many times you send it, so retrying only burns your own rate limit. A
      429 or a 5xx is worth retrying with backoff.
    </p>
    <p>
      That example reads <code>body.code</code>, which is the control-plane shape. Inference failures
      put the code somewhere else — see below before reusing this against the gateway.
    </p>

    <h2 id="inference-errors">
      Errors from the inference gateway
    </h2>
    <p>
      Inference failures do not use the envelope above. They are shaped like the API you are calling,
      so your existing SDK error handling keeps working — which also means the SP Cambo code is in a
      different field depending on the surface. On <code>/v1/messages</code> and
      <code>/v1/messages/count_tokens</code>:
    </p>
    <SpCodeBlock
      filename="400 Bad Request"
      :code="anthropicError"
    />
    <p>
      On <code>/v1/responses</code>, <code>/v1/chat/completions</code> and the key and model read
      endpoints:
    </p>
    <SpCodeBlock
      filename="400 Bad Request"
      :code="openAiError"
    />
    <p>
      Read the code from <code>error.sp_cambo_code</code> on the first and <code>error.code</code> on
      the second. The <code>type</code> field is the upstream classification your SDK expects and is
      coarser than the code — several distinct SP Cambo failures share one <code>type</code>, so branch
      on the code.
    </p>

    <h3 id="gateway-codes">
      Gateway codes
    </h3>
    <p>
      These are additional to the codes above; <code>rate_limit_exceeded</code>,
      <code>insufficient_tokens</code>, <code>insufficient_credits</code>,
      <code>account_suspended</code> and <code>server_error</code> also arrive here, meaning the same
      thing.
    </p>
    <SpDocsErrorTable
      :rows="gatewayCodes"
      status-label="HTTP"
    />
    <p>
      Every <code>4xx</code> in the table is refused before any upstream call and is therefore not
      metered — a misconfigured client cannot quietly drain a package. The exception is
      <code>upstream_rejected</code>, which is the provider refusing the request itself; there the
      reservation is released, so it is not charged either. Where a request reached the provider and
      then failed part-way, the reservation is released or held for reconciliation rather than settled,
      so you are never charged an estimate for a response you did not receive.
    </p>
    <p>
      For errors that appear mid-stream rather than in the status line, see
      <NuxtLink to="/docs/streaming">
        streaming
      </NuxtLink>.
    </p>

    <h2 id="not-in-errors">
      What errors never contain
    </h2>
    <p>
      Error bodies never include a stack trace, an internal hostname, an upstream provider identifier
      or a credential. If you are seeing a framework error page instead of one of these envelopes, you
      are not talking to SP Cambo — check the base URL and any proxy in between.
    </p>

    <!--
      Three rows above end by telling the reader to contact SP Cambo:
      `account_suspended`, `payment_pending` and `payment_verification_failed`. None
      of them can be resolved by the client, so this names the channel — and appears
      only when the deployment has published one, because a documented address that
      does not work is worse than an undocumented one.
    -->
    <template v-if="support">
      <h2 id="getting-help">
        Reaching SP Cambo about one of these
      </h2>
      <p>
        <code>account_suspended</code>, <code>payment_pending</code> and
        <code>payment_verification_failed</code> are the three failures no change to your client can
        clear. For those, contact SP Cambo at <SpSupportLink variant="inline" />.
      </p>
      <p>
        Include the <code>code</code>, the time, and the order reference if a payment is involved.
        Never send an API key: SP Cambo will never ask for one, and a key in a support thread has to be
        revoked. If a key is what you suspect, the dashboard shows its prefix and its last use, and
        that is enough to identify it.
      </p>
    </template>
  </SpDocsShell>
</template>
