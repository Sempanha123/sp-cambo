<script setup lang="ts">
import type { PlaygroundProtocol } from '~/utils/playgroundRequest'

/**
 * API playground: run a bounded server-side request with the daily free quota,
 * or build the exact customer-key request for local/CLI use. Browser code never
 * receives the system-managed Playground credential or an upstream secret.
 *
 * Everything the controls allow is bounded by what the catalogue publishes for the
 * selected alias. A protocol the alias does not state is not offered, because the
 * control plane refuses it with `model_unavailable`.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'API playground',
  description: 'Build a request against your own model alias and copy it as cURL, Python, Node or CLI configuration.',
  robots: 'noindex'
})

const api = useSpApi()
const config = useRuntimeConfig()

const models = await useSpResource('catalog:models', () => api.catalog.models(), { server: false })
const keys = await useSpResource('dashboard:api-keys', () => api.account.apiKeys(), { server: false })

/** ------------------------------------------------------------- selection */

const selectedAlias = ref<string | undefined>(undefined)

const aliasOptions = computed(() =>
  (models.data.value ?? []).map(model => ({ label: model.public_alias, value: model.public_alias }))
)

/** Default to the first alias the catalogue publishes, if any. */
watch(aliasOptions, (options) => {
  if (!selectedAlias.value && options.length > 0) {
    selectedAlias.value = options[0]!.value
  }
}, { immediate: true })

const selectedModel = computed(() =>
  (models.data.value ?? []).find(model => model.public_alias === selectedAlias.value) ?? null
)

/**
 * The protocols this alias may actually be called on.
 *
 * Only an explicit `true` counts. The control plane reads the same flags with a
 * false default, so an unstated protocol is a refused one — offering it here would
 * hand over a snippet that 400s.
 *
 * With no catalogue published there is nothing to filter on, so all three are
 * offered and the copy says the support could not be confirmed.
 */
const protocolOptions = computed(() => {
  const model = selectedModel.value

  if (!model) {
    return [...PLAYGROUND_PROTOCOLS]
  }

  return PLAYGROUND_PROTOCOLS.filter(info => model.capabilities[info.capability] === true)
})

const noStatedProtocol = computed(() => selectedModel.value !== null && protocolOptions.value.length === 0)

const protocol = ref<PlaygroundProtocol>('messages')

/** Keep the selection on something the alias actually offers. */
watch(protocolOptions, (options) => {
  if (options.length > 0 && !options.some(info => info.value === protocol.value)) {
    protocol.value = options[0]!.value
  }
}, { immediate: true })

const protocolItems = computed(() =>
  protocolOptions.value.map(info => ({ label: info.label, value: info.value, description: info.summary }))
)

/** -------------------------------------------------------------- controls */

const systemPrompt = ref('')
const userPrompt = ref(PLAYGROUND_DEFAULT_PROMPT)
const maxOutputTokens = ref(256)
const temperatureEnabled = ref(false)
const temperature = ref(0.7)
const streaming = ref(false)

/** The catalogue states this alias cannot stream, so the toggle must not offer it. */
const streamingUnsupported = computed(() => selectedModel.value?.capabilities.streaming === false)

watch(streamingUnsupported, (unsupported) => {
  if (unsupported) {
    streaming.value = false
  }
})

/**
 * A value the gateway will parse. Below 1 it answers `invalid_max_output_tokens`,
 * so the snippet carries a legal value and the field says what is wrong instead of
 * emitting a request that cannot succeed.
 */
const outputTokens = computed(() =>
  Number.isSafeInteger(maxOutputTokens.value) && maxOutputTokens.value >= 1 ? maxOutputTokens.value : 1
)

const outputTokensInvalid = computed(() => outputTokens.value !== maxOutputTokens.value)

const ceilingNote = computed(() =>
  outputCeilingNote(outputTokens.value, selectedModel.value?.capabilities.max_output_tokens)
)

/** --------------------------------------------------------------- request */

const request = computed(() => buildPlaygroundRequest({
  inferenceRootUrl: config.public.inferenceRootUrl,
  protocol: protocol.value,
  modelAlias: selectedAlias.value,
  systemPrompt: systemPrompt.value,
  userPrompt: userPrompt.value,
  maxOutputTokens: outputTokens.value,
  temperature: temperatureEnabled.value ? temperature.value : null,
  streaming: streaming.value
}))

const requestTabs = computed(() => [
  { label: 'cURL', value: 'curl', code: request.value.curl, filename: 'bash' },
  { label: 'Python', value: 'python', code: request.value.python, filename: 'main.py' },
  { label: 'Node / TypeScript', value: 'node', code: request.value.node, filename: 'main.ts' }
])

/** The raw headers as a single pasteable block, for anyone using another client. */
const headerBlock = computed(() =>
  request.value.headers.map(header => `${header.name}: ${header.value}`).join('\n')
)

/** ------------------------------------------------------------------- CLI */

const { claudeCodeShell, codexConfig } = useCliSnippets({ modelAlias: selectedAlias })

const cliTabs = computed(() => [
  { label: 'Claude Code', value: 'claude', code: claudeCodeShell.value, filename: 'bash' },
  { label: 'Codex CLI', value: 'codex', code: codexConfig.value, filename: '~/.codex/config.toml' }
])

/**
 * Whether the selected alias states the protocol each CLI speaks. `null` means the
 * catalogue has not said, in which case nothing is claimed either way.
 */
const claudeCodeUsable = computed(() => selectedModel.value?.capabilities.messages_api ?? null)
const codexUsable = computed(() => selectedModel.value?.capabilities.responses_api ?? null)

const activeKeys = computed(() => (keys.data.value ?? []).filter(key => key.status === 'ACTIVE').length)

const playgroundQuota = await useSpResource('dashboard:playground-quota', () => api.account.playgroundQuota(), { server: false })
const playgroundRunning = ref(false)
const playgroundResult = ref<unknown | null>(null)
const playgroundRequestId = ref<string | null>(null)
const playgroundError = ref<string | null>(null)

const runPlayground = async () => {
  if (!selectedAlias.value || noStatedProtocol.value || playgroundRunning.value) return
  playgroundRunning.value = true
  playgroundError.value = null
  playgroundResult.value = null
  try {
    const result = await api.account.runPlayground({
      model: selectedAlias.value,
      protocol: protocol.value,
      system_prompt: systemPrompt.value || null,
      prompt: userPrompt.value,
      max_output_tokens: Math.min(2048, outputTokens.value),
      temperature: temperatureEnabled.value ? temperature.value : null
    })
    playgroundResult.value = result.response
    playgroundRequestId.value = result.request_id
    playgroundQuota.data.value = result.quota
  } catch (error) {
    playgroundError.value = error instanceof Error ? error.message : 'The Playground request could not be completed.'
  } finally {
    playgroundRunning.value = false
  }
}

const playgroundQuotaPercent = computed(() => {
  const q = playgroundQuota.data.value
  if (!q || q.limit <= 0) return 0
  return Math.max(0, Math.min(100, Math.round((q.remaining / q.limit) * 100)))
})
</script>

<template>
  <SpDashboardPage
    title="API playground"
    icon="i-lucide-flask-conical"
    description="Build a request against one of your model aliases and copy it as cURL, an SDK call or CLI configuration. Every field is one the gateway accepts, and the host comes from this deployment's configuration."
  >
    <template #actions>
      <UButton
        to="/docs/api-reference"
        color="neutral"
        variant="subtle"
        icon="i-lucide-book-open"
      >
        API reference
      </UButton>
    </template>

    <UAlert
      icon="i-lucide-shield-check"
      color="neutral"
      variant="subtle"
      title="Server-side Playground — no API key pasted into the browser"
      description="Run a small non-streaming request with today's free Playground quota. Laravel holds an encrypted system-managed credential and calls the same SP Cambo gateway used by customer API keys, so normal reservation, routing and settlement still apply. Your own API key is never required for this button."
    />

    <section class="space-y-4">
      <SpSectionHeading
        title="Request"
        description="The alias and the protocols offered come from the published catalogue. A protocol an alias does not state is refused by the control plane, so it is not offered here."
      />

      <SpAsyncSection
        :loading="models.initialLoading.value"
        :unavailable="models.unavailable.value"
        :failed="models.failed.value"
        :offline="models.error.value?.code === 'network_unreachable'"
        :error-message="models.error.value?.message"
        unavailable-title="The model catalogue is not published yet"
        unavailable-description="Without it, SP Cambo cannot tell you which aliases exist or which protocols they accept. The request below still shows the correct shape with a placeholder alias to replace by hand."
        loading-variant="rows"
        :loading-count="3"
        @retry="models.refresh()"
      >
        <div class="grid gap-4 lg:grid-cols-2">
          <UFormField
            label="Model alias"
            :help="aliasOptions.length === 0
              ? 'No alias is published, so the request shows a placeholder to replace by hand.'
              : 'Aliases are stable across upstream routing changes.'"
          >
            <USelectMenu
              v-model="selectedAlias"
              :items="aliasOptions"
              value-key="value"
              :disabled="aliasOptions.length === 0"
              :loading="models.loading.value"
              placeholder="<your-model-alias>"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Protocol"
            :help="selectedModel
              ? 'Only surfaces this alias states are listed.'
              : 'The catalogue is not published, so support per protocol could not be confirmed.'"
          >
            <USelectMenu
              v-model="protocol"
              :items="protocolItems"
              value-key="value"
              :disabled="protocolItems.length === 0"
              class="w-full"
            />
          </UFormField>
        </div>

        <UAlert
          v-if="noStatedProtocol"
          class="mt-4"
          icon="i-lucide-triangle-alert"
          color="warning"
          variant="subtle"
          title="This alias states no inference protocol"
          :description="`${selectedAlias} is published but names none of the three surfaces. The control plane refuses a protocol an alias does not state, so every call would return model_unavailable. Nothing can be built against it — pick another alias, or ask an operator to state its protocols.`"
        />

        <div
          v-else
          class="mt-4 space-y-4"
        >
          <div class="grid gap-4 lg:grid-cols-2">
            <UFormField
              label="System prompt"
              :help="protocol === 'responses'
                ? 'Sent as instructions. Left out entirely when empty.'
                : 'Left out entirely when empty — never sent as a default.'"
            >
              <UTextarea
                v-model="systemPrompt"
                :rows="4"
                placeholder="Optional. E.g. Answer in one sentence."
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Prompt"
              required
            >
              <UTextarea
                v-model="userPrompt"
                :rows="4"
                class="w-full"
              />
            </UFormField>
          </div>

          <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <UFormField
              label="Max output tokens"
              :error="outputTokensInvalid ? 'Must be a whole number of at least 1.' : undefined"
              :help="selectedModel?.capabilities.max_output_tokens
                ? `This alias publishes a ceiling of ${formatCount(selectedModel.capabilities.max_output_tokens)}.`
                : 'No ceiling is published for this alias.'"
            >
              <UInputNumber
                v-model="maxOutputTokens"
                :min="1"
                :step="256"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Temperature"
              :help="temperatureEnabled
                ? 'Whether an upstream model honours it is not published per alias.'
                : 'Off means the field is not sent at all.'"
            >
              <div class="flex items-center gap-3">
                <USwitch v-model="temperatureEnabled" />
                <UInputNumber
                  v-model="temperature"
                  :min="0"
                  :max="2"
                  :step="0.1"
                  :disabled="!temperatureEnabled"
                  class="flex-1"
                />
              </div>
            </UFormField>

            <UFormField
              label="Streaming"
              :help="streamingUnsupported
                ? 'The catalogue states this alias does not stream.'
                : 'Sends stream: true and uses each SDK\'s streaming call.'"
            >
              <USwitch
                v-model="streaming"
                :disabled="streamingUnsupported"
                :label="streaming ? 'Server-sent events' : 'Single response'"
              />
            </UFormField>

            <UFormField
              label="Context window"
              help="Prompt plus output, as published."
            >
              <p class="sp-numeric pt-1.5 text-sm text-highlighted">
                {{ selectedModel?.capabilities.context_tokens
                  ? `${formatCount(selectedModel.capabilities.context_tokens)} tokens`
                  : 'Not published' }}
              </p>
            </UFormField>
          </div>

          <UAlert
            v-if="ceilingNote"
            icon="i-lucide-triangle-alert"
            color="warning"
            variant="subtle"
            title="Above this alias's published output ceiling"
            :description="ceilingNote"
          />
        </div>
      </SpAsyncSection>
    </section>

    <section v-if="!noStatedProtocol" class="space-y-4">
      <SpSectionHeading
        title="Run with today's free quota"
        description="This request executes on the server and is settled through the normal billing pipeline. Streaming is intentionally disabled for the browser Playground."
      />

      <div class="rounded-xl border border-default bg-elevated/30 p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-sm font-medium text-highlighted">Daily Playground quota</p>
            <p v-if="playgroundQuota.data.value" class="mt-1 text-sm text-muted">
              {{ formatCount(playgroundQuota.data.value.remaining) }} / {{ formatCount(playgroundQuota.data.value.limit) }} tokens remaining
              · resets {{ new Date(playgroundQuota.data.value.reset_at).toLocaleString() }}
            </p>
            <p v-else class="mt-1 text-sm text-muted">Loading today's quota…</p>
          </div>
          <UButton
            icon="i-lucide-play"
            :loading="playgroundRunning"
            :disabled="!selectedAlias || playgroundRunning || playgroundQuota.data.value?.enabled === false || playgroundQuota.data.value?.remaining === 0"
            @click="runPlayground"
          >
            Run free test
          </UButton>
        </div>

        <div v-if="playgroundQuota.data.value" class="mt-4 h-2 overflow-hidden rounded-full bg-muted/20">
          <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${playgroundQuotaPercent}%` }" />
        </div>

        <UAlert
          v-if="playgroundError"
          class="mt-4"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Playground request failed"
          :description="playgroundError"
        />

        <div v-if="playgroundResult !== null" class="mt-4 space-y-2">
          <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-medium text-highlighted">Response</p>
            <code v-if="playgroundRequestId" class="text-xs text-dimmed">{{ playgroundRequestId }}</code>
          </div>
          <SpCodeBlock :code="JSON.stringify(playgroundResult, null, 2)" filename="response.json" />
        </div>
      </div>
    </section>

    <section
      v-if="!noStatedProtocol"
      class="space-y-4"
    >
      <SpSectionHeading
        title="What you will send with your own key"
        :description="`POST ${request.protocol.path} — ${request.protocol.summary}`"
      />

      <div class="grid gap-4 lg:grid-cols-2">
        <div class="space-y-3">
          <div class="rounded-lg border border-default bg-elevated/30 p-4">
            <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
              Endpoint
            </p>
            <div class="mt-2 flex items-center gap-2">
              <code class="min-w-0 flex-1 truncate font-mono text-sm text-toned">
                {{ request.method }} {{ request.url }}
              </code>
              <SpCopyButton :value="request.url" />
            </div>
          </div>

          <SpCodeBlock
            :code="headerBlock"
            filename="headers"
          />

          <p class="text-xs text-muted">
            Replace the placeholder with a key you hold.
            <template v-if="activeKeys === 0">
              You have no active key —
              <NuxtLink
                to="/dashboard/api-keys"
                class="text-primary underline decoration-dotted underline-offset-4"
              >create one</NuxtLink>.
            </template>
            <template v-else>
              Lost the secret? Rotate the key on the
              <NuxtLink
                to="/dashboard/api-keys"
                class="text-primary underline decoration-dotted underline-offset-4"
              >keys page</NuxtLink>.
            </template>
          </p>
        </div>

        <SpCodeBlock
          :code="request.bodyJson"
          filename="body.json"
        />
      </div>

      <UTabs :items="requestTabs">
        <template #content="{ item }">
          <SpCodeBlock
            :code="item.code"
            :filename="item.filename"
          />
        </template>
      </UTabs>
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Or run it through a CLI"
        description="Claude Code and Codex CLI build their own request bodies, so the prompt and sampling controls above do not apply to them. What carries over is the alias."
        :level="3"
      >
        <template #actions>
          <UButton
            to="/dashboard/cli-setup"
            color="neutral"
            variant="ghost"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
          >
            Full CLI setup
          </UButton>
        </template>
      </SpSectionHeading>

      <UTabs :items="cliTabs">
        <template #content="{ item }">
          <SpCodeBlock
            :code="item.code"
            :filename="item.filename"
          />
        </template>
      </UTabs>

      <UAlert
        v-if="claudeCodeUsable === false || codexUsable === false"
        icon="i-lucide-triangle-alert"
        color="warning"
        variant="subtle"
        title="One of these CLIs cannot use this alias"
        :description="claudeCodeUsable === false && codexUsable === false
          ? `${selectedAlias} states neither the Claude Messages nor the OpenAI Responses surface, so neither CLI can call it.`
          : claudeCodeUsable === false
            ? `${selectedAlias} does not state the Claude Messages surface, so Claude Code cannot call it. Codex CLI can.`
            : `${selectedAlias} does not state the OpenAI Responses surface, so Codex CLI cannot call it. Claude Code can.`"
      />
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Where the real figures appear"
        :level="3"
      />

      <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            Tokens and latency
          </p>
          <p class="mt-1 text-sm text-muted">
            Every request is recorded with its model, token counts, outcome and duration. It appears on
            <NuxtLink
              to="/dashboard/usage"
              class="text-primary underline decoration-dotted underline-offset-4"
            >usage &amp; activity</NuxtLink>
            within seconds.
          </p>
        </div>
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            What it cost
          </p>
          <p class="mt-1 text-sm text-muted">
            The charge is calculated by SP Cambo when the request settles, from the tokens the upstream model
            actually reported. This page will not estimate it — an estimate that differs from the charge is
            worse than no figure.
          </p>
        </div>
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            What is left
          </p>
          <p class="mt-1 text-sm text-muted">
            Remaining quota and credit, per lot and with exact expiry, are on
            <NuxtLink
              to="/dashboard/entitlements"
              class="text-primary underline decoration-dotted underline-offset-4"
            >entitlements</NuxtLink>.
          </p>
        </div>
      </div>

      <p class="text-sm text-muted">
        Prompts and completions are never stored, so no request you send is replayable here. What SP Cambo
        keeps is listed in
        <NuxtLink
          to="/legal/privacy"
          class="text-primary underline decoration-dotted underline-offset-4"
        >
          the privacy notice
        </NuxtLink>.
      </p>
    </section>
  </SpDashboardPage>
</template>
