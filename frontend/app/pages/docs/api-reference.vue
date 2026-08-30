<script setup lang="ts">
useSeoMeta({
  title: 'API reference',
  description: 'SP Cambo inference endpoints, request shapes, response envelopes and the account endpoints behind the dashboard.'
})

const { inferenceRoot, openAiBase, curlMessages, nodeAnthropic, pythonAnthropic } = useCliSnippets()
const config = useRuntimeConfig()

const controlPlane = computed(() => config.public.apiBaseUrl.replace(/\/+$/, ''))

interface GatewayEndpoint {
  method: string
  path: string
  surface: string
  description: string
  metered: boolean
}

/**
 * Every surface the gateway serves, not just the two a customer is most likely to
 * call. An endpoint that ships but goes undocumented is an endpoint people find out
 * about from a stray 404, and the two read surfaces below are exactly the ones an
 * integration wants before it sends anything.
 */
const gatewayEndpoints: GatewayEndpoint[] = [
  {
    method: 'POST',
    path: '/v1/messages',
    surface: 'Anthropic Messages',
    description: 'Single-turn or multi-turn completion. Supports streaming and tool use where the model does.',
    metered: true
  },
  {
    method: 'POST',
    path: '/v1/messages/count_tokens',
    surface: 'Anthropic Messages',
    description: 'Counts the input tokens a request would use, without generating a completion.',
    metered: true
  },
  {
    method: 'POST',
    path: '/v1/responses',
    surface: 'OpenAI Responses',
    description: 'OpenAI-compatible Responses call for clients configured with the Responses wire API.',
    metered: true
  },
  {
    method: 'POST',
    path: '/v1/chat/completions',
    surface: 'OpenAI Chat Completions',
    description: 'For clients and SDKs written against Chat Completions rather than Responses.',
    metered: true
  },
  {
    method: 'GET',
    path: '/v1/models',
    surface: 'OpenAI-shaped list',
    description: 'The aliases this key may call, with the protocols each one supports. Never lists an alias outside the key\'s scope.',
    metered: false
  },
  {
    method: 'GET',
    path: '/v1/key/status',
    surface: 'SP Cambo',
    description: 'Key state and expiry, allowed aliases, remaining token quota and credit, and the ceilings recorded on the key.',
    metered: false
  }
]

const envelope = `{
  "data": {
    "...": "endpoint payload"
  }
}`

const errorEnvelope = `{
  "message": "Human-readable summary.",
  "code": "insufficient_tokens",
  "errors": {
    "field": ["Only present for validation failures."]
  }
}`

const tabs = computed(() => [
  { label: 'cURL', value: 'curl', code: curlMessages.value, filename: 'bash' },
  { label: 'Node.js', value: 'node', code: nodeAnthropic.value, filename: 'index.mjs' },
  { label: 'Python', value: 'python', code: pythonAnthropic.value, filename: 'main.py' }
])
</script>

<template>
  <SpDocsShell
    title="API reference"
    description="Every endpoint the inference gateway serves, plus the control-plane endpoints behind this dashboard."
  >
    <h2 id="inference">
      Gateway endpoints
    </h2>
    <p>
      Base URL <code>{{ inferenceRoot }}</code> for Anthropic-compatible clients, or
      <code>{{ openAiBase }}</code> for OpenAI-compatible clients. Authenticate with an SP Cambo API
      key.
    </p>

    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Endpoint
            </th>
            <th class="px-4 py-3 font-medium">
              Surface
            </th>
            <th class="px-4 py-3 font-medium">
              Description
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="endpoint in gatewayEndpoints"
            :key="endpoint.path"
          >
            <td class="px-4 py-3 align-top whitespace-nowrap">
              <UBadge
                color="neutral"
                variant="subtle"
                size="sm"
                class="me-2 font-mono"
              >
                {{ endpoint.method }}
              </UBadge>
              <code class="font-mono text-xs text-toned">{{ endpoint.path }}</code>
              <UBadge
                v-if="endpoint.metered"
                color="neutral"
                variant="subtle"
                size="sm"
                class="mt-1.5 block w-fit"
              >
                Metered
              </UBadge>
            </td>
            <td class="px-4 py-3 align-top text-muted whitespace-nowrap">
              {{ endpoint.surface }}
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ endpoint.description }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p>
      The two <code>GET</code> surfaces are not metered: they read your own key's state and never reach
      a model, so they cost no quota. They do count against the per-key request ceiling described in
      <NuxtLink to="/docs/rate-limits">
        rate limits
      </NuxtLink>.
    </p>
    <p>
      <code>/v1/messages/count_tokens</code> is a free local utility. SP Cambo counts the model-visible
      request locally, returns the estimate, does not reserve Tokens or Credits, and does not call
      OmniRoute or any background provider.
    </p>
    <p>
      The gateway also answers <code>GET /health</code> without a credential. It reports whether the
      gateway itself is up and says nothing about your account.
    </p>

    <h2 id="protocol-support">
      Not every alias serves every protocol
    </h2>
    <p>
      An alias declares which of the four protocols it supports. Calling one through a protocol it does
      not support is refused with <code>model_unavailable</code> before any upstream call, so it costs
      nothing — but it is a configuration error, not an outage, and retrying will not clear it. Each
      alias's supported protocols are listed in the <NuxtLink to="/models">
        catalogue
      </NuxtLink>
      and in <code>GET /v1/models</code>.
    </p>

    <h2 id="what-changes">
      What SP Cambo changes in your request
    </h2>
    <p>
      Your messages, tool definitions and system prompts are forwarded unchanged. Three things are not:
    </p>
    <ul>
      <li>
        <code>model</code> is replaced with the upstream model the alias currently routes to. That
        indirection is the point of an alias.
      </li>
      <li>
        the maximum output field for the surface you called is clamped down to your plan's ceiling if
        you asked for more. Asking for more than your <em>key</em> allows is refused up front instead,
        with <code>max_output_tokens_exceeded</code>.
      </li>
      <li>
        on a streaming Chat Completions call, <code>stream_options.include_usage</code> is set, because
        without a usage chunk the request cannot be metered from what the model reports.
      </li>
    </ul>
    <p>
      Each surface accepts a fixed set of parameters. Anything outside it is rejected with
      <code>unsupported_parameter</code> and the name of the offending field, rather than being dropped
      silently — a parameter you believe is in effect but which was quietly discarded is worse than an
      error.
    </p>

    <h2 id="example">
      Example request
    </h2>
    <UTabs
      :items="tabs"
      class="my-6"
    >
      <template #content="{ item }">
        <SpCodeBlock
          :code="item.code"
          :filename="item.filename"
        />
      </template>
    </UTabs>

    <h2 id="model-names">
      Model names
    </h2>
    <p>
      The <code>model</code> field takes an SP Cambo public alias, not a provider model id. Aliases are
      listed in the <NuxtLink to="/models">
        catalogue
      </NuxtLink> and remain stable across upstream
      routing changes. An unknown alias, or one outside your key's scope, is rejected before any
      upstream call is made — so it costs you nothing.
    </p>

    <h2 id="control-plane">
      Control-plane endpoints
    </h2>
    <p>
      The account API is served from <code>{{ controlPlane }}</code> and authenticated with your
      browser session. It is what this dashboard uses. It is not intended as an integration surface for
      your application code, and it will not accept an inference API key.
    </p>
    <p>
      Public, no credential:
    </p>
    <ul>
      <li><code>GET /health</code> — liveness of the control plane.</li>
      <li><code>GET /status</code> — the service status shown on the status page.</li>
      <li><code>GET /catalog/models</code>, <code>GET /catalog/packages</code> — the published catalogue, exactly as the pricing and model pages read it.</li>
    </ul>
    <p>
      With a signed-in session:
    </p>
    <ul>
      <li><code>POST /auth/register/code</code> sends the manual sign-up verification code; <code>POST /auth/register</code> consumes that code and creates the account. <code>POST /auth/login</code>, <code>POST /auth/logout</code>, <code>POST /auth/forgot-password</code>, <code>POST /auth/reset-password</code> handle the remaining account session flows.</li>
      <li><code>GET /me</code>, <code>PATCH /me</code>, <code>POST /me/password</code>, <code>GET /me/sessions</code>, <code>DELETE /me/sessions/{id}</code></li>
      <li><code>GET /me/balance</code>, <code>GET /me/entitlements</code>, <code>GET /me/activity</code>, <code>GET /me/usage/summary</code></li>
      <li><code>GET|POST /me/api-keys</code>, <code>POST /me/api-keys/{id}/rotate</code>, <code>PATCH /me/api-keys/{id}/status</code>, <code>GET /me/api-keys/{id}/status</code></li>
      <li><code>GET|POST /orders</code>, <code>GET /orders/{id}</code>, <code>GET|POST /orders/{id}/payment</code>, <code>POST /orders/{id}/payment/verify</code>, <code>POST /promotions/preview</code></li>
    </ul>
    <p>
      Administration and reseller endpoints exist under <code>/admin</code> and <code>/reseller</code>
      and are refused unless your account holds the matching permission. Resellers who need to act from
      their own software use a
      <NuxtLink to="/reseller/management-keys">
        management key
      </NuxtLink>
      against <code>/reseller-management</code> instead of a browser session; that key carries explicit
      scopes and cannot reach anything outside them. Those six endpoints are documented in full under
      <NuxtLink to="/docs/reseller-api">
        the reseller API
      </NuxtLink>.
    </p>

    <h2 id="envelopes">
      Response envelopes
    </h2>
    <p>Successful control-plane responses wrap their payload in <code>data</code>:</p>
    <SpCodeBlock
      filename="200 OK"
      :code="envelope"
    />

    <p>Failures carry a stable machine <code>code</code> alongside the human message:</p>
    <SpCodeBlock
      filename="4xx / 5xx"
      :code="errorEnvelope"
    />
    <p>
      Branch on <code>code</code>, never on <code>message</code> — message wording can change at any
      time. Gateway failures use a different body: they are shaped like the SDK you are calling so your
      existing error handling keeps working, which means the SP Cambo code sits in a different field.
      See <NuxtLink to="/docs/errors">
        errors
      </NuxtLink> for both shapes and the full code list.
    </p>

    <h2 id="idempotency">
      Idempotency and money
    </h2>
    <p>
      Order fulfilment is idempotent: a verified payment credits your account exactly once, however
      many times verification is re-checked. Asking SP Cambo to re-check a payment is always safe.
    </p>
    <p>
      All monetary values are transported as integer minor units with an explicit currency and
      exponent, never as floating-point numbers. Token and credit quantities are integer strings for
      the same reason. If you consume these values, keep them exact — do not parse them into a float.
    </p>
  </SpDocsShell>
</template>
