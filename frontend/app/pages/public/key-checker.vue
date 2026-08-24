<script setup lang="ts">
definePageMeta({
  layout: 'default'
})

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

const keyStatus = ref<{
  valid: boolean
  masked_key?: string
  status?: string
  package?: string
  allowed_models?: string[]
  created_at?: string
  expires_at?: string
  time_remaining?: string
  quota_remaining?: number | null
  credit_remaining?: number | null
  tokens_used?: { input: number, output: number, total: number }
  total_spend?: number
  last_used?: string | null
  recent_requests?: Array<{
    time: string
    model: string
    status: string
    input_tokens: number
    output_tokens: number
    charge: number
  }>
  error?: string
} | null>(null)

const checkKey = async () => {
  const secret = keyForm.value.apiKey.trim()

  if (!secret) {
    toast.add({
      title: 'Enter your API key',
      description: 'Paste the SP Cambo key you want to check.',
      color: 'warning',
      icon: 'i-lucide-key-round'
    })
    return
  }

  checking.value = true
  keyStatus.value = null

  try {
    const response = await api.checkApiKey({ api_key: secret })

    keyStatus.value = {
      valid: true,
      masked_key: response.masked_key,
      status: response.status,
      package: response.package,
      allowed_models: response.allowed_models,
      created_at: response.created_at,
      expires_at: response.expires_at,
      time_remaining: response.time_remaining,
      quota_remaining: response.quota_remaining,
      credit_remaining: response.credit_remaining,
      tokens_used: response.tokens_used,
      total_spend: response.total_spend,
      last_used: response.last_used,
      recent_requests: response.recent_requests
    }

    // The plaintext secret is no longer needed after a successful POST check.
    keyForm.value.apiKey = ''
    showKey.value = false
  } catch (error) {
    const spError = toSpApiError(error)
    keyStatus.value = {
      valid: false,
      error: spError.message
    }
  } finally {
    checking.value = false
  }
}

const clear = () => {
  keyForm.value.apiKey = ''
  keyStatus.value = null
  showKey.value = false
}

const displayStatus = computed(() => keyStatus.value?.status?.toUpperCase() || 'UNKNOWN')

const formatDate = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not available'
const formatNumber = (value: number | null | undefined) => value === null || value === undefined ? 'Unlimited' : value.toLocaleString()
const formatCurrency = (value: number | null | undefined) => new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  minimumFractionDigits: 2,
  maximumFractionDigits: 4
}).format(value ?? 0)

const statusColor = (status?: string) => {
  switch (status?.toLowerCase()) {
    case 'active': return 'success'
    case 'revoked': return 'error'
    case 'expired': return 'warning'
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
            Verify status, expiry, remaining quota and recent metering without opening your dashboard.
            Your plaintext key is submitted in the request body — never in the URL.
          </p>
        </div>
      </UContainer>
    </section>

    <UContainer class="py-10 sm:py-14">
      <div class="mx-auto max-w-5xl space-y-8">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
          <UCard class="sp-premium-card sp-key-checker-card">
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
                  Successful checks clear the plaintext input automatically.
                </li>
              </ul>
            </div>

            <UButton to="/login" color="neutral" variant="subtle" block trailing-icon="i-lucide-arrow-right">
              Manage keys in dashboard
            </UButton>
          </div>
        </div>

        <UAlert
          v-if="keyStatus && !keyStatus.valid"
          role="alert"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="This key could not be verified"
          :description="keyStatus.error"
        />

        <template v-if="keyStatus?.valid">
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

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <SpMetric label="Time remaining" icon="i-lucide-clock" :value="keyStatus.time_remaining || 'Not available'" />
              <SpMetric label="Quota remaining" icon="i-lucide-hourglass" :value="formatNumber(keyStatus.quota_remaining)" />
              <SpMetric label="Credit remaining" icon="i-lucide-wallet" :value="formatCurrency(keyStatus.credit_remaining)" />
              <SpMetric label="Total spend" icon="i-lucide-chart-line" :value="formatCurrency(keyStatus.total_spend)" />
            </div>
          </section>

          <div class="grid gap-6 lg:grid-cols-2">
            <UCard class="sp-premium-card">
              <template #header>
                <h3 class="font-semibold text-highlighted">Access details</h3>
              </template>

              <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div class="rounded-lg border border-default/70 bg-elevated/35 p-4">
                  <dt class="text-xs text-muted">Package</dt>
                  <dd class="mt-1 font-medium text-highlighted">{{ keyStatus.package || 'Not available' }}</dd>
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
            </UCard>

            <UCard class="sp-premium-card">
              <template #header>
                <h3 class="font-semibold text-highlighted">Metered usage</h3>
              </template>

              <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                  <dt class="text-xs text-muted">Input</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-highlighted">{{ formatNumber(keyStatus.tokens_used?.input ?? 0) }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Output</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-highlighted">{{ formatNumber(keyStatus.tokens_used?.output ?? 0) }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Total</dt>
                  <dd class="sp-numeric mt-1 text-xl font-semibold text-highlighted">{{ formatNumber(keyStatus.tokens_used?.total ?? 0) }}</dd>
                </div>
              </dl>

              <div class="mt-5 rounded-lg border border-default/70 bg-elevated/35 p-4">
                <p class="text-xs text-muted">Last used</p>
                <p class="mt-1 text-sm font-medium text-highlighted">{{ formatDate(keyStatus.last_used) }}</p>
              </div>
            </UCard>
          </div>

          <UCard v-if="keyStatus.recent_requests?.length" class="sp-premium-card">
            <template #header>
              <div>
                <h3 class="font-semibold text-highlighted">Recent requests</h3>
                <p class="mt-1 text-sm text-muted">Customer-facing public models and metering only.</p>
              </div>
            </template>

            <div class="sp-scroll-x">
              <table class="w-full min-w-[760px] text-sm">
                <thead>
                  <tr class="border-b border-default text-left text-xs text-muted">
                    <th class="px-3 pb-3 font-medium">Time</th>
                    <th class="px-3 pb-3 font-medium">Model</th>
                    <th class="px-3 pb-3 font-medium">Status</th>
                    <th class="px-3 pb-3 text-right font-medium">Input</th>
                    <th class="px-3 pb-3 text-right font-medium">Output</th>
                    <th class="px-3 pb-3 text-right font-medium">Charge</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(request, index) in keyStatus.recent_requests" :key="`${request.time}-${index}`" class="border-b border-default/60 last:border-0">
                    <td class="px-3 py-3 text-muted">{{ formatDate(request.time) }}</td>
                    <td class="px-3 py-3 font-mono text-default">{{ request.model }}</td>
                    <td class="px-3 py-3">
                      <UBadge :color="request.status === 'success' ? 'success' : request.status === 'error' ? 'error' : 'warning'" variant="subtle" size="sm">
                        {{ request.status }}
                      </UBadge>
                    </td>
                    <td class="sp-numeric px-3 py-3 text-right text-default">{{ formatNumber(request.input_tokens) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right text-default">{{ formatNumber(request.output_tokens) }}</td>
                    <td class="sp-numeric px-3 py-3 text-right font-medium text-highlighted">{{ formatCurrency(request.charge) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </UCard>

          <UCard v-else class="sp-premium-card">
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
