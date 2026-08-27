<script setup lang="ts">
useSeoMeta({
  title: 'For developers',
  description: 'SP Cambo exposes Anthropic Messages and OpenAI Responses compatible endpoints with streaming, tool calls, exact metering and stable machine error codes.'
})

const {
  inferenceRoot,
  openAiBase,
  claudeCodeShell,
  codexConfig,
  curlMessages,
  nodeAnthropic,
  pythonAnthropic,
  openAiPython
} = useCliSnippets()

const compatibility = computed(() => [
  {
    title: 'Anthropic Messages API',
    description: 'Point any Anthropic-compatible client at the SP Cambo gateway root. The client appends its own /v1/messages path.',
    base: inferenceRoot.value,
    icon: 'i-lucide-message-square-code',
    note: 'Do not add /v1 yourself — Anthropic clients would then request /v1/v1/messages.'
  },
  {
    title: 'OpenAI Responses API',
    description: 'Configure a custom OpenAI-compatible provider with a base URL ending in /v1 and the Responses wire API.',
    base: openAiBase.value,
    icon: 'i-lucide-braces',
    note: 'Chat Completions style clients should be configured for the Responses wire API.'
  }
])

const guarantees = [
  {
    icon: 'i-lucide-radio',
    title: 'Streaming passthrough',
    description: 'Server-sent events are streamed straight through. Usage is settled from the terminal usage payload of the stream, not guessed from token counting.'
  },
  {
    icon: 'i-lucide-wrench',
    title: 'Tool calls',
    description: 'Tool definitions and tool results pass through unchanged for models that support them. Capability flags per model are published in the catalogue.'
  },
  {
    icon: 'i-lucide-binary',
    title: 'Machine error codes',
    description: 'Every failure carries a stable snake_case code so you can branch in code instead of matching on human-readable text.'
  },
  {
    icon: 'i-lucide-shield',
    title: 'Credential isolation',
    description: 'Your SP Cambo key is terminated at our gateway. Upstream provider credentials are supplied server-side and never reach your process.'
  },
  {
    icon: 'i-lucide-timer',
    title: 'Reserve then settle',
    description: 'A request reserves an estimate before it runs and settles against reported usage afterwards. Released reservations return to your balance.'
  },
  {
    icon: 'i-lucide-list-checks',
    title: 'Per-key scoping',
    description: 'Restrict a key to specific model aliases and give each environment its own credential, so revoking one does not break the others.'
  }
]

const languageTabs = computed(() => [
  { label: 'cURL', value: 'curl', code: curlMessages.value, filename: 'bash' },
  { label: 'Node.js', value: 'node', code: nodeAnthropic.value, filename: 'index.mjs' },
  { label: 'Python', value: 'python', code: pythonAnthropic.value, filename: 'main.py' },
  { label: 'OpenAI SDK', value: 'openai', code: openAiPython.value, filename: 'main.py' }
])
</script>

<template>
  <div>
    <UContainer class="py-14 sm:py-16">
      <div class="max-w-3xl space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance">
          Built to drop into code you already have
        </h1>
        <p class="text-lg text-muted text-pretty">
          SP Cambo speaks the APIs your clients already speak. Swap the base URL and the key, keep
          the rest of your integration, and get exact metering and prepaid limits for free.
        </p>
        <div class="flex flex-wrap gap-3 pt-2">
          <UButton
            to="/docs/quick-start"
            trailing-icon="i-lucide-arrow-right"
          >
            Quick start
          </UButton>
          <UButton
            to="/docs/api-reference"
            color="neutral"
            variant="subtle"
          >
            API reference
          </UButton>
        </div>
      </div>

      <div class="mt-12 grid gap-5 lg:grid-cols-2">
        <div
          v-for="item in compatibility"
          :key="item.title"
          class="space-y-4 rounded-xl border border-default bg-elevated/30 p-6"
        >
          <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <UIcon
              :name="item.icon"
              class="size-5"
            />
          </div>
          <div class="space-y-2">
            <h2 class="text-lg font-medium text-highlighted">
              {{ item.title }}
            </h2>
            <p class="text-sm text-muted text-pretty">
              {{ item.description }}
            </p>
          </div>

          <div class="flex items-center gap-2 rounded-lg border border-default bg-default px-3 py-2">
            <code class="min-w-0 flex-1 truncate font-mono text-xs text-toned">{{ item.base }}</code>
            <SpCopyButton
              :value="item.base"
              size="sm"
            />
          </div>

          <p class="flex items-start gap-2 text-xs text-muted">
            <UIcon
              name="i-lucide-info"
              class="mt-0.5 size-3.5 shrink-0"
            />
            {{ item.note }}
          </p>
        </div>
      </div>
    </UContainer>

    <div class="border-y border-default bg-elevated/25">
      <UContainer class="py-14 sm:py-16">
        <div class="max-w-2xl space-y-3">
          <h2 class="text-3xl font-semibold tracking-tight text-highlighted">
            First request
          </h2>
          <p class="text-muted text-pretty">
            Create a key in the dashboard, pick an alias from the model catalogue, then run one of
            these. Keep the key in an environment variable — never in source control.
          </p>
        </div>

        <UTabs
          :items="languageTabs"
          class="mt-8"
          :ui="{ list: 'w-full sm:w-auto' }"
        >
          <template #content="{ item }">
            <SpCodeBlock
              :code="item.code"
              :filename="item.filename"
            />
          </template>
        </UTabs>
      </UContainer>
    </div>

    <UContainer class="py-14 sm:py-16">
      <div class="max-w-2xl space-y-3">
        <h2 class="text-3xl font-semibold tracking-tight text-highlighted">
          What the gateway guarantees
        </h2>
        <p class="text-muted text-pretty">
          The proxy is deliberately thin. It authenticates you, enforces your limits, meters the
          request and forwards it — it does not rewrite your prompts.
        </p>
      </div>

      <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="item in guarantees"
          :key="item.title"
          class="space-y-3 rounded-xl border border-default bg-elevated/30 p-6"
        >
          <UIcon
            :name="item.icon"
            class="size-5 text-primary"
          />
          <h3 class="font-medium text-highlighted">
            {{ item.title }}
          </h3>
          <p class="text-sm text-muted text-pretty">
            {{ item.description }}
          </p>
        </div>
      </div>
    </UContainer>

    <div class="border-t border-default bg-elevated/25">
      <UContainer class="py-14 sm:py-16">
        <div class="grid gap-10 lg:grid-cols-2">
          <div class="space-y-5">
            <h2 class="text-2xl font-semibold tracking-tight text-highlighted">
              Claude Code
            </h2>
            <p class="text-sm text-muted text-pretty">
              Two environment variables and an alias. The base URL must be the gateway root without
              a trailing <code class="font-mono text-xs">/v1</code>.
            </p>
            <SpCodeBlock
              filename="bash"
              :code="claudeCodeShell"
            />
            <UButton
              to="/docs/claude-code"
              color="neutral"
              variant="subtle"
              size="sm"
              trailing-icon="i-lucide-arrow-right"
            >
              Full Claude Code guide
            </UButton>
          </div>

          <div class="space-y-5">
            <h2 class="text-2xl font-semibold tracking-tight text-highlighted">
              Codex CLI
            </h2>
            <p class="text-sm text-muted text-pretty">
              Add SP Cambo as a custom model provider. The key is read from the environment, so it
              never lands in your config file.
            </p>
            <SpCodeBlock
              filename="~/.codex/config.toml"
              :code="codexConfig"
            />
            <UButton
              to="/docs/codex-cli"
              color="neutral"
              variant="subtle"
              size="sm"
              trailing-icon="i-lucide-arrow-right"
            >
              Full Codex CLI guide
            </UButton>
          </div>
        </div>
      </UContainer>
    </div>

    <UContainer class="py-14">
      <div class="flex flex-col items-start justify-between gap-4 rounded-xl border border-default bg-elevated/30 p-6 sm:flex-row sm:items-center">
        <div class="space-y-1">
          <p class="font-medium text-highlighted">
            Ready to issue your first key?
          </p>
          <p class="text-sm text-muted">
            Keys stay masked in normal lists, can be securely re-copied by the signed-in owner, and remain scoped and revocable at any time.
          </p>
        </div>
        <UButton
          to="/dashboard/api-keys"
          trailing-icon="i-lucide-arrow-right"
        >
          Manage API keys
        </UButton>
      </div>
    </UContainer>
  </div>
</template>
