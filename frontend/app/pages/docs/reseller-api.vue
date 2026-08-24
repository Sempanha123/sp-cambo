<script setup lang="ts">
import { MODEL_ALIAS_PLACEHOLDER } from '~/utils/cliSnippets'

/**
 * Reference for the `/reseller-management` surface — the reseller's automation API.
 *
 * Everything here is read off the shipped implementation rather than a wish list:
 * the six routes and their scope middleware from `routes/api.php`, the validation
 * rules from `ResellerCustomerController` and `ResellerCustomerKeyController`, the
 * transfer semantics from `ResellerAllocationService`, and the error codes from the
 * exception mapping in `bootstrap/app.php`.
 *
 * Two of the seven grantable scopes are enforced by no route, and there is no usage
 * endpoint on this surface at all. Both are stated plainly in "Not on this surface
 * yet" — a reseller who plans an integration around an endpoint that does not exist
 * loses more than one who is told the gap up front.
 */
useSeoMeta({
  title: 'Reseller API',
  description: 'Automate your reseller account with a scoped management key: create managed customers, issue their inference keys and allocate quota from your own inventory.'
})

const config = useRuntimeConfig()

/** Control-plane root, from this deployment's config rather than a literal host. */
const controlPlane = computed(() => config.public.apiBaseUrl.replace(/\/+$/, ''))

const root = computed(() => `${controlPlane.value}/reseller-management`)

/**
 * A visible stand-in, never a real secret. `sk-spm-` is the real prefix, so the
 * remainder has to read as obviously fake.
 */
const MANAGEMENT_KEY_PLACEHOLDER = 'sk-spm-your-management-key'

const authExample = computed(() =>
  `curl ${root.value}/customers \\\n  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}"`
)

/**
 * Scope to endpoint, taken from the `management.scope:` middleware on each route.
 * An empty `endpoints` means the control plane will grant the scope but no route
 * reads it, so it authorises nothing today.
 */
const scopeRows = [
  { scope: 'customers:read', endpoints: ['GET /customers'] },
  { scope: 'customers:write', endpoints: ['POST /customers'] },
  { scope: 'keys:read', endpoints: ['GET /customers/{id}/api-keys'] },
  { scope: 'keys:write', endpoints: ['POST /customers/{id}/api-keys', 'POST /customers/{id}/api-keys/{keyId}/revoke'] },
  { scope: 'allocations:write', endpoints: ['POST /customers/{id}/allocations'] },
  { scope: 'allocations:read', endpoints: [] },
  { scope: 'usage:read', endpoints: [] }
]

const listCustomers = computed(() =>
  `curl ${root.value}/customers \\\n  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}"`
)

const listCustomersResponse = `{
  "data": [
    {
      "id": "41",
      "name": "Sokha Dev Team",
      "email": "team@example.com",
      "label": "Sokha — retainer",
      "status": "ACTIVE",
      "created_at": "2026-08-14T02:31:08+00:00"
    }
  ]
}`

const createCustomer = computed(() =>
  `curl ${root.value}/customers \\
  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "Sokha Dev Team",
    "email": "team@example.com",
    "label": "Sokha — retainer",
    "password": "<a-strong-generated-password>",
    "password_confirmation": "<a-strong-generated-password>"
  }'`
)

const listKeys = computed(() =>
  `curl ${root.value}/customers/41/api-keys \\\n  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}"`
)

const createKey = computed(() =>
  `curl ${root.value}/customers/41/api-keys \\
  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "label": "Sokha production",
    "allowed_model_aliases": ["${MODEL_ALIAS_PLACEHOLDER}"],
    "expires_at": "2027-01-31T23:59:59Z"
  }'`
)

const createKeyResponse = `{
  "data": {
    "key": {
      "id": "918",
      "label": "Sokha production",
      "prefix": "sk-spc-",
      "last_four": "4f2b",
      "status": "ACTIVE",
      "created_at": "2026-08-22T04:10:55+00:00",
      "last_used_at": null,
      "expires_at": "2027-01-31T23:59:59+00:00",
      "allowed_model_aliases": ["${MODEL_ALIAS_PLACEHOLDER}"]
    },
    "secret": "sk-spc-…shown-exactly-once…"
  }
}`

const revokeKey = computed(() =>
  `curl -X POST ${root.value}/customers/41/api-keys/918/revoke \\\n  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}"`
)

const allocate = computed(() =>
  `curl ${root.value}/customers/41/allocations \\
  -H "Authorization: Bearer ${MANAGEMENT_KEY_PLACEHOLDER}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "billing_mode": "TOKEN_QUOTA",
    "public_model_alias": "${MODEL_ALIAS_PLACEHOLDER}",
    "units": 500000,
    "idempotency_key": "onboarding-41-2026-08-22",
    "reason": "Initial allocation for the August retainer."
  }'`
)

const allocateResponse = `{
  "data": {
    "id": "77",
    "customer_id": "41",
    "billing_mode": "TOKEN_QUOTA",
    "public_model_alias": "${MODEL_ALIAS_PLACEHOLDER}",
    "units": "500000",
    "created_at": "2026-08-22T04:12:03+00:00"
  }
}`

const errorRows = [
  {
    status: '401',
    code: 'invalid_management_key',
    when: 'The bearer token is missing, does not start with sk-spm-, is unknown, has been revoked, has expired, or your reseller account has been suspended or has lost the reseller permission.'
  },
  {
    status: '403',
    code: 'insufficient_scope',
    when: 'The key is valid but does not hold the scope this endpoint requires. Scopes cannot be added to an existing key — create a replacement.'
  },
  {
    status: '404',
    code: 'not_found',
    when: 'The customer or key id is not one you manage, or the managed customer is not ACTIVE. Another reseller\'s ids are indistinguishable from ids that do not exist.'
  },
  {
    status: '422',
    code: 'validation_failed',
    when: 'A field is missing or malformed. The errors object names each field.'
  },
  {
    status: '402',
    code: 'insufficient_tokens / insufficient_credits',
    when: 'Your own inventory does not cover the allocation. Nothing is transferred.'
  },
  {
    status: '409',
    code: 'idempotency_conflict',
    when: 'The idempotency_key was already used with different inputs.'
  },
  {
    status: '429',
    code: 'rate_limit_exceeded',
    when: 'More than 60 requests in a minute. Retry-After tells you how long to wait.'
  }
]
</script>

<template>
  <SpDocsShell
    title="Reseller API"
    description="Drive your reseller account from your own software with a scoped management key — create managed customers, issue their inference keys and allocate quota out of your inventory."
  >
    <h2 id="three-credentials">
      Three credentials, none interchangeable
    </h2>
    <p>
      A <strong>browser session</strong> is what signing in to this website gives you. It can do
      everything your account is permitted to do, including creating and revoking management keys.
    </p>
    <p>
      An <strong><code>sk-spc-</code> inference key</strong> belongs to one of your customers and calls
      the inference gateway. It cannot reach this API.
    </p>
    <p>
      An <strong><code>sk-spm-</code> management key</strong> is your own automation credential for this
      API. It <strong>cannot make a model request</strong> and it cannot manage other management keys —
      creating and revoking those stays with a signed-in session on the
      <NuxtLink to="/reseller/management-keys">
        management keys page
      </NuxtLink>.
    </p>
    <p>
      Scopes are fixed when a key is created. There is no rotate endpoint and no way to widen a key
      later: to change what a key can do, create a replacement and revoke the old one.
    </p>

    <h2 id="base-url">
      Base URL
    </h2>
    <p>
      Every endpoint below lives on the control plane, under one prefix:
    </p>
    <div class="my-4 flex items-center gap-2 rounded-lg border border-default bg-elevated/30 px-4 py-3">
      <code class="min-w-0 flex-1 font-mono text-sm break-all text-toned">{{ root }}</code>
      <SpCopyButton
        :value="root"
        size="sm"
      />
    </div>
    <p>
      This is not the inference host. Model requests go to the gateway, which is documented under
      <NuxtLink to="/docs/base-urls">
        base URLs
      </NuxtLink>.
    </p>

    <h2 id="authentication">
      Authentication
    </h2>
    <p>
      Send the management secret as a bearer token. This surface accepts no other scheme — an
      <code>x-api-key</code> header is ignored and the request is refused as unauthenticated.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="authExample"
    />
    <p>
      A <code>401 invalid_management_key</code> means the key itself is not usable. Several unrelated
      causes produce it, and they are deliberately not distinguished in the response:
    </p>
    <ul>
      <li>the token is absent, or does not begin with <code>sk-spm-</code>;</li>
      <li>no key matches it, it has been revoked, or it is past its expiry;</li>
      <li>your reseller account is no longer active;</li>
      <li>
        your account no longer holds the reseller permission — in which case
        <strong>every management key you hold stops working at once</strong>, not just this one.
      </li>
    </ul>
    <p>
      Each accepted call stamps the key's <em>last used</em> time, which is visible on the management
      keys page. If a key you expect to be busy shows nothing, it is not reaching the API.
    </p>

    <h2 id="scopes">
      Scopes
    </h2>
    <p>
      Every endpoint requires one scope, and a key holding the wrong one is refused with
      <code>403 insufficient_scope</code> before the request is processed.
    </p>

    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Scope
            </th>
            <th class="px-4 py-3 font-medium">
              What it authorises
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="row in scopeRows"
            :key="row.scope"
          >
            <td class="px-4 py-3 align-top">
              <code class="font-mono text-xs whitespace-nowrap text-toned">{{ row.scope }}</code>
            </td>
            <td class="px-4 py-3 align-top">
              <template v-if="row.endpoints.length">
                <code
                  v-for="endpoint in row.endpoints"
                  :key="endpoint"
                  class="block font-mono text-xs break-all text-muted"
                >{{ endpoint }}</code>
              </template>
              <span
                v-else
                class="text-muted"
              >
                Nothing. The scope can be granted, but no endpoint on this surface reads it yet.
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p>
      Grant the narrowest set that does the job. A worker that only reads customers has no reason to
      hold <code>allocations:write</code>, which can move units out of your inventory.
    </p>

    <h2 id="customers">
      Managed customers
    </h2>

    <h3 id="list-customers">
      <code>GET /customers</code>
    </h3>
    <p>
      Requires <code>customers:read</code>. Returns every customer you manage, oldest first.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="listCustomers"
    />
    <SpCodeBlock
      filename="200 application/json"
      :code="listCustomersResponse"
    />

    <h3 id="create-customer">
      <code>POST /customers</code>
    </h3>
    <p>
      Requires <code>customers:write</code>. Creates a real SP Cambo account that the customer can sign
      in to, and links it to you.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="createCustomer"
    />
    <ul>
      <li><code>name</code> — required, up to 255 characters.</li>
      <li>
        <code>email</code> — required, and unique across all of SP Cambo. Someone who already has an
        account cannot be created as your customer; the response is
        <code>422 validation_failed</code>.
      </li>
      <li>
        <code>password</code> and <code>password_confirmation</code> — required and identical. At least
        12 characters with upper and lower case, a number and a symbol.
      </li>
      <li><code>label</code> — required, up to 150 characters. Your own reference, not shown to the customer.</li>
    </ul>
    <p>
      Responds <code>201</code> with the same shape as the list. <strong>The password is never returned
        and never recoverable.</strong> Generate one per customer, deliver it over a channel you trust,
      and tell them to change it — anything you keep is a credential you are now responsible for.
    </p>
    <p>
      A new customer has no entitlement and no keys. Until you allocate quota, their requests are
      refused for lack of balance.
    </p>

    <h2 id="customer-keys">
      Customer inference keys
    </h2>

    <h3 id="list-keys">
      <code>GET /customers/{id}/api-keys</code>
    </h3>
    <p>
      Requires <code>keys:read</code>. Newest first. Never includes a secret — only the prefix and last
      four characters, which is enough to match a key against your own records.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="listKeys"
    />
    <p>
      <code>status</code> is one of <code>ACTIVE</code>, <code>DISABLED</code>, <code>REVOKED</code> or
      <code>EXPIRED</code>. <code>EXPIRED</code> is derived from the expiry date at read time, so a key
      can report it without anything having been written.
    </p>

    <h3 id="create-key">
      <code>POST /customers/{id}/api-keys</code>
    </h3>
    <p>
      Requires <code>keys:write</code>.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="createKey"
    />
    <ul>
      <li><code>label</code> — required, up to 100 characters.</li>
      <li>
        <code>allowed_model_aliases</code> — optional. Each entry must be a currently published alias;
        one that is not gives <code>422 validation_failed</code>.
        <strong>Omitting the field grants every published alias</strong>, which is the widest scope
        available, so send the list explicitly unless you mean that.
      </li>
      <li><code>expires_at</code> — optional, and must be in the future.</li>
    </ul>
    <SpCodeBlock
      filename="201 application/json"
      :code="createKeyResponse"
    />
    <p>
      <strong><code>secret</code> appears in this response and nowhere else, ever.</strong> SP Cambo
      stores only a hash. If your automation does not capture it here, the key is unusable and your
      only recourse is to revoke it and issue another.
    </p>

    <h3 id="revoke-key">
      <code>POST /customers/{id}/api-keys/{keyId}/revoke</code>
    </h3>
    <p>
      Requires <code>keys:write</code>. Takes no body, and is safe to retry: revoking an
      already-revoked key changes nothing and still answers <code>200</code> with the key.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="revokeKey"
    />
    <p>
      Revocation is immediate and permanent. It does not refund or reclaim anything — units already
      allocated to that customer stay with the customer.
    </p>

    <h2 id="allocations">
      Allocating quota
    </h2>

    <h3 id="create-allocation">
      <code>POST /customers/{id}/allocations</code>
    </h3>
    <p>
      Requires <code>allocations:write</code>. This is a <strong>transfer, not a purchase</strong>: the
      units come out of entitlement you already own and are not billed again.
    </p>
    <SpCodeBlock
      filename="bash"
      :code="allocate"
    />
    <ul>
      <li><code>billing_mode</code> — <code>TOKEN_QUOTA</code> or <code>CREDIT_BALANCE</code>. It must match the inventory you are spending from.</li>
      <li>
        <code>public_model_alias</code> — required. You can only allocate an alias your own lots already
        cover; if none of your inventory permits it, the call fails for insufficient balance even when
        your totals look sufficient.
      </li>
      <li><code>units</code> — a whole number of at least 1.</li>
      <li><code>idempotency_key</code> — required, up to 191 characters. See below.</li>
      <li><code>reason</code> — required, 10 to 2,000 characters. It is written to the audit trail on both sides of the transfer.</li>
    </ul>
    <SpCodeBlock
      filename="201 application/json"
      :code="allocateResponse"
    />
    <p>
      <code>units</code> comes back as a string. Unit counts can exceed what a JavaScript number holds
      exactly, so the API never rounds one for you.
    </p>

    <h3 id="allocation-semantics">
      What the transfer actually does
    </h3>
    <ul>
      <li>
        <strong>Soonest-expiring inventory is spent first.</strong> Lots with an expiry date go before
        lots without one, earliest first. A single allocation can draw on several of your lots.
      </li>
      <li>
        <strong>The new lot inherits its source's expiry.</strong> An allocation cannot outlive the
        entitlement it came from, so allocating from a lot that expires next week hands the customer
        units that expire next week.
      </li>
      <li>
        <strong>The customer's lot is scoped to the one alias you named</strong>, even if your source
        lot allowed several.
      </li>
      <li>
        <strong>It is all or nothing.</strong> If your available units — remaining minus anything
        reserved for in-flight requests — do not cover the full amount, nothing moves and you get
        <code>402</code>.
      </li>
      <li>
        <strong>There is no un-allocate.</strong> Units that have moved belong to the customer. Revoking
        their keys does not bring them back.
      </li>
    </ul>

    <h3 id="idempotency">
      Idempotency
    </h3>
    <p>
      <code>idempotency_key</code> is required because a retried allocation must not transfer twice.
      Choose a value your automation can reproduce for the same intent — an onboarding step id, or a
      customer id and period.
    </p>
    <ul>
      <li>
        Reusing a key with <strong>identical</strong> inputs returns the original transfer and moves
        nothing further. This is what makes a timeout safe to retry.
      </li>
      <li>
        Reusing a key with <strong>any different</strong> input — a different customer, mode, alias or
        unit count — is refused with <code>409 idempotency_conflict</code>. It is not treated as a new
        transfer, because one of the two calls is a bug and guessing which would be worse.
      </li>
    </ul>
    <p>
      Keys are checked across your whole account, so generate them per intent rather than per attempt.
    </p>

    <h2 id="errors">
      Errors
    </h2>
    <p>
      Every failure is JSON with a <code>message</code> and a stable machine <code>code</code>. Branch on
      the code, never on the prose.
    </p>

    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Status
            </th>
            <th class="px-4 py-3 font-medium">
              Code
            </th>
            <th class="px-4 py-3 font-medium">
              When
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="row in errorRows"
            :key="row.code"
          >
            <td class="px-4 py-3 align-top font-medium text-default">
              {{ row.status }}
            </td>
            <td class="px-4 py-3 align-top">
              <code class="font-mono text-xs break-all text-toned">{{ row.code }}</code>
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ row.when }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p>
      <code>404</code> is also the answer for an id belonging to another reseller. That is deliberate:
      a distinguishable response would let anyone enumerate other resellers' customers.
    </p>
    <p>
      The inference gateway has its own, separate set of codes, listed under
      <NuxtLink to="/docs/errors">
        errors
      </NuxtLink>.
    </p>

    <h2 id="rate-limit">
      Rate limit
    </h2>
    <p>
      This surface allows <strong>60 requests per minute</strong>, counted per reseller account rather
      than per key — running two workers on two keys does not double it. Exceeding it gives
      <code>429 rate_limit_exceeded</code> with a <code>Retry-After</code> header; honour it instead of
      retrying immediately.
    </p>
    <p>
      Creating a customer or a key from a signed-in browser session is limited far more tightly than
      this. If you are batching, use a management key.
      <NuxtLink to="/docs/rate-limits">
        Rate limits
      </NuxtLink> covers the gateway's separate per-key limits.
    </p>

    <h2 id="not-yet-available">
      Not on this surface yet
    </h2>
    <p>
      So that you do not design an integration around something that does not exist:
    </p>
    <ul>
      <li>
        <strong>No usage endpoint.</strong> <code>usage:read</code> can be granted but no endpoint reads
        it. Per-customer usage is visible to a signed-in session on the
        <NuxtLink to="/reseller">
          managed customers
        </NuxtLink> pages.
      </li>
      <li>
        <strong>No way to read allocations back.</strong> <code>allocations:read</code> is likewise
        grantable and unused. Record the transfer id from the <code>201</code> response; it is the only
        handle you will get.
      </li>
      <li>
        <strong>No suspend or close for a managed customer</strong>, and no way to change a customer's
        label or email. Revoking their keys is the available lever.
      </li>
      <li>
        <strong>No enable, disable or rotate for a customer's key</strong> — only issue and revoke.
      </li>
      <li>
        <strong>No management-key administration.</strong> Listing, creating and revoking
        <code>sk-spm-</code> keys requires a signed-in session by design, so a leaked management key
        cannot mint more of itself.
      </li>
    </ul>
    <p>
      These are gaps in the API, not in this page. If your integration needs one of them, say so —
      a documented gap is easier to prioritise than a guessed one.
    </p>
  </SpDocsShell>
</template>
