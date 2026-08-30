<script setup lang="ts">
import type { MoneyAmount } from '~/types/commerce'
import type { PublicApiKeyStatus } from '~/types/api'
import { formatMoney, formatUnits } from '~/utils/format'

definePageMeta({ layout: 'default' })
useSeoMeta({
  title: 'API key checker',
  description: 'Check an SP Cambo API key status, expiry, remaining usage and recent metering without signing in.',
  robots: 'noindex'
})

const api = useSpApi()
const toast = useToast()
const keyForm = ref({ apiKey: '' })
const checking = ref(false)
const showKey = ref(false)
const keyStatus = ref<PublicApiKeyStatus | null>(null)

// Kept only in page memory for optional live refresh. It is never put in a URL,
// localStorage, sessionStorage or a cookie and disappears when this page closes.
const sessionSecret = ref('')
const autoRefresh = ref(false)
const lastRefreshedAt = ref<Date | null>(null)
const liveClock = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | null = null
let refreshTimer: ReturnType<typeof setInterval> | null = null

const performCheck = async (secret: string, clearCurrent = true) => {
  if (checking.value || !secret) return
  checking.value = true
  if (clearCurrent) keyStatus.value = null

  try {
    keyStatus.value = await api.checkApiKey({ api_key: secret })
    sessionSecret.value = secret
    keyForm.value.apiKey = ''
    showKey.value = false
    lastRefreshedAt.value = new Date()
    autoRefresh.value = false
  } catch (error) {
    const spError = toSpApiError(error)
    keyStatus.value = { valid: false, error: spError.message }
    if (clearCurrent) sessionSecret.value = ''
  } finally {
    checking.value = false
  }
}

const checkKey = async () => {
  const secret = keyForm.value.apiKey.trim()
  if (!secret) {
    toast.add({ title: 'Enter your API key', description: 'Paste the SP Cambo key you want to check.', color: 'warning', icon: 'i-lucide-key-round' })
    return
  }
  await performCheck(secret)
}

const refreshNow = async () => {
  if (sessionSecret.value) await performCheck(sessionSecret.value, false)
}

const stopTimer = () => {
  if (refreshTimer) clearInterval(refreshTimer)
  refreshTimer = null
}

watch([autoRefresh, sessionSecret], ([enabled, secret]) => {
  if (!import.meta.client) return
  stopTimer()
  if (enabled && secret) {
    refreshTimer = setInterval(() => { void refreshNow() }, 15_000)
  }
}, { immediate: true })

onMounted(() => { clockTimer = setInterval(() => { liveClock.value = Date.now() }, 1000) })
onBeforeUnmount(() => { stopTimer(); if (clockTimer) clearInterval(clockTimer) })

const clear = () => {
  keyForm.value.apiKey = ''
  sessionSecret.value = ''
  autoRefresh.value = false
  keyStatus.value = null
  lastRefreshedAt.value = null
  showKey.value = false
  stopTimer()
}

const displayStatus = computed(() => keyStatus.value?.status?.toUpperCase() || 'UNKNOWN')
const formatDate = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not available'
const formatTimeRemaining = (value?: string | null) => {
  if (!value) return 'No expiry'
  const remaining = new Date(value).getTime() - Date.now()
  if (!Number.isFinite(remaining) || remaining <= 0) return 'Expired'
  const minutes = Math.ceil(remaining / 60_000)
  const days = Math.floor(minutes / 1440)
  const hours = Math.floor((minutes % 1440) / 60)
  const mins = minutes % 60
  if (days > 0) return `${days}d ${hours}h`
  if (hours > 0) return `${hours}h ${mins}m`
  return `${mins}m`
}
const formatMoneySet = (single: MoneyAmount | null | undefined, grouped: MoneyAmount[] | undefined) => {
  if (single) return formatMoney(single)
  if (grouped?.length) return grouped.map(amount => formatMoney(amount)).join(' + ')
  return '—'
}
const runningStates = ['reserved', 'connecting', 'streaming']
const requestDuration = (request: NonNullable<PublicApiKeyStatus['recent_requests']>[number]) => {
  if (request.duration_ms !== null) return request.duration_ms < 1000 ? `${request.duration_ms} ms` : `${(request.duration_ms / 1000).toFixed(2)} s`
  if (!runningStates.includes(request.state)) return '—'
  const elapsed = Math.max(0, liveClock.value - new Date(request.time).getTime())
  return `${(elapsed / 1000).toFixed(1)} s live`
}
const statusColor = (status?: string) => {
  switch (status?.toLowerCase()) {
    case 'active': return 'success'
    case 'revoked': return 'error'
    case 'expired': return 'warning'
    case 'disabled': return 'warning'
    default: return 'neutral'
  }
}
</script>

<template>
  <div>
    <section class="sp-public-hero">
      <div class="sp-khmer-motif pointer-events-none absolute inset-0 opacity-[0.08]" aria-hidden="true" />
      <div class="sp-ambient-glow pointer-events-none absolute inset-x-0 -top-40 h-[34rem]" aria-hidden="true" />

      <UContainer class="relative py-14 sm:py-20 lg:py-24">
        <div class="mx-auto max-w-3xl text-center">
          <div class="mb-5 flex flex-wrap items-center justify-center gap-2">
            <UBadge color="neutral" variant="subtle" size="lg" class="rounded-full">
              <UIcon name="i-lucide-shield-check" class="mr-1 size-4" />
              Private POST check
            </UBadge>
            <span class="sp-khmer-chip">កម្ពុជា · Secure</span>
          </div>

          <h1 class="text-4xl font-semibold tracking-tight text-highlighted sm:text-5xl">
            Check your <span class="sp-gradient-text">SP Cambo API key</span>
          </h1>
          <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-muted sm:text-lg">
            Verify status, expiry, remaining quota, smart-reuse savings and recent metering without opening your dashboard.
            Your plaintext key is submitted in the request body — never in the URL.
          </p>
        </div>
      </UContainer>
    </section>

    <UContainer class="py-10 sm:py-14">
      <div class="mx-auto max-w-5xl space-y-8">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
          <UCard class="sp-premium-card sp-key-checker-card sp-app-card">
            <template #header>
              <div class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                  <UIcon name="i-lucide-key-round" class="size-5" />
                </div>
                <div>
                  <h2 class="font-semibold text-highlighted">Paste your key</h2>
                  <p class="mt-1 text-sm text-muted">The secret stays in this form only long enough to perform the check.</p>
                </div>
              </div>
            </template>

            <UForm :state="keyForm" class="space-y-5" @submit="checkKey">
              <UFormField label="SP Cambo API key" name="apiKey" required>
                <UInput
                  v-model="keyForm.apiKey"
                  :type="showKey ? 'text' : 'password'"
                  placeholder="spc_..."
                  size="xl"
                  class="w-full font-mono"
                  autocomplete="off"
                  spellcheck="false"
                  :disabled="checking"
                  :ui="{ trailing: 'pe-1' }"
                >
                  <template #trailing>
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="sm"
                      :icon="showKey ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                      :aria-label="showKey ? 'Hide key' : 'Show key'"
                      @click="showKey = !showKey"
                    />
                  </template>
                </UInput>
              </UFormField>

              <div class="flex flex-col gap-2 sm:flex-row">
                <UButton type="submit" size="lg" :loading="checking" icon="i-lucide-shield-check" class="sm:flex-1">
                  Check key status
                </UButton>
                <UButton type="button" size="lg" color="neutral" variant="subtle" :disabled="checking" @click="clear">
                  Clear
                </UButton>
              </div>
            </UForm>

            <div v-if="sessionSecret && keyStatus && !keyStatus.error" class="mt-5 flex flex-col gap-3 rounded-lg border border-default bg-elevated/35 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <USwitch v-model="autoRefresh" label="Live usage refresh every 15 seconds" />
                <p class="mt-1 text-xs text-muted">Key is held only in page memory. Last refreshed {{ lastRefreshedAt?.toLocaleTimeString() || '—' }}.</p>
              </div>
              <UButton color="neutral" variant="subtle" icon="i-lucide-refresh-cw" :loading="checking" @click="refreshNow">Refresh now</UButton>
            </div>
          </UCard>

          <div class="space-y-4">
            <div class="sp-premium-card rounded-xl border border-default p-5">
              <div class="sp-khmer-rule mb-4 !h-px !w-14" />
              <h2 class="font-semibold text-highlighted">Designed for safe checks</h2>
              <ul class="mt-4 space-y-3 text-sm text-muted">
                <li class="flex gap-2.5">
                  <UIcon name="i-lucide-check" class="mt-0.5 size-4 shrink-0 text-primary" />
                  No API key in browser URL or query parameters.
                </li>
                <li class="flex gap-2.5">
                  <UIcon name="i-lucide-check" class="mt-0.5 size-4 shrink-0 text-primary" />
                  No localStorage persistence for the plaintext secret.
                </li>
                <li class="flex gap-2.5">
                  <UIcon name="i-lucide-check" class="mt-0.5 size-4 shrink-0 text-primary" />
                  After a successful check, the input is cleared. Optional live refresh keeps the key only in this page's memory until you clear or close it.
                </li>
              </ul>
            </div>

            <UButton to="/login" color="neutral" variant="subtle" block trailing-icon="i-lucide-arrow-right">
              Manage keys in dashboard
            </UButton>
          </div>
        </div>

        <UAlert
          v-if="keyStatus?.error"
          role="alert"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="This key could not be verified"
          :description="keyStatus.error"
        />

        <template v-if="keyStatus && !keyStatus.error">
          <section class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <p class="text-xs font-semibold tracking-[0.16em] text-primary uppercase">Verification result</p>
                <h2 class="mt-1 text-2xl font-semibold text-highlighted">Key is {{ displayStatus.toLowerCase() }}</h2>
                <p class="mt-1 font-mono text-xs text-muted">{{ keyStatus.masked_key || 'Masked key unavailable' }}</p>
              </div>
              <UBadge :color="statusColor(keyStatus.status)" variant="subtle" size="lg">
                {{ displayStatus }}
              </UBadge>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
              <SpMetric label="Time remaining" icon="i-lucide-clock" tone="warning" :value="formatTimeRemaining(keyStatus.expires_at)" />
              <SpMetric label="Quota remaining" icon="i-lucide-hourglass" tone="info" :value="keyStatus.quota_remaining === null || keyStatus.quota_remaining === undefined ? 'No matching balance' : formatUnits(keyStatus.quota_remaining)" />
              <SpMetric label="Credit remaining" icon="i-lucide-wallet" tone="success" :value="keyStatus.credit_remaining || keyStatus.credit_balances?.length ? formatMoneySet(keyStatus.credit_remaining, keyStatus.credit_balances) : 'No matching balance'" />
              <SpMetric label="Credit charged" icon="i-lucide-chart-line" tone="warning" :value="formatMoneySet(keyStatus.total_spend, keyStatus.total_spend_by_currency)" />
              <SpMetric label="Live requests" icon="i-lucide-radio" tone="primary" :value="String(keyStatus.active_requests ?? 0)" />
            </div>
          </section>

          <div class="grid gap-6 lg:grid-cols-2">
            <UCard class="sp-premium-card sp-app-card">
              <template #header>
                <h3 class="font-semibold text-highlighted">Access details</h3>
              </template>

              <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div class="rounded-lg border border-default/70 bg-elevated/35 p-4">
                  <dt class="text-xs text-muted">Package</dt>
                  <dd class="mt-1 font-medium text-highlighted">{{ keyStatus.package || 'Not available' }}</dd>
                </div>
                <div class="rounded-lg border border-default/70 bg-elevated/35 p-4">
                  <dt class="text-xs text-muted">Funding source</dt>
                  <dd class="mt-1 font-medium text-highlighted">{{ keyStatus.funding_source === 'account' ? 'Account balance' : keyStatus.funding_source === 'dedicated_key' ? 'Dedicated key balance' : keyStatus.funding_source === 'mixed' ? 'Account + dedicated balance' : 'No matching balance' }}</dd>
                </div>
                <div class="rounded-lg border border-default/70 bg-elevated/35 p-4">
                  <dt class="text-xs text-muted">Created</dt>
                  <dd class="mt-1 font-medium text-highlighted">{{ formatDate(keyStatus.created_at) }}</dd>
                </div>
                <div class="rounded-lg border border-default/70 bg-elevated/35 p-4 sm:col-span-2">
                  <dt class="text-xs text-muted">Expires</dt>
                  <dd class="mt-1 font-medium text-highlighted">{{ formatDate(keyStatus.expires_at) }}</dd>
                </div>
                <div class="rounded-lg border border-default/70 bg-elevated/35 p-4 sm:col-span-2">
                  <dt class="text-xs text-muted">Allowed public models</dt>
                  <dd class="mt-2 flex flex-wrap gap-2">
                    <UBadge v-for="model in (keyStatus.allowed_models || [])" :key="model" color="neutral" variant="subtle">
                      {{ model }}
                    </UBadge>
                    <span v-if="!keyStatus.allowed_models?.length" class="text-muted">No models returned</span>
                  </dd>
                </div>
              </dl>
              <p v-if="keyStatus.funding_note" class="mt-4 rounded-lg border border-info/20 bg-info/5 p-3 text-xs leading-5 text-muted">{{ keyStatus.funding_note }}</p>
            </UCard>

            <UCard class="sp-premium-card sp-app-card">
              <template #header>
                <div>
                  <h3 class="font-semibold text-highlighted">Usage & smart savings</h3>
                  <p class="mt-1 text-sm text-muted">Repeated context is recognized by SP Cambo locally and can reduce the Tokens charged.</p>
                </div>
              </template>

              <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                  <dt class="text-xs text-muted">Input</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-success">{{ formatUnits(keyStatus.tokens_used?.input ?? '0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Reused context</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-info">{{ formatUnits(keyStatus.tokens_used?.cached_input ?? '0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Output</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-error">{{ formatUnits(keyStatus.tokens_used?.output ?? '0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Saved by reuse</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-success">{{ formatUnits(keyStatus.tokens_used?.saved ?? '0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Charged Tokens</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-primary">{{ formatUnits(keyStatus.tokens_used?.billed ?? '0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Savings rate</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-success">{{ Number(keyStatus.tokens_used?.savings_rate_percent ?? 0).toFixed(1) }}%</dd>
                </div>
              </dl>

              <div class="mt-5 rounded-lg border border-success/20 bg-success/5 p-4">
                <div class="flex items-start gap-3">
                  <UIcon name="i-lucide-sparkles" class="mt-0.5 size-4 shrink-0 text-success" />
                  <div>
                    <p class="text-sm font-medium text-highlighted">Smart reuse is automatic</p>
                    <p class="mt-1 text-xs leading-5 text-muted">Savings are calculated only from SP Cambo's local request meter. Provider or OmniRoute usage counters do not control this key's customer balance.</p>
                  </div>
                </div>
              </div>

              <div class="mt-3 rounded-lg border border-default/70 bg-elevated/35 p-4">
                <p class="text-xs text-muted">Last used</p>
                <p class="mt-1 text-sm font-medium text-highlighted">{{ formatDate(keyStatus.last_used) }}</p>
              </div>
            </UCard>
          </div>

          <UCard v-if="keyStatus.recent_requests?.length" class="sp-premium-card sp-app-card">
            <template #header>
              <div>
                <h3 class="font-semibold text-highlighted">Recent requests</h3>
                <p class="mt-1 text-sm text-muted">SP Cambo meters requests locally. “Saved” is the Token amount actually avoided through smart reuse; provider cache and usage counters are never used.</p>
              </div>
            </template>

            <div class="sp-scroll-x">
              <table class="w-full min-w-[1040px] text-sm">
                <thead>
                  <tr class="border-b border-default text-left text-xs text-muted">
                    <th class="px-3 pb-3 font-medium">Time</th>
                    <th class="px-3 pb-3 font-medium">Status</th>
                    <th class="px-3 pb-3 font-medium">Model</th>
                    <th class="px-3 pb-3 text-right font-medium">In</th>
                    <th class="px-3 pb-3 text-right font-medium">Reused</th>
                    <th class="px-3 pb-3 text-right font-medium">Saved</th>
                    <th class="px-3 pb-3 text-right font-medium">Out</th>
                    <th class="px-3 pb-3 text-right font-medium">Charged</th>
                    <th class="px-3 pb-3 text-right font-medium">Duration</th>
                    <th class="px-3 pb-3 text-right font-medium">Wallet</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="request in keyStatus.recent_requests" :key="request.request_id" class="border-b border-default/60 last:border-0">
                    <td class="px-3 py-3 text-muted">{{ formatDate(request.time) }}</td>
                    <td class="px-3 py-3"><SpStatusBadge :status="request.state" size="sm" /></td>
                    <td class="px-3 py-3"><p class="font-mono text-default">{{ request.model }}</p><p class="max-w-56 truncate text-xs text-dimmed">{{ request.endpoint }}</p></td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-success">{{ request.input_tokens === null ? '—' : formatUnits(request.input_tokens) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-info">{{ request.cached_input_tokens === null || request.cached_input_tokens === '0' ? '—' : formatUnits(request.cached_input_tokens) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-success">{{ request.saved_tokens === null || request.saved_tokens === undefined || request.saved_tokens === '0' ? '—' : formatUnits(request.saved_tokens) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-error">{{ request.output_tokens === null ? '—' : formatUnits(request.output_tokens) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-primary">{{ request.billed_tokens ? formatUnits(request.billed_tokens) : request.reserved_units ? `${formatUnits(request.reserved_units)} reserved` : '—' }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-warning">{{ requestDuration(request) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-medium text-highlighted">{{ request.charge ? formatMoney(request.charge) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </UCard>

          <UCard v-else class="sp-premium-card sp-app-card">
            <div class="flex flex-col items-center py-6 text-center">
              <div class="mb-3 flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <UIcon name="i-lucide-activity" class="size-5" />
              </div>
              <p class="font-medium text-highlighted">No recent requests</p>
              <p class="mt-1 text-sm text-muted">Usage will appear after this key makes successful API calls.</p>
            </div>
          </UCard>
        </template>
      </div>
    </UContainer>
  </div>
</template>
