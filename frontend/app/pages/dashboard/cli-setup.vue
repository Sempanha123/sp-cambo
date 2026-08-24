<script setup lang="ts">
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'CLI setup',
  description: 'Copy the exact configuration for Claude Code, Codex CLI and the SDKs, with your own model alias filled in.',
  robots: 'noindex'
})

const api = useSpApi()
const route = useRoute()

const models = await useSpResource('catalog:models', () => api.catalog.models(), { server: false })
const keys = await useSpResource('dashboard:api-keys', () => api.account.apiKeys(), { server: false })

const selectedAlias = ref<string | undefined>(undefined)

const aliasOptions = computed(() =>
  (models.data.value ?? []).map(model => ({
    label: model.public_alias,
    value: model.public_alias,
    /**
     * Whether the catalogue states Responses-API support. `null` means the
     * catalogue has not said, in which case the page must not claim either way.
     */
    responses: model.capabilities.responses_api ?? null
  }))
)

/** Default to the first alias the catalogue publishes, if any. */
watch(aliasOptions, (options) => {
  const requested = typeof route.query.model === 'string' ? route.query.model : undefined
  if (!selectedAlias.value && requested && options.some(option => option.value === requested)) {
    selectedAlias.value = requested
    return
  }
  if (!selectedAlias.value && options.length > 0) {
    selectedAlias.value = options[0]!.value
  }
}, { immediate: true })

/** True only when the catalogue explicitly says this alias has no Responses surface. */
const responsesUnsupported = computed(() => {
  const option = aliasOptions.value.find(item => item.value === selectedAlias.value)

  return option?.responses === false
})

const {
  inferenceRoot,
  openAiBase,
  claudeCodeShell,
  claudeCodePowerShell,
  codexConfig,
  codexShell,
  curlMessages,
  nodeAnthropic,
  pythonAnthropic,
  openAiPython
} = useCliSnippets({ modelAlias: selectedAlias })

const claudeTabs = computed(() => [
  { label: 'macOS / Linux', value: 'bash', code: claudeCodeShell.value, filename: 'bash' },
  { label: 'Windows PowerShell', value: 'pwsh', code: claudeCodePowerShell.value, filename: 'PowerShell' }
])

const sdkTabs = computed(() => [
  { label: 'cURL', value: 'curl', code: curlMessages.value, filename: 'bash' },
  { label: 'Node.js', value: 'node', code: nodeAnthropic.value, filename: 'index.mjs' },
  { label: 'Python', value: 'python', code: pythonAnthropic.value, filename: 'main.py' },
  { label: 'OpenAI SDK', value: 'openai', code: openAiPython.value, filename: 'responses.py' }
])

const usableKeys = computed(() => (keys.data.value ?? []).filter(key => key.status === 'ACTIVE'))
</script>

<template>
  <SpDashboardPage
    title="CLI setup"
    icon="i-lucide-terminal"
    description="Everything below is generated from this build's configuration. Substitute your own key where the snippets show a placeholder — they never contain a real secret."
  >
    <section class="space-y-4">
      <SpSectionHeading
        title="Pick a model alias"
        description="Aliases are stable across upstream routing changes, so a working configuration keeps working."
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField
          label="Model alias"
          :help="models.unavailable.value
            ? 'The catalogue is not published yet, so the snippets show a placeholder to replace by hand.'
            : 'Used in every snippet on this page.'"
        >
          <USelectMenu
            v-model="selectedAlias"
            :items="aliasOptions"
            value-key="value"
            :disabled="models.unavailable.value || aliasOptions.length === 0"
            :loading="models.loading.value"
            placeholder="<your-model-alias>"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Key to use"
          :help="keys.unavailable.value
            ? 'Key management is not published yet.'
            : 'Secrets are never shown here. Use the value you captured at creation, or rotate the key.'"
        >
          <div class="flex min-h-8 flex-wrap items-center gap-1.5">
            <template v-if="usableKeys.length > 0">
              <UBadge
                v-for="key in usableKeys"
                :key="key.id"
                color="neutral"
                variant="subtle"
                size="sm"
                class="font-mono"
              >
                {{ maskApiKey(key.prefix, key.last_four) }}
              </UBadge>
            </template>
            <UButton
              v-else
              to="/dashboard/api-keys"
              color="neutral"
              variant="subtle"
              size="sm"
              icon="i-lucide-key-round"
            >
              Create a key
            </UButton>
          </div>
        </UFormField>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-default bg-elevated/30 p-4">
          <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
            Anthropic-compatible base URL
          </p>
          <div class="mt-2 flex items-center gap-2">
            <code class="min-w-0 flex-1 truncate font-mono text-sm text-toned">{{ inferenceRoot }}</code>
            <SpCopyButton :value="inferenceRoot" />
          </div>
          <p class="mt-2 text-xs text-muted">
            Root only — the client appends <code>/v1/messages</code> itself.
          </p>
        </div>

        <div class="rounded-lg border border-default bg-elevated/30 p-4">
          <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
            OpenAI-compatible base URL
          </p>
          <div class="mt-2 flex items-center gap-2">
            <code class="min-w-0 flex-1 truncate font-mono text-sm text-toned">{{ openAiBase }}</code>
            <SpCopyButton :value="openAiBase" />
          </div>
          <p class="mt-2 text-xs text-muted">
            Must end in <code>/v1</code>, with the Responses wire API.
          </p>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Claude Code"
        description="Two environment variables and a model alias. No plugin, no patching."
      >
        <template #actions>
          <UButton
            to="/docs/claude-code"
            color="neutral"
            variant="ghost"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
          >
            Full guide
          </UButton>
        </template>
      </SpSectionHeading>

      <UTabs :items="claudeTabs">
        <template #content="{ item }">
          <SpCodeBlock
            :code="item.code"
            :filename="item.filename"
          />
        </template>
      </UTabs>

      <UAlert
        icon="i-lucide-triangle-alert"
        color="warning"
        variant="subtle"
        title="Do not append /v1 to ANTHROPIC_BASE_URL"
        description="Claude Code adds its own path. Including /v1 produces requests to /v1/v1/messages and every call fails with a not-found error."
      />
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Codex CLI"
        description="Registered as a custom OpenAI-compatible provider, with the key read from your environment."
      >
        <template #actions>
          <UButton
            to="/docs/codex-cli"
            color="neutral"
            variant="ghost"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
          >
            Full guide
          </UButton>
        </template>
      </SpSectionHeading>

      <SpCodeBlock
        filename="~/.codex/config.toml"
        :code="codexConfig"
      />
      <SpCodeBlock
        filename="bash"
        :code="codexShell"
      />

      <UAlert
        v-if="responsesUnsupported"
        icon="i-lucide-triangle-alert"
        color="warning"
        variant="subtle"
        title="This alias does not offer the Responses API"
        :description="`The catalogue lists ${selectedAlias} without a Responses surface, so Codex CLI cannot use it. Choose an alias that has one, or use Claude Code with this alias instead.`"
      />

      <p class="text-sm text-muted">
        <code>env_key</code> keeps the secret out of the config file. Confirm your chosen alias supports the
        Responses API before configuring — the
        <NuxtLink
          to="/models"
          class="text-primary underline decoration-dotted underline-offset-4"
        >
          catalogue
        </NuxtLink>
        lists that per model.
      </p>
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="SDKs and raw HTTP"
        description="Point an official SDK at SP Cambo by changing the base URL and the key. Nothing else changes."
      />

      <UTabs :items="sdkTabs">
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
        title="If it does not work"
        :level="3"
      />

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            Every request 404s
          </p>
          <p class="mt-1 text-sm text-muted">
            A <code>/v1</code> on the end of <code>ANTHROPIC_BASE_URL</code>, or Chat Completions instead of
            Responses on the OpenAI side.
          </p>
        </div>
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            401 or 403 immediately
          </p>
          <p class="mt-1 text-sm text-muted">
            The key is wrong, disabled, revoked or scoped to other models. Use
            <strong>Test</strong> on the
            <NuxtLink
              to="/dashboard/api-keys"
              class="text-primary underline decoration-dotted underline-offset-4"
            >
              keys page
            </NuxtLink> — it spends nothing.
          </p>
        </div>
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            402 or "insufficient"
          </p>
          <p class="mt-1 text-sm text-muted">
            The package is spent or expired. Requests stop rather than becoming an overage bill.
          </p>
        </div>
        <div class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            Variables set but ignored
          </p>
          <p class="mt-1 text-sm text-muted">
            The tool read the environment at launch. Restart it, and check the variables are exported in the
            shell that starts it.
          </p>
        </div>
      </div>
    </section>
  </SpDashboardPage>
</template>
