<script setup lang="ts">
import type { PublicModel } from '~/types/commerce'

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

const allModels = computed(() => models.data.value ?? [])
const availableModels = computed(() => {
  const q = quota.data.value
  if (!q || q.allow_model_switching) return allModels.value
  const locked = q.default_model_alias
  if (!locked) return allModels.value.slice(0, 1)
  return allModels.value.filter(model => model.public_alias === locked)
})

const modelOptions = computed(() => availableModels.value.map(model => ({
  label: `${model.display_name} · ${model.public_alias}`,
  value: model.public_alias
})))

const selectedModel = computed<PublicModel | null>(() =>
  allModels.value.find(model => model.public_alias === selectedAlias.value) ?? null
)

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
  const candidate = q?.default_model_alias
    || q?.free_model_aliases?.[0]
    || allModels.value[0]?.public_alias

  if (!selectedAlias.value || !availableModels.value.some(model => model.public_alias === selectedAlias.value)) {
    selectedAlias.value = availableModels.value.some(model => model.public_alias === candidate)
      ? candidate
      : availableModels.value[0]?.public_alias
  }

  if (q) {
    maxOutputTokens.value = Math.min(maxOutputTokens.value, q.max_output_tokens || 1024)
  }
}, { immediate: true })

const fundingForSelectedModel = computed(() => {
  const q = quota.data.value
  if (!q || !selectedAlias.value) return false
  const dailyAvailable = q.remaining > 0 && q.free_model_aliases.includes(selectedAlias.value)
  return dailyAvailable || q.fallback_available
})

const quotaExhausted = computed(() => quota.data.value?.enabled === true && !fundingForSelectedModel.value)
const canSend = computed(() => Boolean(
  quota.data.value?.enabled
  && selectedAlias.value
  && protocol.value
  && composer.value.trim()
  && fundingForSelectedModel.value
  && !sending.value
))

const formatUnits = (value: number) => new Intl.NumberFormat().format(Math.max(0, Number(value) || 0))

const refreshResources = async () => {
  await Promise.all([models.refresh(), quota.refresh()])
}

const newChat = () => {
  messages.value = []
  composer.value = ''
  errorMessage.value = null
  lastRawResponse.value = null
  lastRequestId.value = null
}

const send = async () => {
  const text = composer.value.trim()
  if (!canSend.value || !text || !selectedAlias.value || !protocol.value) return

  const userMessage: ChatMessage = { role: 'user', content: text }
  messages.value.push(userMessage)
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

    messages.value.push({
      role: 'assistant',
      content: result.message || 'The model returned a non-text response. Open the raw response below to inspect it.'
    })
    lastRawResponse.value = result.response
    lastRequestId.value = result.request_id
    quota.data.value = result.quota
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'The Playground request could not be completed.'
    await quota.refresh()
  } finally {
    sending.value = false
  }
}

const redeem = async () => {
  const code = redeemCode.value.trim()
  if (!code || redeeming.value) return

  redeeming.value = true
  try {
    const result = await api.account.redeemCode({
      code,
      idempotency_key: `playground:${Date.now()}:${Math.random().toString(36).slice(2)}`
    })
    redeemCode.value = ''
    await quota.refresh()
    toast.add({
      title: 'Redeem code applied',
      description: `${result.units} ${result.billing_mode === 'TOKEN_QUOTA' ? 'token units' : 'credit units'} added.`,
      color: 'success'
    })
  } catch (error) {
    toast.add({ title: 'Redeem failed', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally {
    redeeming.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Playground"
    eyebrow="Customer chat"
    description="Chat with models published by SP Cambo. Daily free quota is spent first; when it runs out, redeem-code or purchased balance can continue the same Playground."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-plus"
        @click="newChat"
      >
        New chat
      </UButton>
    </template>

    <SpAsyncSection
      :loading="models.initialLoading.value || quota.initialLoading.value"
      :unavailable="models.unavailable.value || quota.unavailable.value"
      :failed="models.failed.value || quota.failed.value"
      :error-message="models.error.value?.message || quota.error.value?.message"
      error-title="Playground could not be loaded"
      @retry="refreshResources"
    >
      <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_21rem]">
        <UCard class="sp-premium-card overflow-hidden">
          <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <h2 class="font-semibold text-highlighted">
                  Chat
                </h2>
                <p class="mt-1 text-sm text-muted">
                  Requests are routed through the configured SP Cambo gateway and are metered exactly like customer API traffic.
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <UBadge
                  color="neutral"
                  variant="subtle"
                >
                  {{ protocolLabel }}
                </UBadge>
                <USelectMenu
                  v-model="selectedAlias"
                  :items="modelOptions"
                  value-key="value"
                  class="w-full min-w-64 lg:w-80"
                  :disabled="quota.data.value?.allow_model_switching === false"
                  placeholder="Choose model"
                />
              </div>
            </div>
          </template>

          <div class="flex min-h-[34rem] flex-col">
            <div class="flex-1 space-y-4 overflow-y-auto py-2">
              <div
                v-if="messages.length === 0"
                class="flex min-h-80 flex-col items-center justify-center px-4 text-center"
              >
                <div class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                  <UIcon
                    name="i-lucide-sparkles"
                    class="size-6"
                  />
                </div>
                <h3 class="text-lg font-semibold text-highlighted">
                  Start a conversation
                </h3>
                <p class="mt-2 max-w-xl text-sm text-muted">
                  Choose a published model and send a message. You do not need to paste an API key into this Playground.
                </p>
              </div>

              <div
                v-for="(message, index) in messages"
                :key="index"
                class="flex"
                :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
              >
                <div
                  class="max-w-[88%] whitespace-pre-wrap rounded-2xl px-4 py-3 text-sm leading-6"
                  :class="message.role === 'user' ? 'bg-primary text-inverted' : 'border border-default bg-elevated/55 text-default'"
                >
                  {{ message.content }}
                </div>
              </div>

              <div
                v-if="sending"
                class="flex justify-start"
              >
                <div class="flex items-center gap-2 rounded-2xl border border-default bg-elevated/55 px-4 py-3 text-sm text-muted">
                  <UIcon
                    name="i-lucide-loader-circle"
                    class="size-4 animate-spin"
                  />
                  Thinking…
                </div>
              </div>
            </div>

            <UAlert
              v-if="errorMessage"
              class="mb-3"
              color="error"
              variant="subtle"
              icon="i-lucide-circle-alert"
              title="Request failed"
              :description="errorMessage"
            />

            <UAlert
              v-if="selectedModel && !protocol"
              class="mb-3"
              color="warning"
              variant="subtle"
              title="This alias has no published chat protocol"
              description="Enable Responses API, Anthropic Messages or Chat Completions on the public model alias before using it in the Playground."
            />

            <div class="border-t border-default pt-4">
              <UTextarea
                v-model="composer"
                :rows="3"
                autoresize
                class="w-full"
                placeholder="Message SP Cambo…"
                :disabled="!quota.data.value?.enabled"
                @keydown.enter.exact.prevent="send"
              />
              <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted">
                  Enter to send · Shift+Enter for a new line
                </p>
                <UButton
                  icon="i-lucide-send"
                  :loading="sending"
                  :disabled="!canSend"
                  @click="send"
                >
                  Send
                </UButton>
              </div>
            </div>
          </div>
        </UCard>

        <div class="space-y-5">
          <UCard class="sp-premium-card">
            <template #header>
              <h3 class="font-semibold text-highlighted">
                Playground balance
              </h3>
            </template>
            <div
              v-if="quota.data.value"
              class="space-y-3 text-sm"
            >
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted">Daily free</span><strong class="sp-numeric">{{ formatUnits(quota.data.value.remaining) }} / {{ formatUnits(quota.data.value.limit) }}</strong>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted">Redeem tokens</span><strong class="sp-numeric">{{ formatUnits(quota.data.value.redeem_token_remaining) }}</strong>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted">Purchased tokens</span><strong class="sp-numeric">{{ formatUnits(quota.data.value.paid_token_remaining) }}</strong>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted">Credit units</span><strong class="sp-numeric">{{ formatUnits(quota.data.value.paid_credit_remaining) }}</strong>
              </div>
              <p class="border-t border-default pt-3 text-xs text-muted">
                Daily free models: {{ quota.data.value.free_model_aliases.join(', ') || 'None configured' }}
              </p>
            </div>
          </UCard>

          <UCard
            v-if="quotaExhausted"
            class="sp-premium-card"
          >
            <template #header>
              <h3 class="font-semibold text-highlighted">
                Continue chatting
              </h3>
            </template>
            <p class="text-sm text-muted">
              The selected model has no spendable Playground balance. Buy tokens/credit or redeem a code to continue.
            </p>
            <UButton
              to="/pricing"
              class="mt-4 w-full"
              icon="i-lucide-shopping-bag"
            >
              Buy tokens / credit
            </UButton>
            <div class="mt-4 flex gap-2">
              <UInput
                v-model="redeemCode"
                class="min-w-0 flex-1"
                placeholder="Redeem code"
                @keyup.enter="redeem"
              />
              <UButton
                color="neutral"
                variant="subtle"
                :loading="redeeming"
                :disabled="!redeemCode.trim()"
                @click="redeem"
              >
                Redeem
              </UButton>
            </div>
          </UCard>

          <UCard
            v-else
            class="sp-premium-card"
          >
            <template #header>
              <h3 class="font-semibold text-highlighted">
                Redeem code
              </h3>
            </template>
            <div class="flex gap-2">
              <UInput
                v-model="redeemCode"
                class="min-w-0 flex-1"
                placeholder="SPFREE-…"
                @keyup.enter="redeem"
              />
              <UButton
                color="neutral"
                variant="subtle"
                :loading="redeeming"
                :disabled="!redeemCode.trim()"
                @click="redeem"
              >
                Apply
              </UButton>
            </div>
          </UCard>

          <UCard class="sp-premium-card">
            <template #header>
              <h3 class="font-semibold text-highlighted">
                Chat controls
              </h3>
            </template>
            <div class="space-y-4">
              <UFormField label="System prompt">
                <UTextarea
                  v-model="systemPrompt"
                  :rows="3"
                  class="w-full"
                  placeholder="Optional instructions"
                />
              </UFormField>
              <UFormField label="Maximum output tokens">
                <UInputNumber
                  v-model="maxOutputTokens"
                  :min="1"
                  :max="quota.data.value?.max_output_tokens ?? 65536"
                  class="w-full"
                />
              </UFormField>
              <USwitch
                v-model="temperatureEnabled"
                label="Custom temperature"
              />
              <UFormField
                v-if="temperatureEnabled"
                label="Temperature"
              >
                <UInputNumber
                  v-model="temperature"
                  :min="0"
                  :max="2"
                  :step="0.1"
                  class="w-full"
                />
              </UFormField>
            </div>
          </UCard>

          <details
            v-if="lastRawResponse !== null"
            class="rounded-xl border border-default bg-elevated/30 p-4 text-sm"
          >
            <summary class="cursor-pointer font-medium text-highlighted">
              Raw response
            </summary>
            <p
              v-if="lastRequestId"
              class="mt-2 font-mono text-xs text-dimmed"
            >
              {{ lastRequestId }}
            </p>
            <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap break-all text-xs text-muted">{{ JSON.stringify(lastRawResponse, null, 2) }}</pre>
          </details>
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
