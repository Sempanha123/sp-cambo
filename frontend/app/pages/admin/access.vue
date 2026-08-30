<script setup lang="ts">
import type { AdminAccessApiKey } from '~/types/admin'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Customers & access', robots: 'noindex' })

const api = useSpApi()
const toast = useToast()
const { canManageAccess } = useSpPermissions()

const customers = await useSpResource('admin:access:customers', () => api.admin.accessCustomers({ limit: 100 }), { server: false })
const keys = await useSpResource('admin:access:keys', () => api.admin.accessApiKeys({ limit: 100 }), { server: false })
const entitlements = await useSpResource('admin:access:entitlements', () => api.admin.accessEntitlements({ limit: 100 }), { server: false })
const usage = await useSpResource('admin:access:usage', () => api.admin.accessUsage({ limit: 100 }), { server: false })
const aliases = await useSpResource('admin:access:aliases', () => canManageAccess.value ? api.admin.accessModelAliases() : Promise.resolve([]), { server: false })

const search = ref('')
const operatorReason = ref('Administrative access review and customer support action')
const busy = ref<string | null>(null)
const issuedSecret = ref<string | null>(null)

const issueUserId = ref('')
const issueLabel = ref('Support-issued API key')
const issueAliasIds = ref<number[]>([])
const issueExpiresAt = ref('')

const normalizedSearch = computed(() => search.value.trim().toLowerCase())
const matches = (...values: Array<string | null | undefined>) => !normalizedSearch.value || values.some(value => (value ?? '').toLowerCase().includes(normalizedSearch.value))

const filteredCustomers = computed(() => (customers.data.value ?? []).filter(item => matches(item.name, item.email, item.status, item.roles.join(' '))))
const filteredKeys = computed(() => (keys.data.value ?? []).filter(item => matches(item.user?.name, item.user?.email, item.label, item.masked_key, item.status, item.allowed_model_aliases.join(' '))))
const filteredEntitlements = computed(() => (entitlements.data.value ?? []).filter(item => matches(item.user?.name, item.user?.email, item.package_name, item.source_type, item.status, item.allowed_model_aliases.join(' '))))
const filteredUsage = computed(() => (usage.data.value ?? []).filter(item => matches(item.user?.name, item.user?.email, item.request_id, item.public_model, item.internal_model, item.provider, item.endpoint, item.state)))

const refreshAll = async () => {
  await Promise.all([customers.refresh(), keys.refresh(), entitlements.refresh(), usage.refresh(), aliases.refresh()])
}

const requireReason = () => {
  if (operatorReason.value.trim().length >= 10) return true
  toast.add({ title: 'Reason required', description: 'Write at least 10 characters for the immutable audit trail.', color: 'warning' })
  return false
}

const changeCustomerStatus = async (id: string, status: 'ACTIVE' | 'SUSPENDED' | 'DISABLED') => {
  if (!canManageAccess.value || !requireReason()) return
  busy.value = `customer:${id}`
  try {
    await api.admin.updateAccessCustomerStatus(id, { status, reason: operatorReason.value.trim() })
    toast.add({ title: 'Customer updated', description: `Account is now ${status}.`, color: 'success' })
    await customers.refresh()
  } catch (error) {
    toast.add({ title: 'Customer update failed', description: error instanceof Error ? error.message : 'The account could not be updated.', color: 'error' })
  } finally { busy.value = null }
}

const issueKey = async () => {
  if (!canManageAccess.value || !requireReason()) return
  const userId = Number(issueUserId.value)
  if (!Number.isSafeInteger(userId) || userId < 1 || issueAliasIds.value.length === 0 || !issueLabel.value.trim()) {
    toast.add({ title: 'Complete the key form', description: 'Choose a customer, label and at least one published model.', color: 'warning' })
    return
  }
  busy.value = 'issue-key'
  issuedSecret.value = null
  try {
    const result = await api.admin.issueAccessApiKey({
      user_id: userId,
      label: issueLabel.value.trim(),
      allowed_model_alias_ids: issueAliasIds.value,
      expires_at: issueExpiresAt.value ? new Date(issueExpiresAt.value).toISOString() : null,
      reason: operatorReason.value.trim()
    })
    issuedSecret.value = result.secret
    toast.add({ title: 'API key issued', description: 'Copy the secret now. It cannot be revealed again.', color: 'success' })
    await keys.refresh()
  } catch (error) {
    toast.add({ title: 'API key not issued', description: error instanceof Error ? error.message : 'The credential could not be issued.', color: 'error' })
  } finally { busy.value = null }
}

const changeKeyStatus = async (key: AdminAccessApiKey, status: 'ACTIVE' | 'DISABLED' | 'REVOKED') => {
  if (!canManageAccess.value || !requireReason()) return
  busy.value = `key:${key.id}`
  try {
    await api.admin.updateAccessApiKeyStatus(key.id, { status, reason: operatorReason.value.trim() })
    toast.add({ title: 'API key updated', description: `${key.masked_key} is now ${status}.`, color: 'success' })
    await keys.refresh()
  } catch (error) {
    toast.add({ title: 'API key update failed', description: error instanceof Error ? error.message : 'The credential could not be updated.', color: 'error' })
  } finally { busy.value = null }
}

const expireEntitlement = async (id: string) => {
  if (!canManageAccess.value || !requireReason()) return
  busy.value = `entitlement:${id}`
  try {
    await api.admin.expireAccessEntitlement(id, operatorReason.value.trim())
    toast.add({ title: 'Entitlement expired', description: 'Remaining units were preserved for audit history and access is blocked.', color: 'success' })
    await entitlements.refresh()
  } catch (error) {
    toast.add({ title: 'Entitlement not expired', description: error instanceof Error ? error.message : 'The entitlement could not be updated.', color: 'error' })
  } finally { busy.value = null }
}

const date = (value: string | null | undefined) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const number = (value: string | number | null | undefined) => value === null || value === undefined ? '—' : new Intl.NumberFormat().format(Number(value))
const privateMoney = (minor: string | null, currency: string | null, exponent: number | null) => {
  if (minor === null) return '—'
  if (!currency || exponent === null) return minor
  return formatMoney({ minor, currency, exponent })
}
const privateMargin = (bps: number | null) => bps === null ? '—' : `${(bps / 100).toFixed(2)}%`
const statusColor = (status: string) => status === 'ACTIVE' || status === 'SETTLED' ? 'success' : status === 'DISABLED' || status === 'SUSPENDED' || status === 'EXPIRED' || status === 'RECONCILING' ? 'warning' : status === 'REVOKED' || status === 'FAILED' ? 'error' : 'neutral'
</script>

<template>
  <SpDashboardPage
    title="Customers & access"
    icon="i-lucide-users-round"
    description="Customer accounts, one-time API-key issuance, entitlement controls and request metering. Mutations require access-manage permission and an operator reason."
  >
    <template #actions>
      <UButton color="neutral" variant="subtle" icon="i-lucide-refresh-cw" @click="refreshAll">Refresh</UButton>
    </template>

    <div class="space-y-6">
      <UCard class="sp-app-card">
        <div class="grid gap-4 lg:grid-cols-[1fr_2fr]">
          <UFormField label="Search current results">
            <UInput v-model="search" icon="i-lucide-search" placeholder="Customer, key, model, request…" />
          </UFormField>
          <UFormField label="Operator reason" help="Required for every write below and stored in the immutable audit log.">
            <UInput v-model="operatorReason" placeholder="Why is this access change required?" />
          </UFormField>
        </div>
        <UAlert v-if="!canManageAccess" class="mt-4" color="warning" variant="subtle" icon="i-lucide-lock" title="Read-only access" description="Your account can inspect administrative state but does not have access.manage permission for access changes." />
      </UCard>

      <SpAsyncSection :loading="customers.initialLoading.value" :unavailable="customers.unavailable.value" :failed="customers.failed.value" :error-message="customers.error.value?.message" error-title="Customers could not be loaded" @retry="customers.refresh()">
        <UCard class="sp-app-card">
          <template #header><div><p class="font-semibold text-highlighted">Customers</p><p class="text-sm text-muted">Latest 100 accounts. Suspended/disabled accounts are rejected by both web access and inference preflight.</p></div></template>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
              <thead class="text-xs uppercase text-muted"><tr><th class="pb-3">Customer</th><th class="pb-3">Status</th><th class="pb-3">Roles</th><th class="pb-3">Keys</th><th class="pb-3">Entitlements</th><th class="pb-3">Orders</th><th class="pb-3 text-right">Actions</th></tr></thead>
              <tbody class="divide-y divide-default">
                <tr v-for="customer in filteredCustomers" :key="customer.id">
                  <td class="py-3"><p class="font-medium text-highlighted">{{ customer.name }}</p><p class="text-xs text-muted">{{ customer.email }}</p></td>
                  <td class="py-3"><UBadge :color="statusColor(customer.status)" variant="subtle">{{ customer.status }}</UBadge></td>
                  <td class="py-3 text-muted">{{ customer.roles.join(', ') || 'Customer' }}</td>
                  <td class="py-3">{{ customer.api_keys_count }}</td><td class="py-3">{{ customer.entitlements_count }}</td><td class="py-3">{{ customer.orders_count }}</td>
                  <td class="py-3"><div class="flex justify-end gap-2"><UButton v-if="customer.status !== 'ACTIVE'" size="xs" variant="soft" :disabled="!canManageAccess" :loading="busy === `customer:${customer.id}`" @click="changeCustomerStatus(customer.id, 'ACTIVE')">Activate</UButton><UButton v-if="customer.status === 'ACTIVE'" size="xs" color="warning" variant="soft" :disabled="!canManageAccess" :loading="busy === `customer:${customer.id}`" @click="changeCustomerStatus(customer.id, 'SUSPENDED')">Suspend</UButton><UButton v-if="customer.status !== 'DISABLED'" size="xs" color="error" variant="soft" :disabled="!canManageAccess" :loading="busy === `customer:${customer.id}`" @click="changeCustomerStatus(customer.id, 'DISABLED')">Disable</UButton></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </UCard>
      </SpAsyncSection>

      <UCard v-if="canManageAccess" class="sp-app-card">
        <template #header><div><p class="font-semibold text-highlighted">Issue customer API key</p><p class="text-sm text-muted">The plaintext key is returned once only. Select at least one currently published public alias.</p></div></template>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <UFormField label="Customer">
            <select v-model="issueUserId" class="h-9 w-full rounded-md border border-default bg-default px-3 text-sm text-highlighted">
              <option value="" disabled>Select customer</option>
              <option v-for="customer in customers.data.value ?? []" :key="customer.id" :value="customer.id">{{ customer.email }}</option>
            </select>
          </UFormField>
          <UFormField label="Label"><UInput v-model="issueLabel" /></UFormField>
          <UFormField label="Expires at" help="Optional"><UInput v-model="issueExpiresAt" type="datetime-local" /></UFormField>
          <div class="flex items-end"><UButton icon="i-lucide-key-round" :loading="busy === 'issue-key'" @click="issueKey">Issue key</UButton></div>
        </div>
        <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
          <label v-for="alias in aliases.data.value ?? []" :key="alias.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-default p-3 text-sm">
            <input v-model="issueAliasIds" type="checkbox" :value="Number(alias.id)">
            <span><strong class="text-highlighted">{{ alias.public_alias }}</strong><span class="ml-2 text-muted">{{ alias.display_name }}</span></span>
          </label>
        </div>
        <UAlert v-if="issuedSecret" class="mt-4" color="success" variant="subtle" icon="i-lucide-key" title="Copy this secret now" description="SP Cambo stores only a keyed digest and cannot show this plaintext credential again." />
        <SpCodeBlock v-if="issuedSecret" class="mt-3" filename="one-time API key" :code="issuedSecret" />
      </UCard>

      <SpAsyncSection :loading="keys.initialLoading.value" :unavailable="keys.unavailable.value" :failed="keys.failed.value" :error-message="keys.error.value?.message" error-title="API keys could not be loaded" @retry="keys.refresh()">
        <UCard class="sp-app-card">
          <template #header><div><p class="font-semibold text-highlighted">API keys</p><p class="text-sm text-muted">Masked only. Revocation is permanent; disabling can be reversed.</p></div></template>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[1050px] text-left text-sm">
              <thead class="text-xs uppercase text-muted"><tr><th class="pb-3">Key</th><th class="pb-3">Customer</th><th class="pb-3">Status</th><th class="pb-3">Models</th><th class="pb-3">Last used</th><th class="pb-3">Expires</th><th class="pb-3 text-right">Actions</th></tr></thead>
              <tbody class="divide-y divide-default"><tr v-for="key in filteredKeys" :key="key.id"><td class="py-3"><p class="font-mono text-xs text-highlighted">{{ key.masked_key }}</p><p class="text-xs text-muted">{{ key.label }}</p></td><td class="py-3"><p>{{ key.user?.name ?? '—' }}</p><p class="text-xs text-muted">{{ key.user?.email }}</p></td><td class="py-3"><UBadge :color="statusColor(key.status)" variant="subtle">{{ key.status }}</UBadge></td><td class="py-3 text-xs text-muted">{{ key.allowed_model_aliases.join(', ') }}</td><td class="py-3 text-xs">{{ date(key.last_used_at) }}</td><td class="py-3 text-xs">{{ date(key.expires_at) }}</td><td class="py-3"><div class="flex justify-end gap-2"><UButton v-if="key.stored_status === 'DISABLED'" size="xs" variant="soft" :disabled="!canManageAccess" :loading="busy === `key:${key.id}`" @click="changeKeyStatus(key, 'ACTIVE')">Enable</UButton><UButton v-if="key.stored_status === 'ACTIVE'" size="xs" color="warning" variant="soft" :disabled="!canManageAccess" :loading="busy === `key:${key.id}`" @click="changeKeyStatus(key, 'DISABLED')">Disable</UButton><UButton v-if="key.stored_status !== 'REVOKED'" size="xs" color="error" variant="soft" :disabled="!canManageAccess" :loading="busy === `key:${key.id}`" @click="changeKeyStatus(key, 'REVOKED')">Revoke</UButton></div></td></tr></tbody>
            </table>
          </div>
        </UCard>
      </SpAsyncSection>

      <SpAsyncSection :loading="entitlements.initialLoading.value" :unavailable="entitlements.unavailable.value" :failed="entitlements.failed.value" :error-message="entitlements.error.value?.message" error-title="Entitlements could not be loaded" @retry="entitlements.refresh()">
        <UCard class="sp-app-card">
          <template #header><div><p class="font-semibold text-highlighted">Entitlements</p><p class="text-sm text-muted">Balances and reservations are preserved. Expiry is blocked while units are reserved by active inference.</p></div></template>
          <div class="overflow-x-auto"><table class="w-full min-w-[1100px] text-left text-sm"><thead class="text-xs uppercase text-muted"><tr><th class="pb-3">Customer</th><th class="pb-3">Package / source</th><th class="pb-3">Mode</th><th class="pb-3">Remaining</th><th class="pb-3">Reserved</th><th class="pb-3">Status</th><th class="pb-3">Expires</th><th class="pb-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-default"><tr v-for="lot in filteredEntitlements" :key="lot.id"><td class="py-3"><p>{{ lot.user?.name ?? '—' }}</p><p class="text-xs text-muted">{{ lot.user?.email }}</p></td><td class="py-3"><p class="font-medium text-highlighted">{{ lot.package_name }}</p><p class="text-xs text-muted">{{ lot.source_type }}</p></td><td class="py-3">{{ lot.billing_mode }}</td><td class="py-3">{{ number(lot.remaining_units) }} {{ lot.unit_label }}</td><td class="py-3">{{ number(lot.reserved_units) }}</td><td class="py-3"><UBadge :color="statusColor(lot.status)" variant="subtle">{{ lot.status }}</UBadge></td><td class="py-3 text-xs">{{ date(lot.expires_at) }}</td><td class="py-3 text-right"><UButton v-if="lot.status === 'ACTIVE'" size="xs" color="error" variant="soft" :disabled="!canManageAccess || Number(lot.reserved_units) > 0" :loading="busy === `entitlement:${lot.id}`" @click="expireEntitlement(lot.id)">Expire</UButton></td></tr></tbody></table></div>
        </UCard>
      </SpAsyncSection>

      <SpAsyncSection :loading="usage.initialLoading.value" :unavailable="usage.unavailable.value" :failed="usage.failed.value" :error-message="usage.error.value?.message" error-title="Usage metering could not be loaded" @retry="usage.refresh()">
        <UCard class="sp-app-card">
          <template #header><div><p class="font-semibold text-highlighted">Usage, metering & private economics</p><p class="text-sm text-muted">Latest 100 requests. SP reference-cost estimates and estimated profit are operator-only and are never returned by customer APIs.</p></div></template>
          <div class="overflow-x-auto"><table class="w-full min-w-[1650px] text-left text-sm"><thead class="text-xs uppercase text-muted"><tr><th class="pb-3">Request</th><th class="pb-3">Customer</th><th class="pb-3">State</th><th class="pb-3">Route</th><th class="pb-3">Input / output</th><th class="pb-3">Metered</th><th class="pb-3">Customer charge</th><th class="pb-3">SP reference cost</th><th class="pb-3">Gross profit</th><th class="pb-3">Margin</th><th class="pb-3">Duration</th><th class="pb-3">Started</th></tr></thead><tbody class="divide-y divide-default"><tr v-for="row in filteredUsage" :key="row.request_id"><td class="py-3"><p class="max-w-40 truncate font-mono text-xs text-highlighted" :title="row.request_id">{{ row.request_id }}</p><p class="text-xs text-muted">{{ row.endpoint }}</p></td><td class="py-3"><p>{{ row.user?.name ?? '—' }}</p><p class="text-xs text-muted">{{ row.user?.email }}</p></td><td class="py-3"><UBadge :color="statusColor(row.state)" variant="subtle">{{ row.state }}</UBadge><p v-if="row.error_code" class="mt-1 text-xs" :class="row.state === 'RECONCILING' ? 'text-warning' : 'text-error'">{{ row.error_code }}</p></td><td class="py-3"><p class="font-medium">{{ row.public_model }}</p><p class="text-xs text-muted">{{ row.provider ?? '—' }} · {{ row.internal_model ?? '—' }} · v{{ row.route_version ?? '—' }}</p></td><td class="py-3">{{ number(row.input_tokens) }} / {{ number(row.output_tokens) }}</td><td class="py-3"><p>{{ number(row.metered_units ?? row.estimated_units) }}</p><p class="text-xs text-muted">{{ row.metered_units ? 'final' : 'reserved estimate' }}</p></td><td class="py-3">{{ privateMoney(row.credit_charge_minor, row.currency, row.currency_exponent) }}</td><td class="py-3 font-medium text-highlighted">{{ privateMoney(row.upstream_cost_minor, row.currency, row.currency_exponent) }}</td><td class="py-3 font-medium" :class="row.gross_profit_minor !== null && Number(row.gross_profit_minor) < 0 ? 'text-error' : 'text-success'">{{ privateMoney(row.gross_profit_minor, row.currency, row.currency_exponent) }}</td><td class="py-3">{{ privateMargin(row.gross_margin_bps) }}</td><td class="py-3">{{ row.duration_ms === null ? (['RESERVED', 'CONNECTING', 'STREAMING'].includes(row.state) ? 'running' : '—') : `${number(row.duration_ms)} ms` }}</td><td class="py-3 text-xs">{{ date(row.started_at) }}</td></tr></tbody></table></div>
        </UCard>
      </SpAsyncSection>
    </div>
  </SpDashboardPage>
</template>
