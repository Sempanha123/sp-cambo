<script setup lang="ts">
import type { ApiKeyCreated, ApiKeyDetails, ApiKeyUsageSummary, RequestActivity } from '~/types/commerce'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'API key details', robots: 'noindex' })

const api = useSpApi()
const route = useRoute()
const toast = useToast()
const keyId = String(route.params.id ?? '')

const details = await useSpResource<ApiKeyDetails>(
  `dashboard:api-key:${keyId}`,
  () => api.account.apiKeyDetails(keyId),
  { server: false, immediate: false }
)
const detailsBooting = ref(true)
const funding = await useSpResource<Pick<ApiKeyDetails, 'balance_source' | 'token_quota_remaining' | 'credit_balances' | 'funding' | 'funding_status' | 'funding_message' | 'funding_diagnostic_id' | 'server_time'>>(
  `dashboard:api-key:${keyId}:funding`,
  () => api.account.apiKeyFunding(keyId),
  { server: false, immediate: false }
)
// Keep the first paint to one request. Usage/activity can be expensive on a
// large ledger, so load them after the key card is already interactive.
const activity = await useSpResource<RequestActivity[]>(
  `dashboard:api-key:${keyId}:activity`,
  () => api.account.activity({ key_id: keyId, limit: 50 }),
  { server: false, immediate: false }
)
const usage = await useSpResource<ApiKeyUsageSummary>(
  `dashboard:api-key:${keyId}:usage-summary`,
  () => api.account.apiKeyUsageSummary(keyId, { bucket: 'day' }),
  { server: false, immediate: false }
)
const usageLoaded = ref(false)
const activityLoaded = ref(false)
const loadUsage = async () => {
  if (usage.loading.value) return
  await usage.refresh()
  usageLoaded.value = true
}
const loadActivity = async () => {
  if (activity.loading.value) return
  await activity.refresh()
  activityLoaded.value = true
}

const activeTab = ref<'overview' | 'models' | 'activity' | 'setup'>('overview')
const tabs = [
  { value: 'overview' as const, label: 'Overview', icon: 'i-lucide-layout-dashboard' },
  { value: 'models' as const, label: 'Models & balance', icon: 'i-lucide-brain' },
  { value: 'activity' as const, label: 'Activity', icon: 'i-lucide-chart-line' },
  { value: 'setup' as const, label: 'Setup', icon: 'i-lucide-terminal' }
]

const key = computed(() => details.data.value?.key ?? null)
const tokenRemaining = computed(() => Number(details.data.value?.token_quota_remaining ?? 0))
const fundingLots = computed(() => details.data.value?.funding ?? [])
const daysRemaining = computed(() => {
  const expires = key.value?.expires_at
  if (!expires) return null
  return Math.max(0, Math.ceil((new Date(expires).getTime() - Date.now()) / 86_400_000))
})

const revealOpen = ref(false)
const secret = ref<string | null>(null)
const rotating = ref(false)
const revealing = ref(false)
const replaceOpen = ref(false)

const showSecret = (result: ApiKeyCreated) => {
  secret.value = result.secret
  revealOpen.value = true
}

const copyKey = async () => {
  if (!key.value) return
  if (!key.value.secret_recopy_available) {
    replaceOpen.value = true
    return
  }
  revealing.value = true
  try {
    showSecret(await api.account.revealApiKey(key.value.id))
  } catch (cause) {
    const error = toSpApiError(cause)
    toast.add({ title: 'Key could not be copied', description: error.message, color: 'error' })
  } finally {
    revealing.value = false
  }
}

const replaceLegacyKey = async () => {
  if (!key.value) return
  rotating.value = true
  try {
    showSecret(await api.account.rotateApiKey(key.value.id))
    replaceOpen.value = false
    await details.refresh()
    toast.add({
      title: 'Secure re-copy enabled',
      description: 'The old unrecoverable secret was replaced. Update any app still using the previous secret.',
      color: 'success'
    })
  } catch (cause) {
    toast.add({ title: 'Key could not be replaced', description: toSpApiError(cause).message, color: 'error' })
  } finally {
    rotating.value = false
  }
}

watch(revealOpen, (open) => {
  if (!open) secret.value = null
})

watch(activeTab, (tab) => {
  if (tab === 'activity') {
    if (!usageLoaded.value) void loadUsage()
    if (!activityLoaded.value) void loadActivity()
  }
})

const mergeFunding = () => {
  if (!details.data.value || !funding.data.value) return
  details.data.value = { ...details.data.value, ...funding.data.value }
}

const loadFunding = async () => {
  await funding.refresh()
  if (funding.data.value) {
    mergeFunding()
    return
  }
  if (details.data.value && funding.error.value) {
    details.data.value = {
      ...details.data.value,
      funding_status: 'unavailable',
      funding_message: funding.error.value.message
    }
  }
}

onMounted(async () => {
  // Render the route shell immediately, then load the lightweight credential
  // identity. Funding and 30-day usage are deliberately detached so neither can
  // make navigation appear frozen.
  try {
    await details.refresh()
  } finally {
    detailsBooting.value = false
  }

  if (!details.data.value) return

  void loadFunding()
  if (typeof window !== 'undefined') {
    const idle = (window as unknown as { requestIdleCallback?: (callback: () => void, options?: { timeout: number }) => number }).requestIdleCallback
    if (idle) {
      idle(() => void loadUsage(), { timeout: 2000 })
      return
    }
  }
  setTimeout(() => void loadUsage(), 750)
})

const balanceSourceLabel = computed(() => {
  const source = details.data.value?.balance_source
  if (source === 'loading') return 'Loading balance…'
  if (source === 'dedicated_and_legacy_entitlements') return 'Account + dedicated key balance'
  if (source === 'legacy_account_entitlements') return 'Account-wide balance'
  return 'No matching spendable balance'
})

const creditLabel = computed(() => {
  if (details.data.value?.funding_status === 'deferred') return 'Loading…'
  const rows = details.data.value?.credit_balances ?? []
  return rows.length ? rows.map(formatMoney).join(' + ') : 'No credit balance for this key scope'
})

const fundingForModel = (alias: string) => fundingLots.value.filter(lot => lot.allowed_model_aliases.includes(alias))

const copyRevealed = async () => {
  if (!secret.value) return
  await navigator.clipboard.writeText(secret.value)
  toast.add({ title: 'API key copied', color: 'success' })
}
</script>

<template>
  <SpDashboardPage
    title="API key details"
    icon="i-lucide-key-round"
    description="One key, one clear view: model scope, balance assigned to this key, expiry, usage and setup."
  >
    <template #actions>
      <UButton to="/dashboard/api-keys" color="neutral" variant="ghost" icon="i-lucide-arrow-left">
        All keys
      </UButton>
      <UButton
        v-if="key"
        :icon="key.secret_recopy_available ? 'i-lucide-copy' : 'i-lucide-refresh-cw'"
        :loading="revealing || rotating"
        :disabled="key.status === 'REVOKED'"
        @click="copyKey"
      >
        {{ key.secret_recopy_available ? 'Copy key' : 'Replace old key' }}
      </UButton>
    </template>

    <SpAsyncSection
      :loading="detailsBooting || details.initialLoading.value"
      :failed="details.failed.value"
      :unavailable="details.unavailable.value"
      :error-message="details.error.value?.message"
      loading-variant="cards"
      @retry="details.refresh()"
    >
      <div v-if="key && details.data.value" class="space-y-5">
        <UAlert
          v-if="details.data.value.funding_status === 'unavailable'"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Key loaded; balance summary needs attention"
          :description="`${details.data.value.funding_message || 'Balance summary is temporarily unavailable.'}${details.data.value.funding_diagnostic_id ? ` Reference: ${details.data.value.funding_diagnostic_id}` : ''}`"
        />
        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-wallet-cards"
          title="How this API key is funded"
          description="API keys do not own a new balance when they are created. They can spend matching account-wide purchased/redeemed lots, plus any lot explicitly dedicated to this key. Model scope is permission only; it does not create quota."
        />
        <div class="rounded-xl border border-default bg-elevated/35 p-5">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-2">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-highlighted">{{ key.label }}</h2>
                <SpStatusBadge :status="key.status.toLowerCase()" />
                <UBadge :color="key.secret_recopy_available ? 'success' : 'warning'" variant="subtle">
                  {{ key.secret_recopy_available ? 'Re-copy ready' : 'Legacy secret' }}
                </UBadge>
              </div>
              <code class="font-mono text-sm text-muted">{{ maskApiKey(key.prefix, key.last_four) }}</code>
              <p class="text-xs text-muted">
                The key is a credential. A normal API key spends matching account-wide purchased/redeemed balance plus any package dedicated to this key. Creating a key does not create tokens or credit. Playground daily quota and another key's dedicated packages are never shared.
              </p>
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
              <div class="rounded-lg border border-default px-3 py-2">
                <p class="text-xs text-muted">Purchased tokens</p>
                <strong class="sp-numeric text-info">{{ details.data.value?.funding_status === 'deferred' ? 'Loading…' : (fundingLots.length ? formatUnits(tokenRemaining) : 'No matching balance') }}</strong>
              </div>
              <div class="rounded-lg border border-default px-3 py-2">
                <p class="text-xs text-muted">Credit</p>
                <strong class="text-xs text-success">{{ creditLabel }}</strong>
              </div>
              <div class="rounded-lg border border-default px-3 py-2">
                <p class="text-xs text-muted">Expires</p>
                <strong>{{ key.expires_at ? formatDate(key.expires_at) : 'No expiry' }}</strong>
              </div>
              <div class="rounded-lg border border-default px-3 py-2">
                <p class="text-xs text-muted">Remaining</p>
                <strong>{{ daysRemaining === null ? 'No limit' : `${daysRemaining} day${daysRemaining === 1 ? '' : 's'}` }}</strong>
              </div>
            </div>
          </div>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-default pb-3">
          <UButton
            v-for="tab in tabs"
            :key="tab.value"
            :icon="tab.icon"
            :color="activeTab === tab.value ? 'primary' : 'neutral'"
            :variant="activeTab === tab.value ? 'subtle' : 'ghost'"
            size="sm"
            @click="activeTab = tab.value"
          >
            {{ tab.label }}
          </UButton>
        </div>

        <div v-if="activeTab === 'overview'" class="grid gap-4 md:grid-cols-2">
          <UCard class="sp-app-card">
            <template #header><h3 class="font-semibold">Key information</h3></template>
            <dl class="space-y-3 text-sm">
              <div class="flex justify-between gap-4"><dt class="text-muted">Status</dt><dd><SpStatusBadge :status="key.status.toLowerCase()" /></dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">Created</dt><dd>{{ formatDateTime(key.created_at) }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">Last used</dt><dd>{{ key.last_used_at ? formatDateTime(key.last_used_at) : 'Never' }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">Balance source</dt><dd>{{ balanceSourceLabel }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">Model count</dt><dd>{{ key.allowed_model_aliases.length }}</dd></div>
            </dl>
          </UCard>
          <UCard class="sp-app-card">
            <template #header><h3 class="font-semibold">What this key can spend</h3></template>
            <p v-if="details.data.value?.funding_status === 'deferred'" class="text-sm text-muted">Loading matching account and dedicated-key balance…</p>
            <p v-else-if="fundingLots.length === 0" class="text-sm text-muted">
              This key currently has no dedicated purchased balance. Creating a key never creates tokens by itself. Buy a package and choose this key as the destination, or use a compatible legacy/redeemed account balance.
            </p>
            <div v-else class="space-y-2 text-sm">
              <p><strong>{{ formatUnits(tokenRemaining) }}</strong> token units are currently spendable across matching lots.</p>
              <p class="text-muted">Credit: {{ creditLabel }}</p>
              <NuxtLink to="/dashboard/entitlements" class="text-primary underline underline-offset-2">Open entitlement ledger</NuxtLink>
            </div>
          </UCard>
          <UCard class="md:col-span-2 sp-app-card">
            <template #header>
              <div class="flex items-center justify-between gap-3">
                <div>
                  <h3 class="font-semibold">Last 30 days on this key</h3>
                  <p class="text-xs text-muted">Only settled activity authenticated with this key is counted here.</p>
                </div>
                <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-refresh-cw" :loading="usage.loading.value" @click="loadUsage()">Refresh</UButton>
              </div>
            </template>
            <div v-if="usage.data.value" class="space-y-3">
              <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <div class="rounded-lg border border-default p-3"><p class="text-xs text-muted">Requests</p><strong class="sp-numeric">{{ usage.data.value.requests.toLocaleString() }}</strong></div>
                <div class="rounded-lg border border-default p-3"><p class="text-xs text-muted">Reused context</p><strong class="sp-numeric text-info">{{ formatUnits(usage.data.value.cached_input_tokens) }}</strong></div>
                <div class="rounded-lg border border-success/20 bg-success/5 p-3"><p class="text-xs text-muted">Saved by cache</p><strong class="sp-numeric text-success">{{ formatUnits(usage.data.value.saved_tokens) }}</strong></div>
                <div class="rounded-lg border border-default p-3"><p class="text-xs text-muted">Charged Tokens</p><strong class="sp-numeric text-primary">{{ formatUnits(usage.data.value.billed_tokens) }}</strong></div>
                <div class="rounded-lg border border-default p-3"><p class="text-xs text-muted">Savings rate</p><strong class="sp-numeric text-success">{{ Number(usage.data.value.savings_rate_percent).toFixed(1) }}%</strong></div>
                <div class="rounded-lg border border-default p-3"><p class="text-xs text-muted">Wallet charge</p><strong class="text-warning">{{ formatMoney(usage.data.value.credit_charge) }}</strong></div>
              </div>
              <p class="text-xs text-muted">Smart-reuse savings are calculated from SP Cambo's local meter only; provider and OmniRoute usage counters do not control customer billing.</p>
            </div>
            <p v-else class="text-sm text-muted">{{ usage.loading.value ? 'Loading usage in the background…' : 'No settled usage on this key in the selected period.' }}</p>
          </UCard>
        </div>

        <div v-else-if="activeTab === 'models'" class="space-y-4">
          <UAlert
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            title="Model scope does not create balance"
            description="A model is usable through this key only when it is in the key scope and this key has a dedicated matching package (or compatible legacy account-wide balance)."
          />
          <div v-if="key.allowed_model_aliases.length" class="grid gap-3 lg:grid-cols-2">
            <UCard v-for="alias in key.allowed_model_aliases" :key="alias" class="sp-app-card">
              <template #header>
                <div class="flex items-center justify-between gap-3">
                  <SpModelBadge :model="alias" :show-alias="true" />
                  <UBadge :color="fundingForModel(alias).length ? 'success' : 'neutral'" variant="subtle">
                    {{ fundingForModel(alias).length ? 'Funded' : 'No matching balance' }}
                  </UBadge>
                </div>
              </template>
              <div v-if="fundingForModel(alias).length" class="space-y-2">
                <div v-for="lot in fundingForModel(alias)" :key="lot.id" class="rounded-lg border border-default p-3 text-sm">
                  <div class="flex flex-wrap items-center justify-between gap-3"><div class="flex items-center gap-2"><strong>{{ lot.package_name }}</strong><UBadge :color="lot.dedicated_to_this_key ? 'success' : 'neutral'" variant="subtle" size="xs">{{ lot.dedicated_to_this_key ? 'Dedicated to this key' : 'Legacy shared balance' }}</UBadge></div><span>{{ lot.days_remaining === null ? 'No expiry' : `${lot.days_remaining}d left` }}</span></div>
                  <p class="mt-1 text-muted">
                    {{ lot.billing_mode === 'TOKEN_QUOTA' ? `${formatUnits(lot.remaining_units)} ${lot.unit_label} remaining` : 'Credit entitlement' }}
                  </p>
                  <p v-if="lot.expires_at" class="mt-1 text-xs text-dimmed">Expires {{ formatDateTime(lot.expires_at) }}</p>
                </div>
              </div>
              <p v-else class="text-sm text-muted">No active purchased/redeemed entitlement currently matches this model.</p>
            </UCard>
          </div>
          <p v-else class="rounded-lg border border-default p-5 text-sm text-muted">This key has no explicit model scope.</p>
        </div>

        <div v-else-if="activeTab === 'activity'" class="space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="font-semibold">Activity for this key only</h3>
              <p class="text-xs text-muted">No requests from your other keys are mixed into this view.</p>
            </div>
            <UButton color="neutral" variant="ghost" icon="i-lucide-refresh-cw" :loading="activity.loading.value" @click="loadActivity()">Refresh</UButton>
          </div>
          <div v-if="(usage.data.value?.by_model ?? []).length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="model in usage.data.value?.by_model ?? []" :key="model.public_model" class="rounded-lg border border-default bg-elevated/30 p-3 text-sm">
              <div class="flex items-center justify-between gap-2"><SpModelBadge :model="model.public_model" compact /><span class="sp-numeric text-muted">{{ model.requests }} req</span></div>
              <p class="mt-1 text-xs text-muted">{{ formatUnits(model.billed_tokens) }} charged · {{ formatUnits(model.saved_tokens) }} saved · {{ Number(model.savings_rate_percent).toFixed(1) }}% savings</p>
            </div>
          </div>
          <div v-if="(activity.data.value ?? []).length" class="overflow-x-auto rounded-lg border border-default">
            <table class="w-full text-sm">
              <thead><tr class="border-b border-default text-left text-muted"><th class="p-3">Time</th><th class="p-3">Model</th><th class="p-3">State</th><th class="p-3 text-right">Tokens</th><th class="p-3 text-right">Duration</th></tr></thead>
              <tbody>
                <tr v-for="row in activity.data.value ?? []" :key="row.id" class="border-b border-default/60 last:border-0">
                  <td class="p-3 whitespace-nowrap">{{ formatDateTime(row.started_at) }}</td>
                  <td class="p-3 font-mono">{{ row.public_model }}</td>
                  <td class="p-3"><SpStatusBadge :status="row.state" /></td>
                  <td class="p-3 text-right sp-numeric font-semibold text-primary">{{ row.total_tokens?.toLocaleString() ?? '—' }}</td>
                  <td class="p-3 text-right sp-numeric font-semibold text-warning">{{ row.duration_ms === null ? '—' : `${row.duration_ms.toLocaleString()} ms` }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="rounded-lg border border-default p-6 text-center text-sm text-muted">No usage recorded for this key yet.</p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2">
          <UCard class="sp-app-card">
            <template #header><h3 class="font-semibold">Use this key</h3></template>
            <p class="text-sm text-muted">Copy the key, then choose one of its allowed models in CLI Setup. Your purchased/redeemed entitlement is charged by actual settled usage.</p>
            <div class="mt-4 flex flex-wrap gap-2">
              <UButton :loading="revealing" icon="i-lucide-copy" @click="copyKey">{{ key.secret_recopy_available ? 'Copy key' : 'Replace old key' }}</UButton>
              <UButton :to="key.allowed_model_aliases[0] ? `/dashboard/cli-setup?model=${encodeURIComponent(key.allowed_model_aliases[0])}` : '/dashboard/cli-setup'" color="neutral" variant="subtle" icon="i-lucide-terminal">CLI setup</UButton>
            </div>
          </UCard>
          <UCard class="sp-app-card">
            <template #header><h3 class="font-semibold">Playground</h3></template>
            <p class="text-sm text-muted">Playground does not use this API key's dedicated package balance. Only purchases you explicitly allocate to Playground (plus compatible legacy/redeemed balance) appear there.</p>
            <UButton to="/dashboard/playground" class="mt-4" color="neutral" variant="subtle" icon="i-lucide-flask-conical">Open Playground</UButton>
          </UCard>
        </div>
      </div>
    </SpAsyncSection>

    <UModal v-model:open="replaceOpen" title="Replace this legacy secret?" description="The old secret cannot be reconstructed because it was created before encrypted re-copy support.">
      <template #body>
        <UAlert color="warning" variant="subtle" icon="i-lucide-triangle-alert" title="The current secret will stop working" description="SP Cambo will rotate this key in place. Its model scope and entitlement access stay the same, but apps using the previous secret must be updated." />
        <div class="mt-5 flex justify-end gap-2">
          <UButton color="neutral" variant="ghost" @click="replaceOpen = false">Cancel</UButton>
          <UButton :loading="rotating" icon="i-lucide-refresh-cw" @click="replaceLegacyKey">Replace and enable re-copy</UButton>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="revealOpen" title="Your SP Cambo API key" description="This key can be copied again later because the recovery copy is encrypted at rest.">
      <template #body>
        <div v-if="secret" class="space-y-4">
          <div class="rounded-lg border border-default bg-elevated/50 p-4 font-mono text-sm break-all">{{ secret }}</div>
          <UButton block icon="i-lucide-copy" @click="copyRevealed">Copy API key</UButton>
        </div>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
