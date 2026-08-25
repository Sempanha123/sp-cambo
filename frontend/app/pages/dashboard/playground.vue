<script setup lang="ts">
import type { PublicModel, RequestActivity } from '~/types/commerce'

type ChatMessage = { role: 'user' | 'assistant', content: string }
type PlaygroundProtocol = 'messages' | 'responses' | 'chat_completions'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({
  title: 'Playground',
  description: 'Chat with SP Cambo models using daily free quota, redeem balance or purchased balance.',
  robots: 'noindex'
})

const api = useSpApi()
const toast = useToast()
const models = await useSpResource('catalog:models:playground-chat', () => api.catalog.models(), { server: false })
const quota = await useSpResource('dashboard:playground-quota', () => api.account.playgroundQuota(), { server: false })

const selectedAlias = ref<string | undefined>()
const messages = ref<ChatMessage[]>([])
const composer = ref('')
const systemPrompt = ref('')
const maxOutputTokens = ref(1024)
const temperatureEnabled = ref(false)
const temperature = ref(0.7)
const sending = ref(false)
const lastRawResponse = ref<unknown | null>(null)
const lastRequestId = ref<string | null>(null)
const errorMessage = ref<string | null>(null)
const redeemCode = ref('')
const redeeming = ref(false)
const recentActivity = ref<RequestActivity[]>([])
const liveClock = ref(Date.now())
let activityTimer: ReturnType<typeof setInterval> | undefined
let clockTimer: ReturnType<typeof setInterval> | undefined

const starters = [
  { label: 'Analyze data', icon: 'i-lucide-chart-no-axes-column', prompt: 'Help me analyze this data and explain the important patterns:\n' },
  { label: 'Summarize text', icon: 'i-lucide-file-text', prompt: 'Summarize the following text into the most important points:\n' },
  { label: 'Write code', icon: 'i-lucide-code-2', prompt: 'Write clean code for this requirement and explain the key decisions:\n' },
  { label: 'Explain concept', icon: 'i-lucide-lightbulb', prompt: 'Explain this concept clearly with a practical example:\n' },
  { label: 'Brainstorm ideas', icon: 'i-lucide-wand-sparkles', prompt: 'Brainstorm several useful ideas for:\n' },
  { label: 'Draft API request', icon: 'i-lucide-braces', prompt: 'Help me draft and validate an API request for:\n' }
]
const personaPrompts = [
  { label: 'Coding assistant', value: 'You are a careful coding assistant. Prefer correct, maintainable solutions and explain important tradeoffs.' },
  { label: 'Concise helper', value: 'Be concise and practical. Lead with the answer, then include only the details needed to act.' },
  { label: 'Translator', value: 'Translate accurately while preserving meaning, tone and formatting. Ask only when the target language is genuinely ambiguous.' }
]

const allModels = computed(() => models.data.value ?? [])
const availableModels = computed(() => {
  const q = quota.data.value
  if (!q || q.allow_model_switching) return allModels.value
  const locked = q.default_model_alias
  if (!locked) return allModels.value.slice(0, 1)
  return allModels.value.filter(model => model.public_alias === locked)
})
const modelOptions = computed(() => availableModels.value.map(model => ({ label: model.display_name, value: model.public_alias })))
const selectedModel = computed<PublicModel | null>(() => allModels.value.find(model => model.public_alias === selectedAlias.value) ?? null)

const preferredProtocol = (model: PublicModel | null): PlaygroundProtocol | null => {
  if (!model) return null
  if (model.capabilities.responses_api === true) return 'responses'
  if (model.capabilities.messages_api === true) return 'messages'
  if (model.capabilities.chat_completions_api === true) return 'chat_completions'
  return null
}
const protocol = computed(() => preferredProtocol(selectedModel.value))
const protocolLabels: Record<PlaygroundProtocol, string> = {
  responses: 'Responses API',
  messages: 'Anthropic Messages',
  chat_completions: 'Chat Completions'
}
const protocolLabel = computed(() => protocol.value ? protocolLabels[protocol.value] : 'No chat protocol')

watch([allModels, () => quota.data.value], () => {
  const q = quota.data.value
  const candidate = q?.default_model_alias || q?.free_model_aliases?.[0] || allModels.value[0]?.public_alias
  if (!selectedAlias.value || !availableModels.value.some(model => model.public_alias === selectedAlias.value)) {
    selectedAlias.value = availableModels.value.some(model => model.public_alias === candidate)
      ? candidate
      : availableModels.value[0]?.public_alias
  }
  if (q) maxOutputTokens.value = Math.min(maxOutputTokens.value, q.max_output_tokens || 1024)
}, { immediate: true })

const fundingForSelectedModel = computed(() => {
  const q = quota.data.value
  if (!q || !selectedAlias.value) return false
  const dailyAvailable = q.remaining > 0 && q.free_model_aliases.includes(selectedAlias.value)
  return dailyAvailable || q.fallback_available
})
const quotaExhausted = computed(() => quota.data.value?.enabled === true && !fundingForSelectedModel.value)
const canSend = computed(() => Boolean(
  quota.data.value?.enabled && selectedAlias.value && protocol.value && composer.value.trim() && fundingForSelectedModel.value && !sending.value
))
const fallbackTokenBalance = computed(() => (quota.data.value?.redeem_token_remaining ?? 0) + (quota.data.value?.paid_token_remaining ?? 0))
const freePercent = computed(() => {
  const q = quota.data.value
  if (!q?.limit) return 0
  return Math.max(0, Math.min(100, Math.round((q.remaining / q.limit) * 100)))
})
const runningRequests = computed(() => recentActivity.value.filter(row => ['reserved', 'connecting', 'streaming', 'reconciling'].includes(row.state)))
const lastRequest = computed(() => {
  if (lastRequestId.value) return recentActivity.value.find(row => row.id === lastRequestId.value) ?? recentActivity.value[0] ?? null
  return recentActivity.value[0] ?? null
})

const formatUnits = (value: number | string | null | undefined) => new Intl.NumberFormat().format(Math.max(0, Number(value) || 0))
const elapsed = (row: RequestActivity) => {
  if (row.duration_ms !== null) return `${Math.max(0, row.duration_ms).toLocaleString()} ms`
  return `${Math.max(0, Math.round((liveClock.value - new Date(row.started_at).getTime()) / 100) / 10)} s live`
}
const stateColor = (state: RequestActivity['state']) => state === 'settled' ? 'success' : ['failed', 'released'].includes(state) ? 'error' : 'warning'

const refreshActivity = async () => {
  if (typeof api.account.activity !== 'function') return
  try { recentActivity.value = await api.account.activity({ limit: 8 }) } catch { /* telemetry is non-blocking */ }
}
const refreshResources = async () => {
  await Promise.all([models.refresh(), quota.refresh(), refreshActivity()])
}
const newChat = () => {
  messages.value = []
  composer.value = ''
  errorMessage.value = null
  lastRawResponse.value = null
  lastRequestId.value = null
}
const chooseStarter = (prompt: string) => {
  composer.value = prompt
  nextTick(() => document.querySelector<HTMLTextAreaElement>('textarea[data-playground-composer]')?.focus())
}
const setPersona = (prompt: string) => { systemPrompt.value = prompt }

const send = async () => {
  const text = composer.value.trim()
  if (!canSend.value || !text || !selectedAlias.value || !protocol.value) return
  messages.value.push({ role: 'user', content: text })
  composer.value = ''
  sending.value = true
  errorMessage.value = null
  try {
    const result = await api.account.runPlayground({
      model: selectedAlias.value,
      protocol: protocol.value,
      system_prompt: systemPrompt.value.trim() || null,
      messages: messages.value.slice(-30),
      max_output_tokens: Math.min(
        Number(maxOutputTokens.value) || 1024,
        quota.data.value?.max_output_tokens ?? 2048,
        selectedModel.value?.capabilities.max_output_tokens ?? Number.MAX_SAFE_INTEGER
      ),
      temperature: temperatureEnabled.value ? Number(temperature.value) : null
    })
    messages.value.push({ role: 'assistant', content: result.message || 'The model returned a non-text response. Open the raw response below to inspect it.' })
    lastRawResponse.value = result.response
    lastRequestId.value = result.request_id
    quota.data.value = result.quota
    await refreshActivity()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'The Playground request could not be completed.'
    await quota.refresh()
    await refreshActivity()
  } finally {
    sending.value = false
  }
}

const redeem = async () => {
  const code = redeemCode.value.trim()
  if (!code || redeeming.value) return
  redeeming.value = true
  try {
    const result = await api.account.redeemCode({ code, idempotency_key: `playground:${Date.now()}:${Math.random().toString(36).slice(2)}` })
    redeemCode.value = ''
    await quota.refresh()
    toast.add({ title: 'Redeem code applied', description: `${result.units} ${result.billing_mode === 'TOKEN_QUOTA' ? 'token units' : 'credit units'} added.`, color: 'success' })
  } catch (error) {
    toast.add({ title: 'Redeem failed', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally { redeeming.value = false }
}

onMounted(() => {
  void refreshActivity()
  activityTimer = setInterval(() => void refreshActivity(), 3000)
  clockTimer = setInterval(() => { liveClock.value = Date.now() }, 1000)
})
onBeforeUnmount(() => {
  if (activityTimer) clearInterval(activityTimer)
  if (clockTimer) clearInterval(clockTimer)
})
</script>

<template>
  <SpDashboardPage
    title="Playground"
    eyebrow="AI workspace"
    description="Use SP Cambo's hosted chat with your daily free allowance first, then redeem or purchased balance. No API key is pasted into the Playground."
  >
    <template #actions>
      <div class="flex items-center gap-2">
        <UBadge v-if="runningRequests.length" color="warning" variant="subtle">
          <span class="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-current" />{{ runningRequests.length }} live
        </UBadge>
        <UButton color="neutral" variant="subtle" icon="i-lucide-plus" @click="newChat">New chat</UButton>
      </div>
    </template>

    <SpAsyncSection
      :loading="models.initialLoading.value || quota.initialLoading.value"
      :unavailable="models.unavailable.value || quota.unavailable.value"
      :failed="models.failed.value || quota.failed.value"
      :error-message="models.error.value?.message || quota.error.value?.message"
      error-title="Playground could not be loaded"
      @retry="refreshResources"
    >
      <div v-if="quota.data.value" class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-default bg-elevated/35 px-4 py-3">
          <p class="text-xs text-muted">Daily free</p>
          <div class="mt-1 flex items-end justify-between gap-3"><strong class="sp-numeric text-lg">{{ formatUnits(quota.data.value.remaining) }}</strong><span class="text-xs text-muted">/ {{ formatUnits(quota.data.value.limit) }}</span></div>
          <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${freePercent}%` }" /></div>
        </div>
        <div class="rounded-xl border border-default bg-elevated/35 px-4 py-3"><p class="text-xs text-muted">Redeem + purchased tokens</p><strong class="sp-numeric mt-1 block text-lg">{{ formatUnits(fallbackTokenBalance) }}</strong></div>
        <div class="rounded-xl border border-default bg-elevated/35 px-4 py-3"><p class="text-xs text-muted">Credit units</p><strong class="sp-numeric mt-1 block text-lg">{{ formatUnits(quota.data.value.paid_credit_remaining) }}</strong></div>
        <div class="rounded-xl border border-default bg-elevated/35 px-4 py-3"><p class="text-xs text-muted">Request monitor</p><strong class="mt-1 flex items-center gap-2 text-lg"><span class="size-2 rounded-full" :class="runningRequests.length ? 'animate-pulse bg-warning' : 'bg-success'" />{{ runningRequests.length ? `${runningRequests.length} running` : 'Ready' }}</strong></div>
      </div>

      <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <UCard class="sp-premium-card overflow-hidden">
          <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <h2 class="font-semibold text-highlighted">Workspace</h2>
                <p class="mt-1 text-sm text-muted">Start a conversation, test a model, then inspect the exact routed request in Usage.</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <UBadge color="neutral" variant="subtle">{{ protocolLabel }}</UBadge>
                <UBadge v-if="selectedAlias" color="primary" variant="subtle">{{ selectedAlias }}</UBadge>
              </div>
            </div>
          </template>

          <div class="flex min-h-[36rem] flex-col">
            <div class="flex-1 space-y-4 overflow-y-auto py-2">
              <div v-if="messages.length === 0" class="mx-auto flex min-h-80 max-w-3xl flex-col items-center justify-center px-4 text-center">
                <div class="mb-4 flex size-12 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary"><UIcon name="i-lucide-sparkles" class="size-6" /></div>
                <h3 class="text-xl font-semibold text-highlighted">What do you want to build?</h3>
                <p class="mt-2 max-w-xl text-sm text-muted">Start a conversation with one of your published SP Cambo models, or use a starter to shape the first message.</p>
                <div class="mt-6 grid w-full gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  <button v-for="starter in starters" :key="starter.label" type="button" class="flex items-center gap-3 rounded-xl border border-default bg-elevated/30 px-3 py-3 text-left text-sm text-default transition hover:border-primary/40 hover:bg-primary/5" @click="chooseStarter(starter.prompt)">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted"><UIcon :name="starter.icon" class="size-4" /></span>{{ starter.label }}
                  </button>
                </div>
              </div>

              <div v-for="(message, index) in messages" :key="index" class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
                <div class="max-w-[88%] whitespace-pre-wrap rounded-2xl px-4 py-3 text-sm leading-6" :class="message.role === 'user' ? 'bg-primary text-inverted' : 'border border-default bg-elevated/55 text-default'">{{ message.content }}</div>
              </div>
              <div v-if="sending" class="flex justify-start"><div class="flex items-center gap-2 rounded-2xl border border-default bg-elevated/55 px-4 py-3 text-sm text-muted"><UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />Routing request…</div></div>
            </div>

            <UAlert v-if="errorMessage" class="mb-3" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Request failed" :description="errorMessage" />
            <UAlert v-if="selectedModel && !protocol" class="mb-3" color="warning" variant="subtle" title="This alias has no published chat protocol" description="Enable Responses API, Anthropic Messages or Chat Completions on the public model alias before using it in the Playground." />

            <div class="rounded-xl border border-default bg-elevated/25 p-3">
              <UTextarea v-model="composer" data-playground-composer :rows="3" autoresize class="w-full" placeholder="Send a message…" :disabled="!quota.data.value?.enabled" @keydown.enter.exact.prevent="send" />
              <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                  <USelectMenu v-model="selectedAlias" :items="modelOptions" value-key="value" class="min-w-48" :disabled="quota.data.value?.allow_model_switching === false" placeholder="Choose model" />
                  <span class="text-xs text-muted">Enter to send · Shift+Enter newline</span>
                </div>
                <UButton icon="i-lucide-send" :loading="sending" :disabled="!canSend" @click="send">Send</UButton>
              </div>
            </div>
          </div>
        </UCard>

        <div class="space-y-5">
          <UCard class="sp-premium-card">
            <template #header><div><h3 class="font-semibold text-highlighted">Run setup</h3><p class="mt-1 text-xs text-muted">Controls apply only to this Playground chat.</p></div></template>
            <div class="space-y-4">
              <UFormField label="Model picker"><USelectMenu v-model="selectedAlias" :items="modelOptions" value-key="value" class="w-full" :disabled="quota.data.value?.allow_model_switching === false" /></UFormField>
              <div class="rounded-lg border border-default bg-elevated/30 p-3 text-xs">
                <div class="flex justify-between gap-3"><span class="text-muted">Protocol</span><strong>{{ protocolLabel }}</strong></div>
                <div class="mt-2 flex justify-between gap-3"><span class="text-muted">Alias</span><span class="font-mono text-highlighted">{{ selectedAlias || '—' }}</span></div>
              </div>
              <UFormField label="Maximum output tokens"><UInputNumber v-model="maxOutputTokens" :min="1" :max="quota.data.value?.max_output_tokens ?? 65536" class="w-full" /></UFormField>
              <USwitch v-model="temperatureEnabled" label="Custom temperature" />
              <UFormField v-if="temperatureEnabled" label="Temperature"><UInputNumber v-model="temperature" :min="0" :max="2" :step="0.1" class="w-full" /></UFormField>
              <div>
                <p class="mb-2 text-xs font-medium text-muted">Quick roles</p>
                <div class="flex flex-wrap gap-2"><UButton v-for="persona in personaPrompts" :key="persona.label" size="xs" color="neutral" variant="subtle" @click="setPersona(persona.value)">{{ persona.label }}</UButton></div>
              </div>
              <UFormField label="System prompt"><UTextarea v-model="systemPrompt" :rows="4" class="w-full" placeholder="Optional assistant role or instructions" /></UFormField>
            </div>
          </UCard>

          <UCard v-if="lastRequest" class="sp-premium-card">
            <template #header><div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-highlighted">Last request</h3><UBadge :color="stateColor(lastRequest.state)" variant="subtle">{{ lastRequest.state }}</UBadge></div></template>
            <div class="space-y-2 text-sm">
              <div class="flex justify-between gap-3"><span class="text-muted">Provider</span><strong>{{ lastRequest.provider || 'Resolving…' }}</strong></div>
              <div class="flex justify-between gap-3"><span class="text-muted">Public model</span><span class="font-mono text-xs">{{ lastRequest.public_model }}</span></div>
              <div class="flex justify-between gap-3"><span class="text-muted">Route model</span><span class="max-w-48 truncate font-mono text-xs">{{ lastRequest.internal_model || '—' }}</span></div>
              <div class="flex justify-between gap-3"><span class="text-muted">Duration</span><strong class="sp-numeric">{{ elapsed(lastRequest) }}</strong></div>
              <div class="grid grid-cols-2 gap-2 border-t border-default pt-3 text-center"><div><p class="text-xs text-muted">Input</p><strong>{{ lastRequest.input_tokens ?? '—' }}</strong></div><div><p class="text-xs text-muted">Output</p><strong>{{ lastRequest.output_tokens ?? '—' }}</strong></div></div>
              <p v-if="lastRequest.reserved_units && lastRequest.input_tokens === null" class="text-xs text-muted">Reserved estimate: {{ formatUnits(lastRequest.reserved_units) }} units. Exact tokens appear after settlement.</p>
              <UButton to="/dashboard/usage" color="neutral" variant="subtle" block trailing-icon="i-lucide-arrow-right">Open live usage</UButton>
            </div>
          </UCard>

          <UCard class="sp-premium-card">
            <template #header><h3 class="font-semibold text-highlighted">Balance & redeem</h3></template>
            <div class="space-y-3 text-sm">
              <div class="flex justify-between gap-3"><span class="text-muted">Daily free</span><strong>{{ formatUnits(quota.data.value.remaining) }}</strong></div>
              <div class="flex justify-between gap-3"><span class="text-muted">Fallback tokens</span><strong>{{ formatUnits(fallbackTokenBalance) }}</strong></div>
              <div class="flex justify-between gap-3"><span class="text-muted">Credit units</span><strong>{{ formatUnits(quota.data.value.paid_credit_remaining) }}</strong></div>
              <UAlert v-if="quotaExhausted" color="warning" variant="subtle" title="Continue chatting" description="This model has no spendable Playground balance. Redeem a code or buy token/credit balance." />
              <div class="flex gap-2"><UInput v-model="redeemCode" class="min-w-0 flex-1" placeholder="Redeem code" @keyup.enter="redeem" /><UButton color="neutral" variant="subtle" :loading="redeeming" :disabled="!redeemCode.trim()" @click="redeem">Redeem</UButton></div>
              <UButton v-if="quotaExhausted" to="/pricing" class="w-full" icon="i-lucide-shopping-bag">Buy tokens / credit</UButton>
            </div>
          </UCard>

          <details v-if="lastRawResponse !== null" class="rounded-xl border border-default bg-elevated/30 p-4 text-sm"><summary class="cursor-pointer font-medium text-highlighted">Raw response</summary><p v-if="lastRequestId" class="mt-2 font-mono text-xs text-dimmed">{{ lastRequestId }}</p><pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap break-all text-xs text-muted">{{ JSON.stringify(lastRawResponse, null, 2) }}</pre></details>
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
