<script setup lang="ts">
import type { PlaygroundChatSummary, PlaygroundModel, RequestActivity } from '~/types/commerce'

type ChatMessage = { role: 'user' | 'assistant', content: string }
type PlaygroundProtocol = 'messages' | 'responses' | 'chat_completions'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({
  title: 'Playground',
  description: 'Chat with SP Cambo models using daily free quota first, then an explicit redeemed or purchased balance fallback.',
  robots: 'noindex'
})

const api = useSpApi()
const toast = useToast()
const quota = await useSpResource('dashboard:playground-quota', () => api.account.playgroundQuota(), { server: false, lazy: true })

const selectedAlias = ref<string | undefined>()
const messages = ref<ChatMessage[]>([])
const composer = ref('')
const systemPrompt = ref('')
const maxOutputTokens = ref(1024)
const temperatureEnabled = ref(false)
const temperature = ref(0.7)
const sending = ref(false)
const receivedStreamDelta = ref(false)
const lastRawResponse = ref<unknown | null>(null)
const lastRequestId = ref<string | null>(null)
const errorMessage = ref<string | null>(null)
const redeemCode = ref('')
const redeeming = ref(false)
const useBalanceFallback = ref(false)
const showInspector = ref(true)
const historyOpen = ref(false)
const historyLoading = ref(false)
const historySaving = ref(false)
const historyError = ref<string | null>(null)
const chatHistory = ref<PlaygroundChatSummary[]>([])
const currentChatId = ref<number | null>(null)
const inspectorTab = ref<'setup' | 'usage'>('setup')
const chatScroll = ref<HTMLElement | null>(null)
const recentActivity = ref<RequestActivity[]>([])
const liveClock = ref(Date.now())
let activityTimer: ReturnType<typeof setInterval> | undefined
let clockTimer: ReturnType<typeof setInterval> | undefined
let streamController: AbortController | null = null

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

const allModels = computed(() => quota.data.value?.available_models ?? [])
const hasChatProtocol = (model: PlaygroundModel) => Boolean(
  model.capabilities.responses_api === true
  || model.capabilities.messages_api === true
  || model.capabilities.chat_completions_api === true
)
const availableModels = computed(() => {
  const q = quota.data.value
  if (!q) return []
  const allowed = allModels.value.filter(model => q.available_model_aliases.includes(model.public_alias) && hasChatProtocol(model))
  if (q.allow_model_switching) return allowed
  const locked = q.default_model_alias
  return allowed.filter(model => model.public_alias === locked || q.fallback_model_aliases.includes(model.public_alias))
})
const unavailableFundedModels = computed(() => quota.data.value?.unavailable_funded_models ?? [])
const modelOptions = computed(() => {
  const q = quota.data.value
  const runnable = availableModels.value.map((model) => {
    const free = q?.free_model_aliases.includes(model.public_alias) ?? false
    const purchased = q?.fallback_model_aliases.includes(model.public_alias) ?? false
    const access = free && purchased ? 'Free + purchased' : free ? 'Daily free' : purchased ? 'Purchased' : 'Unavailable'
    return { label: `${model.display_name} · ${access}`, value: model.public_alias }
  })
  const blocked = unavailableFundedModels.value.map(model => ({
    label: `${model.display_name} · Temporarily unavailable`,
    value: `blocked:${model.public_alias}`,
    disabled: true
  }))
  return [...runnable, ...blocked]
})
const selectedModel = computed<PlaygroundModel | null>(() => allModels.value.find(model => model.public_alias === selectedAlias.value) ?? null)

const preferredProtocol = (model: PlaygroundModel | null): PlaygroundProtocol | null => {
  if (!model) return null

  const configured = model.capabilities.playground_protocol
  if (configured === 'chat_completions' && model.capabilities.chat_completions_api === true) return configured
  if (configured === 'messages' && model.capabilities.messages_api === true) return configured
  if (configured === 'responses' && model.capabilities.responses_api === true) return configured

  // OmniRoute Chat Completions gives the hosted Playground the cleanest
  // incremental-delta + final-usage contract, so prefer it whenever the exact
  // custom model verified that endpoint. External customers still retain every
  // published protocol on the alias.
  if (model.capabilities.chat_completions_api === true) return 'chat_completions'
  if (model.capabilities.messages_api === true) return 'messages'
  if (model.capabilities.responses_api === true) return 'responses'
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
  const candidate = q?.default_model_alias || q?.available_model_aliases?.[0] || allModels.value[0]?.public_alias
  if (!selectedAlias.value || !availableModels.value.some(model => model.public_alias === selectedAlias.value)) {
    selectedAlias.value = availableModels.value.some(model => model.public_alias === candidate)
      ? candidate
      : availableModels.value[0]?.public_alias
  }
  if (q) maxOutputTokens.value = Math.min(maxOutputTokens.value, q.max_output_tokens || 1024)
}, { immediate: true })

const dailyRemaining = computed(() => Math.max(0, Number(quota.data.value?.remaining) || 0))
const dailyFundingForSelectedModel = computed(() => {
  const q = quota.data.value
  if (!q || !selectedAlias.value) return false
  return dailyRemaining.value > 0 && q.free_model_aliases.includes(selectedAlias.value)
})
const balanceAvailableForSelectedModel = computed(() => {
  const q = quota.data.value
  if (!q || !selectedAlias.value) return false
  return q.fallback_available && q.fallback_model_aliases.includes(selectedAlias.value)
})
const selectedIsFreeEligible = computed(() => Boolean(
  selectedAlias.value && quota.data.value?.free_model_aliases.includes(selectedAlias.value)
))
// Choosing a purchased-only model is already an explicit decision to spend its
// purchased entitlement. For a model that also has daily-free access, SP Cambo
// still requires the existing explicit opt-in before falling back to paid balance.
const paidOnlyFunding = computed(() => !selectedIsFreeEligible.value && balanceAvailableForSelectedModel.value)
const balanceFundingForSelectedModel = computed(() => paidOnlyFunding.value || (useBalanceFallback.value && balanceAvailableForSelectedModel.value))
const fundingForSelectedModel = computed(() => dailyFundingForSelectedModel.value || balanceFundingForSelectedModel.value)
const quotaExhausted = computed(() => quota.data.value?.enabled === true && selectedIsFreeEligible.value && !dailyFundingForSelectedModel.value)
const selectedBalance = computed(() => quota.data.value?.model_balances.find(row => row.alias === selectedAlias.value) ?? null)
const canSend = computed(() => Boolean(
  quota.data.value?.enabled && selectedAlias.value && protocol.value && composer.value.trim() && fundingForSelectedModel.value && !sending.value
))
const activeFundingLabel = computed(() => dailyFundingForSelectedModel.value ? 'Daily free' : paidOnlyFunding.value ? 'Purchased balance' : balanceFundingForSelectedModel.value ? 'Customer balance' : 'No funding selected')
const freePercent = computed(() => {
  const q = quota.data.value
  if (!q?.limit) return 0
  return Math.max(0, Math.min(100, Math.round((q.remaining / q.limit) * 100)))
})
const liveRequestStates: RequestActivity['state'][] = ['reserved', 'connecting', 'streaming']
const runningRequests = computed(() => recentActivity.value.filter(row => liveRequestStates.includes(row.state)))
const lastRequest = computed(() => {
  if (lastRequestId.value) return recentActivity.value.find(row => row.id === lastRequestId.value) ?? recentActivity.value[0] ?? null
  return recentActivity.value[0] ?? null
})

const formatUnits = (value: number | string | null | undefined) => new Intl.NumberFormat().format(Math.max(0, Number(value) || 0))
const elapsed = (row: RequestActivity) => {
  if (row.duration_ms !== null) {
    const ms = Math.max(0, row.duration_ms)
    return ms < 1000 ? `${ms.toLocaleString()} ms` : `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} s`
  }
  if (!liveRequestStates.includes(row.state)) return 'Finished'
  return `${Math.max(0, Math.round((liveClock.value - new Date(row.started_at).getTime()) / 100) / 10)} s live`
}
const stateColor = (state: RequestActivity['state']) => state === 'settled' ? 'success' : ['failed', 'released'].includes(state) ? 'error' : state === 'reconciling' ? 'warning' : 'primary'
const stateLabel = (state: RequestActivity['state']) => state === 'reconciling' ? 'Billing pending' : state === 'settled' ? 'Settled' : state === 'released' ? 'Released' : state === 'failed' ? 'Failed' : state === 'streaming' ? 'Streaming' : state === 'connecting' ? 'Connecting' : 'Reserved'
const requestUnits = (row: RequestActivity) => row.metered_units !== null ? `${formatUnits(row.metered_units)} units` : row.reserved_units !== null ? `${formatUnits(row.reserved_units)} reserved` : '—'
const scrollToBottom = (behavior: ScrollBehavior = 'smooth') => nextTick(() => chatScroll.value?.scrollTo({ top: chatScroll.value.scrollHeight, behavior }))

const refreshActivity = async () => {
  if (typeof api.account.activity !== 'function') return
  try {
    recentActivity.value = await api.account.activity({ limit: 8 })
  } catch { /* telemetry is non-blocking */ }
}
const refreshHistory = async () => {
  historyLoading.value = true
  historyError.value = null
  try {
    chatHistory.value = await api.account.playgroundChats({ limit: 30 })
  } catch (error) {
    historyError.value = error instanceof Error ? error.message : 'Chat history could not be loaded.'
  } finally {
    historyLoading.value = false
  }
}

const chatTitle = () => {
  const first = messages.value.find(message => message.role === 'user' && message.content.trim())?.content.trim() || 'New chat'
  return first.replace(/\s+/g, ' ').slice(0, 64)
}

const persistCurrentChat = async (refresh = true): Promise<boolean> => {
  const storedMessages = messages.value
    .filter(message => message.content.trim() !== '')
    .slice(-60)

  if (storedMessages.length === 0) return true

  historySaving.value = true
  const payload = {
    title: chatTitle(),
    model_alias: selectedAlias.value ?? null,
    system_prompt: systemPrompt.value.trim() || null,
    messages: storedMessages
  }

  try {
    if (currentChatId.value) {
      await api.account.updatePlaygroundChat(currentChatId.value, payload)
    } else {
      const created = await api.account.createPlaygroundChat(payload)
      currentChatId.value = created.id
    }
    historyError.value = null
    if (refresh) await refreshHistory()
    return true
  } catch (error) {
    historyError.value = error instanceof Error ? error.message : 'This chat could not be saved.'
    return false
  } finally {
    historySaving.value = false
  }
}

const openHistory = async () => {
  if (historyOpen.value) {
    historyOpen.value = false
    return
  }

  historyOpen.value = true
  historyError.value = null

  // This also adopts conversations that were already open before the history
  // feature was deployed (for example after a hot reload from Fix26 -> Fix27).
  // A partially streaming assistant turn is intentionally not persisted.
  if (!sending.value && messages.value.some(message => message.content.trim() !== '')) {
    await persistCurrentChat(false)
  }
  await refreshHistory()
}

const openChat = async (id: number) => {
  if (sending.value) return
  if (currentChatId.value !== id) await persistCurrentChat()
  historyLoading.value = true
  historyError.value = null
  try {
    const chat = await api.account.playgroundChat(id)
    currentChatId.value = chat.id
    messages.value = chat.messages.slice(-60)
    systemPrompt.value = chat.system_prompt ?? ''
    if (chat.model_alias && availableModels.value.some(model => model.public_alias === chat.model_alias)) selectedAlias.value = chat.model_alias
    errorMessage.value = null
    lastRawResponse.value = null
    lastRequestId.value = null
    historyOpen.value = false
    void scrollToBottom('auto')
  } catch (error) {
    historyError.value = error instanceof Error ? error.message : 'This chat could not be opened.'
    await refreshHistory()
  } finally {
    historyLoading.value = false
  }
}

const deleteChat = async (id: number) => {
  if (sending.value) return
  try {
    await api.account.deletePlaygroundChat(id)
    if (currentChatId.value === id) {
      currentChatId.value = null
      messages.value = []
    }
    await refreshHistory()
  } catch (error) {
    historyError.value = error instanceof Error ? error.message : 'This chat could not be deleted.'
  }
}

const clearHistory = async () => {
  if (sending.value || chatHistory.value.length === 0) return
  if (import.meta.client && !window.confirm('Delete all saved Playground chats? This cannot be undone.')) return
  try {
    await api.account.clearPlaygroundChats()
    currentChatId.value = null
    messages.value = []
    await refreshHistory()
  } catch (error) {
    historyError.value = error instanceof Error ? error.message : 'Chat history could not be cleared.'
  }
}

const refreshResources = async () => {
  await Promise.all([quota.refresh(), refreshActivity(), refreshHistory()])
}
const newChat = async () => {
  if (sending.value) return
  await persistCurrentChat()
  currentChatId.value = null
  messages.value = []
  composer.value = ''
  errorMessage.value = null
  lastRawResponse.value = null
  lastRequestId.value = null
  historyOpen.value = false
}
const chooseStarter = (prompt: string) => {
  composer.value = prompt
  nextTick(() => document.querySelector<HTMLTextAreaElement>('textarea[data-playground-composer]')?.focus())
}
const setPersona = (prompt: string) => {
  systemPrompt.value = prompt
}

const send = async () => {
  const text = composer.value.trim()
  if (!canSend.value || !text || !selectedAlias.value || !protocol.value) return

  messages.value.push({ role: 'user', content: text })
  await persistCurrentChat()
  const outboundMessages = messages.value.slice(-30)
  composer.value = ''
  sending.value = true
  receivedStreamDelta.value = false
  errorMessage.value = null

  const assistantIndex = messages.value.length
  messages.value.push({ role: 'assistant', content: '' })
  void scrollToBottom()

  const input = {
    model: selectedAlias.value,
    protocol: protocol.value,
    system_prompt: systemPrompt.value.trim() || null,
    messages: outboundMessages,
    max_output_tokens: Math.min(
      Number(maxOutputTokens.value) || 1024,
      quota.data.value?.max_output_tokens ?? 2048,
      selectedModel.value?.capabilities.max_output_tokens ?? Number.MAX_SAFE_INTEGER
    ),
    temperature: temperatureEnabled.value ? Number(temperature.value) : null,
    funding_source: dailyFundingForSelectedModel.value ? 'daily' as const : 'balance' as const
  }

  streamController = new AbortController()
  try {
    await api.account.streamPlayground(input, {
      onMeta: (data) => {
        if (data.request_id) lastRequestId.value = data.request_id
      },
      onDelta: (delta) => {
        const target = messages.value[assistantIndex]
        if (!target || target.role !== 'assistant') return
        target.content += delta
        receivedStreamDelta.value = true
        void scrollToBottom('auto')
      },
      onDone: (data) => {
        if (data.request_id) lastRequestId.value = data.request_id
        lastRawResponse.value = data.response ?? {
          streamed: true,
          protocol: data.protocol ?? protocol.value,
          event_count: data.event_count ?? null,
          text_length: data.text_length ?? null
        }
      }
    }, streamController.signal)

    const assistant = messages.value[assistantIndex]
    if (assistant && assistant.role === 'assistant' && assistant.content.trim() === '') {
      assistant.content = 'The model completed without a text response. Open live usage to inspect the settled request.'
    }

    // Give the activity endpoint a brief moment to publish the final state after
    // the gateway has completed settlement/reconciliation and closed the stream.
    await new Promise(resolve => setTimeout(resolve, 150))
    await quota.refresh()
    await refreshActivity()
    await persistCurrentChat()
    void scrollToBottom()
  } catch (error) {
    const assistant = messages.value[assistantIndex]
    const aborted = error instanceof DOMException && error.name === 'AbortError'
    if (assistant && assistant.role === 'assistant' && assistant.content.trim() === '') {
      messages.value.splice(assistantIndex, 1)
    }
    if (!aborted) {
      errorMessage.value = error instanceof Error ? error.message : 'The Playground request could not be completed.'
    }
    await quota.refresh()
    await refreshActivity()
    await persistCurrentChat()
  } finally {
    streamController = null
    sending.value = false
    receivedStreamDelta.value = false
  }
}

const stopGenerating = () => {
  if (!sending.value) return
  streamController?.abort()
}

const redeem = async () => {
  const code = redeemCode.value.trim()
  if (!code || redeeming.value) return
  redeeming.value = true
  try {
    const result = await api.account.redeemCode({ code, idempotency_key: `playground:${Date.now()}:${Math.random().toString(36).slice(2)}` })
    redeemCode.value = ''
    await quota.refresh()
    if (quota.data.value?.fallback_available && selectedAlias.value && quota.data.value.fallback_model_aliases.includes(selectedAlias.value)) {
      useBalanceFallback.value = true
    }
    toast.add({ title: 'Redeem code applied', description: `${result.units} ${result.billing_mode === 'TOKEN_QUOTA' ? 'token units' : 'credit units'} added. Playground balance fallback is ready when daily free quota is unavailable.`, color: 'success' })
  } catch (error) {
    toast.add({ title: 'Redeem failed', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally { redeeming.value = false }
}

onMounted(() => {
  if (window.matchMedia('(max-width: 1023px)').matches) showInspector.value = false
  void refreshActivity()
  void refreshHistory()
  activityTimer = setInterval(() => void refreshActivity(), 3000)
  clockTimer = setInterval(() => {
    liveClock.value = Date.now()
  }, 1000)
})
onBeforeUnmount(() => {
  streamController?.abort()
  if (activityTimer) clearInterval(activityTimer)
  if (clockTimer) clearInterval(clockTimer)
})
</script>

<template>
  <SpDashboardPage title="Playground">
    <template #actions>
      <div class="flex items-center gap-1.5 sm:gap-2">
        <UBadge v-if="runningRequests.length" color="primary" variant="subtle" class="hidden sm:inline-flex">
          <span class="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-current" />{{ runningRequests.length }} live
        </UBadge>
        <UButton color="neutral" variant="ghost" icon="i-lucide-history" aria-label="Chat history" @click="openHistory">
          <span class="hidden sm:inline">History</span>
        </UButton>
        <UButton color="neutral" variant="subtle" icon="i-lucide-plus" :disabled="sending" aria-label="New chat" @click="newChat">
          <span class="hidden sm:inline">New chat</span>
        </UButton>
      </div>
    </template>

    <SpAsyncSection
      :loading="quota.initialLoading.value"
      :unavailable="quota.unavailable.value"
      :failed="quota.failed.value"
      :error-message="quota.error.value?.message"
      error-title="Playground could not be loaded"
      @retry="refreshResources"
    >
      <div v-if="quota.data.value" class="sp-playground-shell relative overflow-hidden rounded-none border-y border-default bg-default/20 shadow-sm sm:rounded-2xl sm:border">
        <div v-if="historyOpen" class="absolute inset-0 z-40 bg-black/35 lg:bg-black/15" @click.self="historyOpen = false">
          <aside class="absolute inset-y-0 left-0 flex w-full max-w-[20rem] max-sm:max-w-none flex-col border-r border-default bg-elevated/95 shadow-2xl backdrop-blur-xl">
            <div class="flex min-h-14 items-center justify-between gap-2 border-b border-default px-3.5">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-highlighted">Chat history</p>
                <p class="text-[10px] text-muted">30 chats · 30-day rolling retention</p>
              </div>
              <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-x" aria-label="Close history" @click="historyOpen = false" />
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-2.5">
              <div v-if="(historyLoading || historySaving) && chatHistory.length === 0" class="flex items-center gap-2 px-2 py-4 text-xs text-muted"><UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />{{ historySaving ? 'Saving current chat…' : 'Loading chats…' }}</div>
              <UAlert v-else-if="historyError" color="error" variant="subtle" :description="historyError" class="mb-2" />
              <div v-if="!historyLoading && chatHistory.length === 0" class="px-3 py-10 text-center">
                <UIcon name="i-lucide-message-square-dashed" class="mx-auto size-8 text-dimmed" />
                <p class="mt-3 text-sm font-medium text-highlighted">No saved chats yet</p>
                <p class="mt-1 text-xs leading-5 text-muted">Send a message to create a saved chat. Opening History also saves the conversation currently on screen.</p>
              </div>
              <div v-else class="space-y-1">
                <div v-for="chat in chatHistory" :key="chat.id" class="group flex items-center gap-1 rounded-xl border p-1 transition" :class="currentChatId === chat.id ? 'border-primary/30 bg-primary/10' : 'border-transparent hover:border-default hover:bg-muted/35'">
                  <button type="button" class="min-w-0 flex-1 rounded-lg px-2.5 py-2 text-left" :disabled="sending" @click="openChat(chat.id)">
                    <p class="truncate text-xs font-medium text-highlighted">{{ chat.title }}</p>
                    <div class="mt-1 flex items-center gap-1.5 text-[10px] text-muted">
                      <span class="max-w-28 truncate font-mono">{{ chat.model_alias || 'model' }}</span>
                      <span>·</span>
                      <span>{{ chat.message_count }} msgs</span>
                    </div>
                    <p v-if="chat.last_message_at" class="mt-1 text-[10px] text-dimmed">{{ formatDateTime(chat.last_message_at) }}</p>
                  </button>
                  <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-trash-2" aria-label="Delete chat" :disabled="sending" class="opacity-70 sm:opacity-0 sm:group-hover:opacity-100" @click.stop="deleteChat(chat.id)" />
                </div>
              </div>
            </div>
            <div class="border-t border-default p-3">
              <p class="mb-2 text-[10px] leading-4 text-muted">Active chats renew their 30-day retention. The oldest chat is removed automatically after you reach 30 saved chats.</p>
              <UButton color="neutral" variant="ghost" size="sm" block icon="i-lucide-trash-2" :disabled="sending || chatHistory.length === 0" @click="clearHistory">Clear history</UButton>
            </div>
          </aside>
        </div>

        <div class="flex h-[calc(100dvh-7rem)] min-h-[32rem] min-w-0 flex-col sm:h-[calc(100dvh-7.5rem)] lg:h-[calc(100dvh-7.25rem)] xl:flex-row">
          <section class="flex min-w-0 flex-1 flex-col bg-default/10">
            <header class="flex min-h-14 flex-wrap items-center justify-between gap-2 border-b border-default bg-elevated/35 px-3 py-2.5 sm:px-4">
              <div class="flex min-w-0 flex-1 items-center gap-2">
                <USelectMenu
                  v-model="selectedAlias"
                  :items="modelOptions"
                  value-key="value"
                  class="w-full min-w-44 max-w-72"
                  :disabled="quota.data.value?.allow_model_switching === false && (quota.data.value?.fallback_model_aliases.length ?? 0) === 0"
                  placeholder="Choose model"
                />
                <UBadge color="neutral" variant="subtle" class="hidden sm:inline-flex">{{ protocolLabel }}</UBadge>
                <UBadge :color="fundingForSelectedModel ? 'success' : 'warning'" variant="subtle" class="hidden md:inline-flex">{{ activeFundingLabel }}</UBadge>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="hidden items-center gap-2 rounded-lg border border-default bg-default/30 px-2.5 py-1.5 text-xs lg:flex">
                  <span class="text-muted">Free</span>
                  <strong class="sp-numeric text-highlighted">{{ formatUnits(dailyRemaining) }}</strong>
                  <div class="h-1.5 w-14 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${freePercent}%` }" /></div>
                </div>
                <UButton
                  size="sm"
                  color="neutral"
                  variant="ghost"
                  :icon="showInspector ? 'i-lucide-panel-right-close' : 'i-lucide-panel-right-open'"
                  :aria-label="showInspector ? 'Hide controls' : 'Show controls'"
                  @click="showInspector = !showInspector"
                />
              </div>
            </header>

            <div ref="chatScroll" class="min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-smooth">
              <div class="mx-auto w-full max-w-5xl px-4 py-7 sm:px-6 lg:px-8">
                <div v-if="messages.length === 0" class="mx-auto flex min-h-[28rem] max-w-3xl flex-col items-center justify-center text-center">
                  <div class="mb-4 flex size-12 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                    <UIcon name="i-lucide-sparkles" class="size-6" />
                  </div>
                  <h2 class="text-2xl font-semibold tracking-tight text-highlighted">What can I help you build?</h2>
                  <p class="mt-2 max-w-xl text-sm leading-6 text-muted">Choose a starter or type naturally. Long answers stay inside this chat area, and code gets its own copyable editor-style block.</p>
                  <div class="mt-7 grid w-full gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                      v-for="starter in starters"
                      :key="starter.label"
                      type="button"
                      class="group flex items-center gap-3 rounded-xl border border-default bg-elevated/25 px-3.5 py-3 text-left text-sm text-default transition hover:border-primary/35 hover:bg-primary/5"
                      @click="chooseStarter(starter.prompt)"
                    >
                      <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted transition group-hover:text-primary"><UIcon :name="starter.icon" class="size-4" /></span>
                      <span>{{ starter.label }}</span>
                    </button>
                  </div>
                </div>

                <div v-else class="space-y-7 pb-4">
                  <SpPlaygroundMessage
                    v-for="(message, index) in messages"
                    :key="index"
                    :role="message.role"
                    :content="message.content"
                    :streaming="sending && receivedStreamDelta && message.role === 'assistant' && index === messages.length - 1"
                  />
                  <div v-if="sending && !receivedStreamDelta" class="mx-auto flex w-full max-w-4xl items-center gap-3 text-sm text-muted">
                    <span class="flex size-7 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary"><UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" /></span>
                    <span>Connecting to {{ selectedModel?.display_name || 'model' }}…</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="sticky bottom-0 z-20 bg-gradient-to-t from-default via-default/96 to-transparent px-2.5 pb-[max(.65rem,env(safe-area-inset-bottom))] pt-5 sm:px-4 sm:pb-3 sm:pt-6">
              <div class="mx-auto w-full max-w-4xl">
                <UAlert
                  v-if="errorMessage"
                  class="mb-2"
                  color="error"
                  variant="subtle"
                  icon="i-lucide-circle-alert"
                  title="Request failed"
                  :description="errorMessage"
                />
                <UAlert
                  v-if="selectedModel && !protocol"
                  class="mb-2"
                  color="warning"
                  variant="subtle"
                  title="No published chat protocol"
                  description="Enable Responses API, Anthropic Messages or Chat Completions for this alias."
                />
                <div class="rounded-[1.35rem] border border-default/90 bg-elevated/92 p-2 shadow-[0_14px_42px_-24px_rgba(0,0,0,.85)] backdrop-blur-xl transition focus-within:border-primary/45 focus-within:shadow-[0_18px_50px_-26px_rgba(49,105,255,.48)] focus-within:ring-2 focus-within:ring-primary/10">
                  <UTextarea
                    v-model="composer"
                    data-playground-composer
                    :rows="1"
                    autoresize
                    class="w-full text-[16px] sm:text-sm"
                    placeholder="Message SP Cambo…"
                    :disabled="!quota.data.value?.enabled"
                    @keydown.enter.exact.prevent="send"
                  />
                  <div class="mt-1.5 flex items-center justify-between gap-2 px-1 pb-0.5">
                    <div class="flex min-w-0 items-center gap-2 text-xs text-muted">
                      <span class="hidden sm:inline">Enter to send · Shift+Enter for new line</span>
                      <span class="max-w-36 truncate font-mono text-[10px] text-dimmed sm:hidden">{{ selectedAlias || 'Choose model' }}</span>
                      <UBadge v-if="useBalanceFallback" color="success" variant="outline" size="sm">Balance fallback</UBadge>
                    </div>
                    <UButton
                      v-if="sending"
                      size="sm"
                      color="error"
                      variant="solid"
                      icon="i-lucide-square"
                      aria-label="Stop generating"
                      @click="stopGenerating"
                    >
                      <span class="hidden sm:inline">Stop</span>
                    </UButton>
                    <UButton
                      v-else
                      size="sm"
                      icon="i-lucide-arrow-up"
                      :disabled="!canSend"
                      aria-label="Send message"
                      @click="send"
                    >
                      <span class="hidden sm:inline">Send</span>
                    </UButton>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <aside
            v-if="showInspector"
            class="z-30 flex w-[21rem] shrink-0 flex-col border-l border-default bg-elevated/45 backdrop-blur-xl max-xl:absolute max-xl:inset-y-0 max-xl:right-0 max-xl:w-[min(21rem,94vw)] max-sm:w-full max-xl:shadow-2xl"
          >
            <div class="flex min-h-14 items-center justify-between gap-2 border-b border-default px-3 py-2.5">
              <div class="flex items-center gap-1 rounded-lg bg-muted/55 p-1">
                <UButton size="xs" :color="inspectorTab === 'setup' ? 'primary' : 'neutral'" :variant="inspectorTab === 'setup' ? 'soft' : 'ghost'" icon="i-lucide-sliders-horizontal" @click="inspectorTab = 'setup'">Setup</UButton>
                <UButton size="xs" :color="inspectorTab === 'usage' ? 'primary' : 'neutral'" :variant="inspectorTab === 'usage' ? 'soft' : 'ghost'" icon="i-lucide-activity" @click="inspectorTab = 'usage'">Usage</UButton>
              </div>
              <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-x" aria-label="Close controls" @click="showInspector = false" />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-4">
              <div v-if="inspectorTab === 'setup'" class="space-y-5">
                <div class="rounded-xl border border-default bg-default/25 p-3 text-xs">
                  <div class="flex items-center justify-between gap-3"><span class="text-muted">Protocol</span><strong>{{ protocolLabel }}</strong></div>
                  <div class="mt-2 flex items-center justify-between gap-3"><span class="text-muted">Alias</span><span class="max-w-44 truncate font-mono text-[11px] text-highlighted">{{ selectedAlias || '—' }}</span></div>
                  <div class="mt-2 flex items-center justify-between gap-3"><span class="text-muted">Funding</span><UBadge :color="fundingForSelectedModel ? 'success' : 'warning'" variant="subtle" size="sm">{{ activeFundingLabel }}</UBadge></div>
                </div>

                <UFormField label="Maximum output tokens">
                  <UInputNumber v-model="maxOutputTokens" :min="1" :max="quota.data.value?.max_output_tokens ?? 65536" class="w-full" />
                </UFormField>
                <USwitch v-model="temperatureEnabled" label="Custom temperature" />
                <UFormField v-if="temperatureEnabled" label="Temperature">
                  <UInputNumber v-model="temperature" :min="0" :max="2" :step="0.1" class="w-full" />
                </UFormField>

                <div>
                  <p class="mb-2 text-xs font-medium text-muted">Quick roles</p>
                  <div class="flex flex-wrap gap-2">
                    <UButton v-for="persona in personaPrompts" :key="persona.label" size="xs" color="neutral" variant="subtle" @click="setPersona(persona.value)">{{ persona.label }}</UButton>
                  </div>
                </div>
                <UFormField label="System prompt">
                  <UTextarea v-model="systemPrompt" :rows="6" class="w-full" placeholder="Optional assistant role or instructions" />
                </UFormField>

                <UAlert
                  v-if="unavailableFundedModels.length"
                  color="warning"
                  variant="subtle"
                  icon="i-lucide-triangle-alert"
                  title="Purchased model unavailable"
                  description="Your purchased balance is preserved until its exact route is runnable again."
                />
              </div>

              <div v-else class="space-y-5">
                <section v-if="lastRequest" class="rounded-xl border border-default bg-default/25 p-3.5">
                  <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-highlighted">Last request</h3>
                    <UBadge :color="stateColor(lastRequest.state)" variant="subtle" size="sm">{{ stateLabel(lastRequest.state) }}</UBadge>
                  </div>
                  <div class="space-y-2.5 text-xs">
                    <div class="flex justify-between gap-3"><span class="text-muted">Provider</span><strong>{{ lastRequest.provider || 'Resolving…' }}</strong></div>
                    <div class="flex justify-between gap-3"><span class="text-muted">Public model</span><span class="max-w-44 truncate font-mono text-[11px]">{{ lastRequest.public_model }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-muted">Route model</span><span class="max-w-44 truncate font-mono text-[11px]">{{ lastRequest.internal_model || '—' }}</span></div>
                  </div>
                  <div class="mt-3 grid grid-cols-3 gap-2 border-t border-default pt-3 text-center">
                    <div class="rounded-lg border border-success/20 bg-success/5 p-2"><p class="text-[10px] uppercase tracking-wide text-success/80">Input</p><strong class="sp-numeric mt-1 block text-sm text-success">{{ lastRequest.input_tokens ?? '—' }}</strong></div>
                    <div class="rounded-lg border border-error/20 bg-error/5 p-2"><p class="text-[10px] uppercase tracking-wide text-error/80">Output</p><strong class="sp-numeric mt-1 block text-sm text-error">{{ lastRequest.output_tokens ?? '—' }}</strong></div>
                    <div class="rounded-lg border border-warning/20 bg-warning/5 p-2"><p class="text-[10px] uppercase tracking-wide text-warning/80">Duration</p><strong class="sp-numeric mt-1 block text-sm text-warning">{{ elapsed(lastRequest) }}</strong></div>
                  </div>
                  <div class="mt-2 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-primary/80">Units</p>
                    <strong class="sp-numeric mt-1 block text-sm text-primary">{{ requestUnits(lastRequest) }}</strong>
                    <p v-if="lastRequest.state === 'reconciling'" class="mt-1 text-[11px] leading-4 text-warning">The model response finished. Billing is being reconciled; this request is not still running.</p>
                  </div>
                  <UButton to="/dashboard/usage" color="neutral" variant="subtle" block size="sm" class="mt-3" trailing-icon="i-lucide-arrow-right">Open usage</UButton>
                </section>

                <section class="rounded-xl border border-default bg-default/25 p-3.5">
                  <div class="flex items-center justify-between gap-3"><span class="text-xs text-muted">Daily free remaining</span><strong class="sp-numeric text-sm text-highlighted">{{ formatUnits(dailyRemaining) }}</strong></div>
                  <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${freePercent}%` }" /></div>
                  <div class="mt-3 grid grid-cols-3 gap-1.5 text-center text-[10px]">
                    <div class="rounded-lg bg-muted/35 p-2"><p class="text-muted">Redeemed</p><strong class="sp-numeric mt-1 block text-default">{{ formatUnits(quota.data.value?.redeem_token_remaining ?? 0) }}</strong></div>
                    <div class="rounded-lg bg-muted/35 p-2"><p class="text-muted">Purchased</p><strong class="sp-numeric mt-1 block text-default">{{ formatUnits(quota.data.value?.paid_token_remaining ?? 0) }}</strong></div>
                    <div class="rounded-lg bg-muted/35 p-2"><p class="text-muted">Credit</p><strong class="sp-numeric mt-1 block text-default">{{ formatUnits(quota.data.value?.paid_credit_remaining ?? 0) }}</strong></div>
                  </div>
                </section>

                <section v-if="(quota.data.value?.model_balances.length ?? 0) > 0" class="space-y-2">
                  <p class="text-xs font-medium text-muted">Model access</p>
                  <div v-for="modelBalance in quota.data.value?.model_balances ?? []" :key="modelBalance.alias" class="rounded-xl border border-default bg-default/25 p-3 text-xs">
                    <div class="flex items-center justify-between gap-2"><strong class="truncate font-mono text-[11px] text-highlighted">{{ modelBalance.alias }}</strong><UBadge :color="modelBalance.balance_available ? 'success' : 'neutral'" variant="subtle" size="sm">{{ modelBalance.free_eligible && modelBalance.balance_available ? 'Free + purchased' : modelBalance.free_eligible ? 'Daily free' : 'Purchased' }}</UBadge></div>
                    <div class="mt-2 space-y-1 text-muted"><p v-if="modelBalance.token_remaining > 0">Purchased: <strong class="sp-numeric text-default">{{ formatUnits(modelBalance.token_remaining) }}</strong> tokens</p><p v-if="modelBalance.credit_remaining > 0">Credit: <strong class="sp-numeric text-default">{{ formatUnits(modelBalance.credit_remaining) }}</strong></p><p v-if="modelBalance.next_expires_at">Expires {{ formatDateTime(modelBalance.next_expires_at) }}</p></div>
                  </div>
                </section>

                <UAlert v-if="quotaExhausted" color="warning" variant="subtle" title="Daily quota exhausted" :description="balanceAvailableForSelectedModel ? 'Enable customer balance to continue.' : 'Redeem a code, buy a package, or wait for the daily reset.'" />
                <UButton v-if="quotaExhausted && balanceAvailableForSelectedModel" class="w-full" :color="useBalanceFallback ? 'success' : 'primary'" :variant="useBalanceFallback ? 'soft' : 'solid'" :icon="useBalanceFallback ? 'i-lucide-circle-check' : 'i-lucide-wallet-cards'" @click="useBalanceFallback = !useBalanceFallback">{{ useBalanceFallback ? 'Customer balance enabled' : 'Continue with customer balance' }}</UButton>
                <div class="flex gap-2"><UInput v-model="redeemCode" class="min-w-0 flex-1" placeholder="Redeem code" @keyup.enter="redeem" /><UButton color="neutral" variant="subtle" :loading="redeeming" :disabled="!redeemCode.trim()" @click="redeem">Redeem</UButton></div>

                <details v-if="lastRawResponse !== null" class="rounded-xl border border-default bg-default/25 p-3 text-xs">
                  <summary class="cursor-pointer font-medium text-highlighted">Raw response</summary>
                  <p v-if="lastRequestId" class="mt-2 break-all font-mono text-[10px] text-dimmed">{{ lastRequestId }}</p>
                  <pre class="mt-3 max-h-60 overflow-auto whitespace-pre-wrap break-all font-mono text-[10px] leading-5 text-muted">{{ JSON.stringify(lastRawResponse, null, 2) }}</pre>
                </details>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
