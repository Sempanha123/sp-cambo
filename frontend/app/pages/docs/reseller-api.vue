<script setup lang="ts">
useSeoMeta({
  title: 'Reseller API',
  description: 'Create managed customers, sell package quota, issue customer inference keys and automate SP Cambo reseller workflows.'
})

const config = useRuntimeConfig()
const apiBase = computed(() => String(config.public.apiBaseUrl).replace(/\/+$/, ''))
const root = computed(() => `${apiBase.value}/reseller-management`)
const inferenceRoot = computed(() => apiBase.value.replace(/\/api\/v1\/?$/, ''))

const managementKey = 'sk-spm-your-management-key'
const customerKey = 'sk-your-customer-key'
const customerId = '4'
const lotId = '01EXAMPLE_RESELLER_LOT'

const inventoryExample = computed(() => `curl "${root.value}/inventory" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json"`)

const customersExample = computed(() => `curl "${root.value}/customers" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json"`)

const createCustomerExample = computed(() => `curl -X POST "${root.value}/customers" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "Demo Customer",
    "email": "customer@example.com",
    "label": "API customer",
    "password": "ChangeMe123!Strong",
    "password_confirmation": "ChangeMe123!Strong"
  }'`)

const sellPackageExample = computed(() => `curl -X POST "${root.value}/customers/${customerId}/allocations" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -d '{
    "inventory_lot_id": "${lotId}",
    "units": 1000000,
    "idempotency_key": "seller-order-10001",
    "reason": "Customer purchased one million package tokens."
  }'`)

const saleHistoryExample = computed(() => `curl "${root.value}/customers/${customerId}/allocations?limit=50" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json"`)

const createKeyExample = computed(() => `curl -X POST "${root.value}/customers/${customerId}/api-keys" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -d '{
    "label": "Customer production API",
    "allowed_model_aliases": [
      "claude-sonnet-5",
      "claude-haiku-4-5"
    ]
  }'`)

const checkKeyExample = computed(() => `curl -X POST "${apiBase.value}/keys/check" \\
  -H "Content-Type: application/json" \\
  -d '{
    "api_key": "${customerKey}"
  }'`)

const inferenceExample = computed(() => `curl -X POST "${inferenceRoot.value}/v1/messages" \\
  -H "x-api-key: ${customerKey}" \\
  -H "anthropic-version: 2023-06-01" \\
  -H "content-type: application/json" \\
  -d '{
    "model": "claude-sonnet-5",
    "max_tokens": 100,
    "messages": [
      {
        "role": "user",
        "content": "Say hello from SP Cambo reseller API."
      }
    ]
  }'`)

const revokeKeyExample = computed(() => `curl -X POST "${root.value}/customers/${customerId}/api-keys/KEY_ID/revoke" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json"`)

const suspendExample = computed(() => `curl -X PATCH "${root.value}/customers/${customerId}/status" \\
  -H "Authorization: Bearer ${managementKey}" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -d '{
    "status": "SUSPENDED",
    "reason": "Customer requested a temporary suspension."
  }'`)
</script>

<template>
  <SpDocsShell
    title="Reseller API"
    description="Use one normal SP Cambo package catalogue. Paid packages bought by an active reseller become reseller inventory automatically; the reseller then sells part of a package to managed customers."
  >
    <h2>Current flow</h2>
    <ol>
      <li>Active reseller buys a normal SP Cambo package.</li>
      <li>The paid package automatically becomes <code>RESELLER_STOCK</code>.</li>
      <li><code>GET /inventory</code> returns one row per package lot.</li>
      <li>Sell from that exact package with <code>inventory_lot_id</code>.</li>
      <li>The customer's allocation inherits all models included in that package.</li>
      <li>The allocation is one shared balance across those models, not one balance per model.</li>
      <li>Issue the customer an <code>sk-</code> inference key scoped to the funded models.</li>
    </ol>

    <UAlert
      class="my-5"
      color="warning"
      variant="subtle"
      icon="i-lucide-key-round"
      title="Keep the management key server-side"
      description="An sk-spm- management key controls customers, allocations and keys. Never put it in browser JavaScript, a public repository or a mobile app."
    />

    <h2>Credentials</h2>
    <ul>
      <li><code>sk-spm-...</code> — reseller management API only.</li>
      <li><code>sk-...</code> — customer inference API only.</li>
      <li>A signed-in browser session manages the reseller's own management keys.</li>
    </ul>

    <h2>1. Check reseller inventory</h2>
    <p>
      Requires <code>allocations:read</code>. Each returned row is one real package lot and contains
      <code>allowed_model_aliases</code>, remaining units and expiry.
    </p>
    <SpCodeBlock filename="bash" :code="inventoryExample" />

    <h2>2. List customers</h2>
    <SpCodeBlock filename="bash" :code="customersExample" />

    <h2>3. Create a customer</h2>
    <p>Requires <code>customers:write</code>.</p>
    <SpCodeBlock filename="bash" :code="createCustomerExample" />

    <h2>4. Sell package quota</h2>
    <p>
      Requires <code>allocations:write</code>. Use the exact <code>id</code> returned by
      <code>GET /inventory</code>. Do not select one model here.
    </p>
    <SpCodeBlock filename="bash" :code="sellPackageExample" />

    <p>
      If a Claude package contains 9 Claude aliases and you sell 1,000,000 units, the customer gets
      one 1,000,000-unit shared balance usable across those 9 aliases.
    </p>

    <h2>5. Check sales history and remaining customer balance</h2>
    <p>
      Requires <code>allocations:read</code>. Package sales return their model scope and current
      <code>customer_balance</code>, including original, remaining, used, reserved, status and expiry.
    </p>
    <SpCodeBlock filename="bash" :code="saleHistoryExample" />

    <h2>6. Issue customer inference key</h2>
    <p>
      Requires <code>keys:write</code>. Send the model aliases funded for that customer. The secret is
      returned on creation; store/deliver it securely.
    </p>
    <SpCodeBlock filename="bash" :code="createKeyExample" />

    <h2>7. Check customer key</h2>
    <SpCodeBlock filename="bash" :code="checkKeyExample" />

    <h2>8. Make an inference request</h2>
    <p>
      The customer <code>sk-</code> key calls the inference gateway. The <code>sk-spm-</code> management
      key cannot make model requests.
    </p>
    <SpCodeBlock filename="bash" :code="inferenceExample" />

    <h2>Optional management operations</h2>

    <h3>Revoke a customer key</h3>
    <SpCodeBlock filename="bash" :code="revokeKeyExample" />

    <h3>Suspend a managed customer</h3>
    <SpCodeBlock filename="bash" :code="suspendExample" />

    <h2>What remains intentionally available</h2>
    <ul>
      <li><code>GET /customers/{id}/usage</code> remains available for reseller automation/reporting even though the website customer page no longer shows the usage block.</li>
      <li>The old single-model allocation format remains accepted for backward compatibility, but new integrations should use <code>inventory_lot_id</code>.</li>
      <li>Admin/manual stock-grant tools remain available for support and recovery; normal reseller purchases do not need them.</li>
    </ul>

    <h2>Important behavior</h2>
    <ul>
      <li>Paid package units become reseller stock automatically for an active reseller.</li>
      <li>Promotion, referral, redeem-code and Playground balances do not become reseller stock.</li>
      <li>Package allocations inherit the source package expiry.</li>
      <li>Allocation is idempotent; reuse the same idempotency key only when retrying the same sale.</li>
      <li>There is no automatic un-allocation/refund route after quota has been transferred.</li>
    </ul>
  </SpDocsShell>
</template>
