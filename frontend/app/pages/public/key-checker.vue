<script setup lang="ts">
import type { MoneyAmount } from '~/types/commerce'
import type { PublicApiKeyStatus } from '~/types/api'
import { formatCompactUnits, formatMoney, formatUnits } from '~/utils/format'

definePageMeta({ layout: 'default' })
useSeoMeta({
  title: 'API key checker',
  description: 'Securely check an SP Cambo API key status, model access, limits, balance and recent metering without signing in.',
  robots: 'noindex, nofollow'
})

const CHECK_INTERVAL_MS = 15_000
const api = useSpApi()
const toast = useToast()
const keyForm = ref({ apiKey: '' })
const checking = ref(false)
const checkingMode = ref<'manual' | 'refresh'>('manual')
const showKey = ref(false)
const keyError = ref('')
const checkError = ref<{ title: string, description: string, retryable: boolean } | null>(null)
const refreshWarning = ref('')
const keyStatus = ref<PublicApiKeyStatus | null>(null)

// The secret exists only in this component's memory for optional live refresh.
// It is never copied into a URL, cookie or browser storage.
const sessionSecret = ref('')
const autoRefresh = ref(false)
const lastRefreshedAt = ref<Date | null>(null)
const liveClock = ref(Date.now())
const requestFilter = ref<'all' | 'live' | 'completed' | 'failed'>('all')
let clockTimer: ReturnType<typeof setInterval> | null = null
let refreshTimer: ReturnType<typeof setInterval> | null = null
let requestVersion = 0

const validateSecret = (value: string): string => {
  if (!value) return 'Enter your SP Cambo API key.'
  if (value.length < 10) return 'This key is too short to be an SP Cambo API key.'
  if (value.length > 255) return 'This key is longer than the supported key format.'
  if (/\s/.test(value)) return 'Remove spaces or line breaks inside the key.'
  if (!value.startsWith('sk-')) return 'SP Cambo API keys begin with “sk-”.'
  return ''
}

watch(() => keyForm.value.apiKey, () => {
  if (keyError.value) keyError.value = ''
  if (checkError.value) checkError.value = null
})

const errorPresentation = (error: unknown) => {
  const spError = toSpApiError(error)

  if (spError.code === 'not_found') {
    return {
      title: 'Key not recognized',
      description: 'Check that you copied the complete key. It may also have been rotated or revoked and replaced.',
      retryable: false
    }
  }
  if (spError.code === 'rate_limit_exceeded') {
    return {
      title: 'Too many checks',
      description: 'The checker allows 10 requests per minute. Wait a moment, then try again.',
      retryable: true
    }
  }
  if (spError.isValidation) {
    return {
      title: 'Key format was rejected',
      description: spError.fieldError('api_key') || spError.message,
      retryable: false
    }
  }

  return {
    title: spError.isUnavailable ? 'Checker temporarily unavailable' : 'Verification failed',
    description: spError.message,
    retryable: spError.retryable || spError.isUnavailable
  }
}

const performCheck = async (secret: string, mode: 'manual' | 'refresh') => {
  if (checking.value || !secret) return

  const version = ++requestVersion
  checking.value = true
  checkingMode.value = mode

  if (mode === 'manual') {
    keyStatus.value = null
    sessionSecret.value = ''
    autoRefresh.value = false
    checkError.value = null
    refreshWarning.value = ''
  }

  try {
    const response = await api.checkApiKey({ api_key: secret })
    if (version !== requestVersion) return

    keyStatus.value = response
    sessionSecret.value = secret
    autoRefresh.value = true
    lastRefreshedAt.value = new Date()
    refreshWarning.value = ''

    if (mode === 'manual') {
      keyForm.value.apiKey = ''
      showKey.value = false
      requestFilter.value = 'all'
    }
  } catch (error) {
    if (version !== requestVersion) return

    const presentation = errorPresentation(error)
    if (mode === 'refresh' && keyStatus.value) {
      refreshWarning.value = `${presentation.title}: ${presentation.description}`
      if (toSpApiError(error).code === 'rate_limit_exceeded') autoRefresh.value = false
    } else {
      checkError.value = presentation
      sessionSecret.value = ''
    }
  } finally {
    if (version === requestVersion) checking.value = false
  }
}

const checkKey = async () => {
  const secret = keyForm.value.apiKey.trim()
  keyError.value = validateSecret(secret)
  if (keyError.value) return
  await performCheck(secret, 'manual')
}

const pasteKey = async () => {
  try {
    if (!import.meta.client || !navigator.clipboard?.readText) throw new Error('Clipboard unavailable')
    keyForm.value.apiKey = (await navigator.clipboard.readText()).trim()
    showKey.value = false
    keyError.value = validateSecret(keyForm.value.apiKey)
  } catch {
    toast.add({
      title: 'Paste permission unavailable',
      description: 'Use Ctrl+V or Command+V inside the key field instead.',
      color: 'warning',
      icon: 'i-lucide-clipboard-paste'
    })
  }
}

const refreshNow = async () => {
  if (!sessionSecret.value || (import.meta.client && document.visibilityState === 'hidden')) return
  await performCheck(sessionSecret.value, 'refresh')
}

const stopRefreshTimer = () => {
  if (refreshTimer) clearInterval(refreshTimer)
  refreshTimer = null
}

watch([autoRefresh, sessionSecret], ([enabled, secret]) => {
  if (!import.meta.client) return
  stopRefreshTimer()
  if (enabled && secret) {
    refreshTimer = setInterval(() => {
      void refreshNow()
    }, CHECK_INTERVAL_MS)
  }
})

const clear = () => {
  requestVersion++
  checking.value = false
  keyForm.value.apiKey = ''
  keyError.value = ''
  checkError.value = null
  refreshWarning.value = ''
  sessionSecret.value = ''
  autoRefresh.value = false
  keyStatus.value = null
  lastRefreshedAt.value = null
  showKey.value = false
  requestFilter.value = 'all'
  stopRefreshTimer()
}

onMounted(() => {
  clockTimer = setInterval(() => {
    liveClock.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  requestVersion++
  stopRefreshTimer()
  if (clockTimer) clearInterval(clockTimer)
})

const displayStatus = computed(() => keyStatus.value?.status?.toUpperCase() || 'UNKNOWN')
const statusUi = computed(() => {
  switch (displayStatus.value) {
    case 'ACTIVE':
      return { key: 'active', color: 'success' as const, icon: 'i-lucide-circle-check-big', title: 'Ready to use', description: 'This credential is active. Requests still need an allowed model and spendable matching balance.' }
    case 'DISABLED':
      return { key: 'warning', color: 'warning' as const, icon: 'i-lucide-pause-circle', title: 'Key is disabled', description: 'The key exists but cannot make API requests until it is enabled from the dashboard.' }
    case 'EXPIRED':
      return { key: 'warning', color: 'warning' as const, icon: 'i-lucide-clock-alert', title: 'Key has expired', description: 'This key can no longer authenticate. Create or use another active key.' }
    case 'REVOKED':
      return { key: 'danger', color: 'error' as const, icon: 'i-lucide-ban', title: 'Key was revoked', description: 'Revocation is permanent. Replace this key anywhere it was configured.' }
    default:
      return { key: 'neutral', color: 'neutral' as const, icon: 'i-lucide-circle-help', title: 'Status unavailable', description: 'The server returned a status this checker does not recognize yet.' }
  }
})

const validDate = (value?: string | null): Date | null => {
  if (!value) return null
  const date = new Date(value)
  return Number.isFinite(date.getTime()) ? date : null
}

const formatDate = (value?: string | null) => validDate(value)?.toLocaleString() || 'Not available'
const formatTimeRemaining = (value?: string | null) => {
  const expiry = validDate(value)
  if (!expiry) return 'No expiry'

  const remaining = expiry.getTime() - liveClock.value
  if (remaining <= 0) return 'Expired'

  const minutes = Math.ceil(remaining / 60_000)
  const days = Math.floor(minutes / 1440)
  const hours = Math.floor((minutes % 1440) / 60)
  const mins = minutes % 60
  if (days > 0) return `${days}d ${hours}h`
  if (hours > 0) return `${hours}h ${mins}m`
  return `${mins}m`
}

const lastCheckedLabel = computed(() => {
  if (!lastRefreshedAt.value) return 'Not checked yet'
  const seconds = Math.max(0, Math.floor((liveClock.value - lastRefreshedAt.value.getTime()) / 1000))
  if (seconds < 5) return 'Checked just now'
  if (seconds < 60) return `Checked ${seconds}s ago`
  return `Checked ${Math.floor(seconds / 60)}m ago`
})

const formatMoneySet = (single: MoneyAmount | null | undefined, grouped: MoneyAmount[] | undefined) => {
  if (single) return formatMoney(single)
  if (grouped?.length) return grouped.map(amount => formatMoney(amount)).join(' + ')
  return '—'
}

const fundingLabel = computed(() => {
  switch (keyStatus.value?.funding_source) {
    case 'account': return 'Account balance'
    case 'dedicated_key': return 'Dedicated key balance'
    case 'mixed': return 'Account + dedicated balance'
    default: return 'No matching balance'
  }
})

const modelDetails = computed(() => {
  if (keyStatus.value?.model_details?.length) return keyStatus.value.model_details
  return (keyStatus.value?.allowed_models || []).map(alias => ({
    public_alias: alias,
    display_name: alias,
    status: 'ACTIVE',
    context_tokens: null,
    max_output_tokens: null,
    capability_basis: null,
    features: []
  }))
})

const formatLimit = (value: number | null | undefined) => value === null || value === undefined
  ? 'Package default'
  : formatUnits(value)

const formatBytes = (value: number | null | undefined) => {
  if (value === null || value === undefined) return 'Package default'
  if (value >= 1_048_576) return `${(value / 1_048_576).toFixed(value % 1_048_576 === 0 ? 0 : 1)} MB`
  if (value >= 1024) return `${(value / 1024).toFixed(value % 1024 === 0 ? 0 : 1)} KB`
  return `${value} B`
}

const runningStates = ['reserved', 'connecting', 'streaming']
const requestDuration = (request: NonNullable<PublicApiKeyStatus['recent_requests']>[number]) => {
  if (request.duration_ms !== null) return request.duration_ms < 1000 ? `${request.duration_ms} ms` : `${(request.duration_ms / 1000).toFixed(2)} s`
  if (!runningStates.includes(request.state)) return '—'
  const elapsed = Math.max(0, liveClock.value - new Date(request.time).getTime())
  return `${(elapsed / 1000).toFixed(1)} s live`
}

const filteredRequests = computed(() => {
  const requests = keyStatus.value?.recent_requests || []
  if (requestFilter.value === 'live') return requests.filter(request => runningStates.includes(request.state))
  if (requestFilter.value === 'completed') return requests.filter(request => request.status === 'success')
  if (requestFilter.value === 'failed') return requests.filter(request => request.status === 'error')
  return requests
})

const requestFilters = [
  { value: 'all' as const, label: 'All' },
  { value: 'live' as const, label: 'Live' },
  { value: 'completed' as const, label: 'Completed' },
  { value: 'failed' as const, label: 'Failed' }
]

const requestStatusCode = (request: NonNullable<PublicApiKeyStatus['recent_requests']>[number]) => {
  if (request.status === 'success') return '200'
  if (request.status === 'error') return request.error_code && request.error_code.toLowerCase().includes('timeout') ? '504' : '500'
  if (request.state === 'streaming') return '102'
  if (request.state === 'reconciling') return '202'
  if (request.state === 'reserved' || request.state === 'connecting') return '102'
  return '200'
}

const requestStatusTone = (request: NonNullable<PublicApiKeyStatus['recent_requests']>[number]) => {
  if (request.status === 'success') return 'success'
  if (request.status === 'error') return 'error'
  if (request.state === 'reconciling') return 'warning'
  return 'info'
}
</script>

<template>
  <div class="sp-checker-page">
    <section class="sp-checker-hero relative overflow-hidden">
      <div
        class="sp-khmer-motif pointer-events-none absolute inset-0 opacity-[0.06]"
        aria-hidden="true"
      />
      <div
        class="sp-ambient-glow pointer-events-none absolute inset-x-0 -top-48 h-[36rem]"
        aria-hidden="true"
      />

      <UContainer class="relative py-14 sm:py-20">
        <div class="mx-auto max-w-4xl text-center">
          <div class="mb-5 flex flex-wrap items-center justify-center gap-2">
            <UBadge
              color="success"
              variant="subtle"
              size="lg"
              class="rounded-full"
            >
              <UIcon
                name="i-lucide-shield-check"
                class="mr-1 size-4"
              />
              Secure possession check
            </UBadge>
            <span class="sp-khmer-chip">កម្ពុជា · SP Cambo</span>
          </div>

          <h1 class="text-4xl font-semibold tracking-[-0.035em] text-highlighted sm:text-6xl">
            Know exactly what your <span class="sp-gradient-text">API key can use</span>
          </h1>
          <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-muted sm:text-lg">
            Check status, model access, limits, balance and recent local metering in one place — no account sign-in required.
          </p>

          <div class="mx-auto mt-8 grid max-w-3xl gap-3 text-left sm:grid-cols-3">
            <div class="sp-checker-proof">
              <UIcon
                name="i-lucide-link-2-off"
                class="size-4 text-success"
              /><span>Never placed in the URL</span>
            </div>
            <div class="sp-checker-proof">
              <UIcon
                name="i-lucide-hard-drive"
                class="size-4 text-success"
              /><span>Never saved in storage</span>
            </div>
            <div class="sp-checker-proof">
              <UIcon
                name="i-lucide-fingerprint"
                class="size-4 text-success"
              /><span>HMAC lookup on the server</span>
            </div>
          </div>
        </div>
      </UContainer>
    </section>

    <UContainer class="pb-16 pt-8 sm:pb-24 sm:pt-10">
      <div class="mx-auto max-w-6xl space-y-7">
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(19rem,0.65fr)]">
          <UCard class="sp-key-checker-card sp-app-card overflow-hidden">
            <template #header>
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                  <div class="sp-checker-icon flex size-11 shrink-0 items-center justify-center rounded-xl text-primary">
                    <UIcon
                      name="i-lucide-key-round"
                      class="size-5"
                    />
                  </div>
                  <div>
                    <p class="text-xs font-semibold tracking-[0.14em] text-primary uppercase">
                      Private POST request
                    </p><h2 class="mt-1 text-lg font-semibold text-highlighted">
                      Inspect an API key
                    </h2>
                  </div>
                </div>
                <UBadge
                  color="neutral"
                  variant="subtle"
                >
                  10 checks/min
                </UBadge>
              </div>
            </template>

            <UForm
              :state="keyForm"
              class="space-y-5"
              @submit="checkKey"
            >
              <UFormField
                label="SP Cambo API key"
                name="apiKey"
                required
                :error="keyError || undefined"
              >
                <UInput
                  v-model="keyForm.apiKey"
                  :type="showKey ? 'text' : 'password'"
                  placeholder="sk-..."
                  size="xl"
                  class="w-full font-mono"
                  autocomplete="off"
                  autocapitalize="off"
                  spellcheck="false"
                  :maxlength="255"
                  :disabled="checking"
                  aria-describedby="checker-privacy-hint"
                  :ui="{ trailing: 'pe-1' }"
                >
                  <template #trailing>
                    <div class="flex items-center">
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-clipboard-paste"
                        aria-label="Paste key from clipboard"
                        @click="pasteKey"
                      />
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        :icon="showKey ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                        :aria-label="showKey ? 'Hide key' : 'Show key'"
                        @click="showKey = !showKey"
                      />
                    </div>
                  </template>
                </UInput>
              </UFormField>

              <div
                id="checker-privacy-hint"
                class="flex items-start gap-2 rounded-xl border border-default/70 bg-elevated/35 px-3.5 py-3 text-xs leading-5 text-muted"
              >
                <UIcon
                  name="i-lucide-lock-keyhole"
                  class="mt-0.5 size-4 shrink-0 text-primary"
                />
                <span>After a successful check, the field is cleared. The full key remains only in this tab's memory for secure auto refresh while this tab stays open, until you clear or close the page.</span>
              </div>

              <div class="flex flex-col gap-2 sm:flex-row">
                <UButton
                  type="submit"
                  size="lg"
                  :loading="checking && checkingMode === 'manual'"
                  icon="i-lucide-scan-search"
                  class="sm:flex-1"
                >
                  Verify key
                </UButton>
                <UButton
                  type="button"
                  size="lg"
                  color="neutral"
                  variant="subtle"
                  :disabled="checking"
                  icon="i-lucide-eraser"
                  @click="clear"
                >
                  Clear securely
                </UButton>
              </div>
            </UForm>
          </UCard>

          <aside class="space-y-4">
            <div class="sp-checker-side-card">
              <div class="sp-khmer-rule mb-4 !h-px !w-16" />
              <h2 class="font-semibold text-highlighted">
                Everything you need to verify
              </h2>
              <div class="mt-5 space-y-4">
                <div
                  v-for="item in [
                    { icon: 'i-lucide-badge-check', title: 'Credential state', text: 'Active, disabled, expired or revoked.' },
                    { icon: 'i-lucide-boxes', title: 'Models and limits', text: 'Context window, output cap and key overrides.' },
                    { icon: 'i-lucide-wallet-cards', title: 'Spendable balance', text: 'Token quota and exact currency-scaled credit.' },
                    { icon: 'i-lucide-activity', title: 'Local metering', text: 'Usage, reuse savings and recent request states.' }
                  ]"
                  :key="item.title"
                  class="flex gap-3"
                >
                  <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-primary">
                    <UIcon
                      :name="item.icon"
                      class="size-4"
                    />
                  </div>
                  <div>
                    <p class="text-sm font-medium text-highlighted">
                      {{ item.title }}
                    </p><p class="mt-0.5 text-xs leading-5 text-muted">
                      {{ item.text }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <UButton
              to="/login"
              color="neutral"
              variant="subtle"
              block
              trailing-icon="i-lucide-arrow-right"
            >
              Manage keys in dashboard
            </UButton>
          </aside>
        </div>

        <UCard
          v-if="checking && !keyStatus"
          class="sp-app-card"
          aria-live="polite"
        >
          <div class="flex items-center gap-4 py-2">
            <div class="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <UIcon
                name="i-lucide-loader-circle"
                class="size-5 animate-spin"
              />
            </div>
            <div>
              <p class="font-medium text-highlighted">
                Verifying key securely
              </p><p class="mt-1 text-sm text-muted">
                Checking credential state, spendable entitlements and local usage…
              </p>
            </div>
          </div>
        </UCard>

        <UAlert
          v-if="checkError"
          role="alert"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="This key could not be verified"
          :description="`${checkError.title}: ${checkError.description}`"
        />
        <UAlert
          v-if="refreshWarning"
          role="status"
          color="warning"
          variant="subtle"
          icon="i-lucide-refresh-cw-off"
          title="Showing the last successful result"
          :description="refreshWarning"
        />

        <template v-if="keyStatus">
          <section
            class="sp-checker-result"
            :class="`sp-checker-result--${statusUi.key}`"
            aria-live="polite"
          >
            <div
              class="sp-checker-result__glow"
              aria-hidden="true"
            />
            <div class="relative grid gap-6 p-5 sm:p-7 lg:grid-cols-[1fr_auto] lg:items-center">
              <div class="flex min-w-0 items-start gap-4">
                <div class="sp-checker-status-icon flex size-12 shrink-0 items-center justify-center rounded-2xl">
                  <UIcon
                    :name="statusUi.icon"
                    class="size-6"
                  />
                </div>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xs font-semibold tracking-[0.15em] uppercase opacity-75">
                      Verification result
                    </p><UBadge
                      :color="statusUi.color"
                      variant="subtle"
                      size="sm"
                    >
                      {{ displayStatus }}
                    </UBadge>
                  </div>
                  <h2 class="mt-2 text-2xl font-semibold tracking-tight text-highlighted">
                    {{ statusUi.title }}
                  </h2>
                  <p class="mt-1 max-w-2xl text-sm leading-6 text-muted">
                    {{ statusUi.description }}
                  </p>
                  <div class="mt-3 flex min-w-0 items-center gap-1.5">
                    <code class="truncate rounded-md bg-default/60 px-2 py-1 font-mono text-xs text-default">{{ keyStatus.masked_key || 'Masked key unavailable' }}</code>
                    <SpCopyButton
                      v-if="keyStatus.masked_key"
                      :value="keyStatus.masked_key"
                      label="masked key"
                    />
                  </div>
                </div>
              </div>

              <div class="flex flex-col gap-3 lg:min-w-64 lg:items-end">
                <div class="text-left lg:text-right">
                  <p class="text-sm font-medium text-highlighted">
                    {{ lastCheckedLabel }}
                  </p><p class="mt-0.5 text-xs text-muted">
                    Server time {{ formatDate(keyStatus.server_time) }}
                  </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <UButton
                    color="neutral"
                    variant="subtle"
                    icon="i-lucide-refresh-cw"
                    :loading="checking && checkingMode === 'refresh'"
                    :disabled="!sessionSecret"
                    @click="refreshNow"
                  >
                    Refresh
                  </UButton>
                  <div
                    v-if="sessionSecret"
                    class="sp-checker-auto-refresh"
                  >
                    <span class="sp-checker-auto-refresh__dot" aria-hidden="true" />
                    Auto refresh · 15s
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section
            aria-labelledby="balance-heading"
            class="space-y-4"
          >
            <div class="flex items-end justify-between gap-4">
              <div>
                <p class="text-xs font-semibold tracking-[0.14em] text-primary uppercase">
                  At a glance
                </p><h2
                  id="balance-heading"
                  class="mt-1 text-xl font-semibold text-highlighted"
                >
                  Balance and activity
                </h2>
              </div>
              <p class="hidden text-xs text-muted sm:block">
                Exact values from the latest successful check
              </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              <SpMetric
                label="Time remaining"
                icon="i-lucide-clock"
                tone="warning"
                :value="formatTimeRemaining(keyStatus.expires_at)"
              />
              <SpMetric
                label="Quota remaining"
                icon="i-lucide-hourglass"
                tone="info"
                :value="keyStatus.quota_remaining === null || keyStatus.quota_remaining === undefined ? 'No balance' : formatUnits(keyStatus.quota_remaining)"
              />
              <SpMetric
                label="Credit remaining"
                icon="i-lucide-wallet"
                tone="success"
                :value="keyStatus.credit_remaining || keyStatus.credit_balances?.length ? formatMoneySet(keyStatus.credit_remaining, keyStatus.credit_balances) : 'No balance'"
              />
              <SpMetric
                label="Total Tokens"
                icon="i-lucide-gauge"
                tone="primary"
                :value="formatUnits(keyStatus.tokens_used?.total ?? '0')"
              />
              <SpMetric
                label="Credit charged"
                icon="i-lucide-chart-line"
                tone="warning"
                :value="formatMoneySet(keyStatus.total_spend, keyStatus.total_spend_by_currency)"
              />
              <SpMetric
                label="Live requests"
                icon="i-lucide-radio"
                tone="primary"
                :value="String(keyStatus.active_requests ?? 0)"
              />
            </div>
          </section>

          <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(22rem,0.85fr)]">
            <UCard class="sp-app-card">
              <template #header>
                <div class="flex items-center gap-3">
                  <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <UIcon
                      name="i-lucide-boxes"
                      class="size-4"
                    />
                  </div>
                  <div>
                    <h3 class="font-semibold text-highlighted">
                      Allowed models
                    </h3><p class="mt-0.5 text-xs text-muted">
                      Public aliases and their configured capability windows.
                    </p>
                  </div>
                </div>
              </template>

              <div
                v-if="modelDetails.length"
                class="grid gap-3 sm:grid-cols-2"
              >
                <article
                  v-for="model in modelDetails"
                  :key="model.public_alias"
                  class="sp-checker-model-card"
                >
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <p class="truncate font-medium text-highlighted">
                        {{ model.display_name }}
                      </p><code class="mt-1 block truncate font-mono text-[11px] text-muted">{{ model.public_alias }}</code>
                    </div>
                    <SpStatusBadge
                      :status="model.status"
                      size="xs"
                    />
                  </div>
                  <div class="mt-4 grid grid-cols-2 gap-2">
                    <div class="rounded-lg bg-elevated/45 p-2.5">
                      <p class="text-[10px] text-muted">
                        Context
                      </p><p class="mt-1 font-semibold text-highlighted">
                        {{ model.context_tokens ? formatCompactUnits(model.context_tokens) : '—' }}
                      </p>
                    </div>
                    <div class="rounded-lg bg-elevated/45 p-2.5">
                      <p class="text-[10px] text-muted">
                        Max output
                      </p><p class="mt-1 font-semibold text-highlighted">
                        {{ model.max_output_tokens ? formatCompactUnits(model.max_output_tokens) : '—' }}
                      </p>
                    </div>
                  </div>
                  <div
                    v-if="model.features.length"
                    class="mt-3 flex flex-wrap gap-1.5"
                  >
                    <UBadge
                      v-for="feature in model.features"
                      :key="feature"
                      color="neutral"
                      variant="subtle"
                      size="xs"
                    >
                      {{ feature }}
                    </UBadge>
                  </div>
                  <p
                    v-if="model.capability_basis"
                    class="mt-3 text-[10px] leading-4 text-dimmed"
                  >
                    {{ model.capability_basis === 'PROVIDER_PUBLIC_SPEC' ? 'Provider-published capability profile' : 'SP Cambo route capability profile' }}
                  </p>
                </article>
              </div>
              <div
                v-else
                class="py-8 text-center text-sm text-muted"
              >
                No model aliases are attached to this key.
              </div>
            </UCard>

            <div class="space-y-6">
              <UCard class="sp-app-card">
                <template #header>
                  <h3 class="font-semibold text-highlighted">
                    Access details
                  </h3>
                </template>
                <dl class="divide-y divide-default text-sm">
                  <div class="flex items-start justify-between gap-5 py-3 first:pt-0">
                    <dt class="text-muted">
                      Package
                    </dt><dd class="max-w-[65%] text-right font-medium text-highlighted">
                      {{ keyStatus.package || 'Not available' }}
                    </dd>
                  </div>
                  <div class="flex items-start justify-between gap-5 py-3">
                    <dt class="text-muted">
                      Funding source
                    </dt><dd class="text-right font-medium text-highlighted">
                      {{ fundingLabel }}
                    </dd>
                  </div>
                  <div class="flex items-start justify-between gap-5 py-3">
                    <dt class="text-muted">
                      Created
                    </dt><dd class="text-right font-medium text-highlighted">
                      {{ formatDate(keyStatus.created_at) }}
                    </dd>
                  </div>
                  <div class="flex items-start justify-between gap-5 py-3 last:pb-0">
                    <dt class="text-muted">
                      Expires
                    </dt><dd class="text-right font-medium text-highlighted">
                      {{ formatDate(keyStatus.expires_at) }}
                    </dd>
                  </div>
                </dl>
                <p
                  v-if="keyStatus.funding_note"
                  class="mt-4 rounded-lg border border-info/20 bg-info/5 p-3 text-xs leading-5 text-muted"
                >
                  {{ keyStatus.funding_note }}
                </p>
              </UCard>

              <UCard class="sp-app-card">
                <template #header>
                  <div>
                    <h3 class="font-semibold text-highlighted">
                      Key-level limits
                    </h3><p class="mt-0.5 text-xs text-muted">
                      “Package default” means the key has no stricter override.
                    </p>
                  </div>
                </template>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                  <div class="sp-checker-limit">
                    <dt>Requests/min</dt><dd>{{ formatLimit(keyStatus.limits?.requests_per_minute) }}</dd>
                  </div>
                  <div class="sp-checker-limit">
                    <dt>Tokens/min</dt><dd>{{ formatLimit(keyStatus.limits?.tokens_per_minute) }}</dd>
                  </div>
                  <div class="sp-checker-limit">
                    <dt>Concurrency</dt><dd>{{ formatLimit(keyStatus.limits?.concurrency) }}</dd>
                  </div>
                  <div class="sp-checker-limit">
                    <dt>Max output</dt><dd>{{ formatLimit(keyStatus.limits?.max_output_tokens) }}</dd>
                  </div>
                  <div class="sp-checker-limit col-span-2">
                    <dt>Max request size</dt><dd>{{ formatBytes(keyStatus.limits?.max_request_bytes) }}</dd>
                  </div>
                </dl>
              </UCard>
            </div>
          </div>

          <UCard class="sp-app-card">
            <template #header>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <h3 class="font-semibold text-highlighted">
                    Usage and smart reuse
                  </h3><p class="mt-1 text-sm text-muted">
                    Calculated only from SP Cambo's local request meter.
                  </p>
                </div>
                <UBadge
                  color="success"
                  variant="subtle"
                  icon="i-lucide-sparkles"
                >
                  Automatic savings
                </UBadge>
              </div>
            </template>
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              <div class="sp-checker-usage">
                <dt>Input</dt><dd class="text-success">
                  {{ formatUnits(keyStatus.tokens_used?.input ?? '0') }}
                </dd>
              </div>
              <div class="sp-checker-usage">
                <dt>Reused context</dt><dd class="text-info">
                  {{ formatUnits(keyStatus.tokens_used?.cached_input ?? '0') }}
                </dd>
              </div>
              <div class="sp-checker-usage">
                <dt>Output</dt><dd class="text-error">
                  {{ formatUnits(keyStatus.tokens_used?.output ?? '0') }}
                </dd>
              </div>
              <div class="sp-checker-usage">
                <dt>Saved by cache</dt><dd class="text-success">
                  {{ formatUnits(keyStatus.tokens_used?.saved ?? '0') }}
                </dd>
              </div>
              <div class="sp-checker-usage">
                <dt>Charged Tokens</dt><dd class="text-primary">
                  {{ formatUnits(keyStatus.tokens_used?.billed ?? '0') }}
                </dd>
              </div>
              <div class="sp-checker-usage">
                <dt>Savings rate</dt><dd class="text-success">
                  {{ Number(keyStatus.tokens_used?.savings_rate_percent ?? 0).toFixed(1) }}%
                </dd>
              </div>
            </dl>
            <div class="mt-4 flex flex-col gap-2 rounded-xl border border-default/70 bg-elevated/35 px-4 py-3 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
              <span>Provider or OmniRoute counters never control this customer balance.</span>
              <span>Last used: <strong class="font-medium text-highlighted">{{ formatDate(keyStatus.last_used) }}</strong></span>
            </div>
          </UCard>

          <UCard class="sp-app-card overflow-hidden">
            <template #header>
              <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <h3 class="font-semibold text-highlighted">
                    Recent requests
                  </h3><p class="mt-1 text-sm text-muted">
                    Up to 12 latest requests, with live duration for in-flight activity.
                  </p>
                </div>
                <div class="flex flex-wrap gap-1 rounded-lg bg-elevated/50 p-1">
                  <UButton
                    v-for="filter in requestFilters"
                    :key="filter.value"
                    size="xs"
                    :color="requestFilter === filter.value ? 'primary' : 'neutral'"
                    :variant="requestFilter === filter.value ? 'soft' : 'ghost'"
                    @click="requestFilter = filter.value"
                  >
                    {{ filter.label }}
                  </UButton>
                </div>
              </div>
            </template>

            <div
              v-if="filteredRequests.length"
              class="sp-scroll-x -mx-4 sm:-mx-6"
            >
              <table class="w-full min-w-[1120px] text-sm">
                <thead>
                  <tr class="border-b border-default bg-elevated/30 text-left text-xs text-muted">
                    <th class="px-5 py-3 font-medium">
                      Request
                    </th><th class="px-3 py-3 font-medium">
                      Status
                    </th><th class="px-3 py-3 font-medium">
                      Model
                    </th><th class="px-3 py-3 text-right font-medium">
                      In
                    </th><th class="px-3 py-3 text-right font-medium">
                      Reused
                    </th><th class="px-3 py-3 text-right font-medium">
                      Saved by cache
                    </th><th class="px-3 py-3 text-right font-medium">
                      Out
                    </th><th class="px-3 py-3 text-right font-medium">
                      Charged
                    </th><th class="px-3 py-3 text-right font-medium">
                      Duration
                    </th><th class="px-5 py-3 text-right font-medium">
                      Wallet
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="request in filteredRequests"
                    :key="request.request_id"
                    class="sp-checker-request-row border-b border-default/60 last:border-0"
                  >
                    <td class="px-5 py-3">
                      <div class="flex items-center gap-1">
                        <code class="max-w-32 truncate font-mono text-xs text-default">{{ request.request_id }}</code><SpCopyButton
                          :value="request.request_id"
                          label="request ID"
                        />
                      </div><p class="mt-1 text-[11px] text-dimmed">
                        {{ formatDate(request.time) }}
                      </p>
                    </td>
                    <td class="px-3 py-3">
                      <div class="sp-request-status" :class="`sp-request-status--${requestStatusTone(request)}`">
                        <strong class="sp-request-status__code">{{ requestStatusCode(request) }}</strong>
                        <span class="sp-request-status__label">{{ stateLabel(request.state) }}</span>
                      </div>
                    </td>
                    <td class="px-3 py-3">
                      <p class="font-mono text-default">
                        {{ request.model }}
                      </p><p class="max-w-48 truncate text-xs text-dimmed">
                        {{ request.endpoint }}
                      </p>
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-success">
                      {{ request.input_tokens === null ? '—' : formatUnits(request.input_tokens) }}
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-info">
                      {{ request.cached_input_tokens === null || request.cached_input_tokens === '0' ? '—' : formatUnits(request.cached_input_tokens) }}
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-success">
                      {{ request.saved_tokens === null || request.saved_tokens === undefined || request.saved_tokens === '0' ? '—' : formatUnits(request.saved_tokens) }}
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-error">
                      {{ request.output_tokens === null ? '—' : formatUnits(request.output_tokens) }}
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-primary">
                      {{ request.billed_tokens ? formatUnits(request.billed_tokens) : request.reserved_units ? `${formatUnits(request.reserved_units)} reserved` : '—' }}
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right font-semibold text-warning">
                      {{ requestDuration(request) }}
                    </td>
                    <td class="sp-numeric px-5 py-3 text-right font-medium text-highlighted">
                      {{ request.charge ? formatMoney(request.charge) : '—' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div
              v-else
              class="flex flex-col items-center py-10 text-center"
            >
              <div class="mb-3 flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <UIcon
                  name="i-lucide-activity"
                  class="size-5"
                />
              </div>
              <p class="font-medium text-highlighted">
                No {{ requestFilter === 'all' ? 'recent' : requestFilter }} requests
              </p>
              <p class="mt-1 text-sm text-muted">
                {{ requestFilter === 'all' ? 'Usage appears after this key makes API calls.' : 'Choose another filter to see different request states.' }}
              </p>
            </div>
          </UCard>

          <div class="flex flex-col gap-3 rounded-2xl border border-default bg-elevated/25 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
              <UIcon
                name="i-lucide-life-buoy"
                class="mt-0.5 size-5 shrink-0 text-primary"
              /><div>
                <p class="font-medium text-highlighted">
                  Need to change this key?
                </p><p class="mt-1 text-sm text-muted">
                  Sign in to enable, disable, rotate or permanently revoke credentials.
                </p>
              </div>
            </div>
            <div class="flex gap-2">
              <UButton
                to="/support"
                color="neutral"
                variant="ghost"
              >
                Get support
              </UButton><UButton
                to="/login"
                trailing-icon="i-lucide-arrow-right"
              >
                Open dashboard
              </UButton>
            </div>
          </div>
        </template>
      </div>
    </UContainer>
  </div>
</template>

<style scoped>
.sp-checker-page {
  min-height: 100svh;
  background: radial-gradient(circle at 50% 22%, color-mix(in oklab, var(--ui-primary) 5%, transparent), transparent 30%), var(--ui-bg);
}

.sp-checker-hero {
  border-bottom: 1px solid color-mix(in oklab, var(--ui-border) 65%, transparent);
  background: linear-gradient(180deg, color-mix(in oklab, var(--ui-bg-elevated) 58%, transparent), transparent);
}

.sp-checker-proof,
.sp-checker-side-card,
.sp-checker-result,
.sp-checker-model-card {
  border: 1px solid color-mix(in oklab, var(--ui-border) 82%, transparent);
  background: color-mix(in oklab, var(--ui-bg-elevated) 78%, transparent);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .035);
}

.sp-checker-proof { display: flex; align-items: center; gap: .6rem; border-radius: .8rem; padding: .75rem .9rem; font-size: .75rem; color: var(--ui-text-muted); backdrop-filter: blur(12px); }
.sp-checker-side-card { border-radius: 1rem; padding: 1.25rem; }
.sp-checker-icon { border: 1px solid color-mix(in oklab, var(--ui-primary) 24%, transparent); background: color-mix(in oklab, var(--ui-primary) 10%, var(--ui-bg-elevated)); box-shadow: inset 0 1px 0 color-mix(in oklab, white 8%, transparent), 0 10px 28px color-mix(in oklab, var(--ui-primary) 9%, transparent); }

.sp-checker-result {
  --checker-tone: var(--ui-primary);
  position: relative;
  isolation: isolate;
  overflow: hidden;
  border-radius: 1.1rem;
  border-color: color-mix(in oklab, var(--checker-tone) 34%, var(--ui-border));
  background: linear-gradient(120deg, color-mix(in oklab, var(--checker-tone) 8%, var(--ui-bg-elevated)), var(--ui-bg-elevated) 64%);
  box-shadow: 0 20px 60px color-mix(in oklab, var(--ui-bg) 65%, transparent), inset 0 1px 0 color-mix(in oklab, white 5%, transparent);
}

.sp-checker-result--active { --checker-tone: var(--ui-success); }
.sp-checker-result--warning { --checker-tone: var(--ui-warning); }
.sp-checker-result--danger { --checker-tone: var(--ui-error); }
.sp-checker-result--neutral { --checker-tone: var(--ui-text-muted); }
.sp-checker-result__glow { position: absolute; right: -7rem; top: -9rem; width: 22rem; height: 22rem; border-radius: 9999px; opacity: .12; filter: blur(22px); background: var(--checker-tone); }
.sp-checker-status-icon { color: var(--checker-tone); border: 1px solid color-mix(in oklab, var(--checker-tone) 24%, transparent); background: color-mix(in oklab, var(--checker-tone) 10%, transparent); }

.sp-checker-model-card { border-radius: .9rem; padding: 1rem; transition: border-color 160ms ease, transform 160ms ease, background-color 160ms ease; }
.sp-checker-model-card:hover { transform: translateY(-1px); border-color: color-mix(in oklab, var(--ui-primary) 32%, var(--ui-border)); background: color-mix(in oklab, var(--ui-primary) 4%, var(--ui-bg-elevated)); }
.sp-checker-limit, .sp-checker-usage { border-radius: .75rem; background: color-mix(in oklab, var(--ui-bg-elevated) 72%, transparent); padding: .75rem; }
.sp-checker-limit dt, .sp-checker-usage dt { font-size: .68rem; color: var(--ui-text-muted); }
.sp-checker-limit dd { margin-top: .25rem; font-size: .78rem; font-weight: 600; color: var(--ui-text-highlighted); }
.sp-checker-usage dd { margin-top: .35rem; font-size: 1.15rem; font-weight: 650; letter-spacing: -.02em; }

@media (prefers-reduced-motion: reduce) {
  .sp-checker-model-card { transition: none; }
  .sp-checker-model-card:hover { transform: none; }
}

.sp-checker-auto-refresh {
  display: inline-flex;
  align-items: center;
  gap: .45rem;
  border: 1px solid color-mix(in oklab, var(--ui-success) 20%, var(--ui-border));
  border-radius: 9999px;
  background: color-mix(in oklab, var(--ui-success) 8%, var(--ui-bg-elevated));
  padding: .55rem .85rem;
  font-size: .74rem;
  font-weight: 600;
  color: color-mix(in oklab, var(--ui-success) 72%, var(--ui-text));
}

.sp-checker-auto-refresh__dot {
  width: .5rem;
  height: .5rem;
  border-radius: 9999px;
  background: var(--ui-success);
  box-shadow: 0 0 0 6px color-mix(in oklab, var(--ui-success) 12%, transparent);
}

.sp-checker-request-row {
  transition: background-color 140ms ease, box-shadow 140ms ease;
}

.sp-checker-request-row:hover {
  background: color-mix(in oklab, var(--ui-bg-elevated) 45%, transparent);
}

.sp-request-status {
  display: inline-flex;
  min-width: 4.45rem;
  flex-direction: column;
  align-items: flex-start;
  gap: .12rem;
  border: 1px solid color-mix(in oklab, var(--ui-border) 86%, transparent);
  border-radius: .8rem;
  background: color-mix(in oklab, var(--ui-bg-elevated) 74%, transparent);
  padding: .42rem .62rem;
}

.sp-request-status__code {
  font-variant-numeric: tabular-nums;
  font-size: .92rem;
  font-weight: 700;
  letter-spacing: -.02em;
}

.sp-request-status__label {
  font-size: .64rem;
  font-weight: 600;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--ui-text-muted);
}

.sp-request-status--success .sp-request-status__code { color: var(--ui-success); }
.sp-request-status--error .sp-request-status__code { color: var(--ui-error); }
.sp-request-status--warning .sp-request-status__code { color: var(--ui-warning); }
.sp-request-status--info .sp-request-status__code { color: var(--ui-primary); }

@media (max-width: 639px) {
  .sp-checker-auto-refresh {
    width: 100%;
    justify-content: center;
  }
}

</style>
