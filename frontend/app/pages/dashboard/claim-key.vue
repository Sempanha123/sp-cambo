<script setup lang="ts">
import type { ApiKeySummary } from '~/types/commerce'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Activate purchased access', description: 'Attach purchased access to an existing SP Cambo key or create a new key.', robots: 'noindex' })

const route = useRoute()
const api = useSpApi()
const toast = useToast()
const claimId = computed(() => String(route.query.claim ?? ''))
const mode = ref<'NEW' | 'EXISTING'>('NEW')
const keys = ref<ApiKeySummary[]>([])
const selectedKeyId = ref<string | undefined>()
const loadingKeys = ref(false)
const claiming = ref(false)
const revealedSecret = ref<string | null>(null)
const claimInfo = ref<{ id: string, order_id: string, status: string, package_name: string, allowed_model_aliases: string[] } | null>(null)
const result = ref<{ masked_key?: string, key_id?: string, models?: string[], expires_at?: string | null, delivery_mode?: 'NEW' | 'EXISTING' } | null>(null)
const error = ref<string | null>(null)

const activeKeys = computed(() => keys.value.filter(key => key.status === 'ACTIVE'))
const keyOptions = computed(() => activeKeys.value.map(key => ({ label: `${key.label} · ${key.prefix}…${key.last_four}`, value: key.id })))
const selectedModel = computed(() => result.value?.models?.[0] ?? claimInfo.value?.allowed_model_aliases?.[0] ?? '')

const {
  inferenceRoot,
  openAiBase,
  claudeCodePowerShell,
  claudeCodeSettingsJson
} = useCliSnippets({ modelAlias: selectedModel, apiKey: revealedSecret })

const loadClaim = async () => {
  if (!claimId.value) return
  try {
    const claims = await api.request<Array<{ id: string, order_id: string, status: string, package_name: string, allowed_model_aliases: string[] }>>('/me/api-key-claims', { collection: true })
    claimInfo.value = claims.find(claim => claim.id === claimId.value) ?? null
  } catch {
    claimInfo.value = null
  }
}

const loadKeys = async () => {
  loadingKeys.value = true
  try {
    keys.value = await api.account.apiKeys()
    if (!selectedKeyId.value && activeKeys.value.length > 0) {
      selectedKeyId.value = activeKeys.value[0]!.id
      // Reusing an existing key is the least disruptive default for repeat buyers.
      mode.value = 'EXISTING'
    }
  } finally {
    loadingKeys.value = false
  }
}

const claim = async () => {
  if (!claimId.value || (mode.value === 'EXISTING' && !selectedKeyId.value)) return
  claiming.value = true
  error.value = null
  try {
    const data = await api.request<{
      delivery_mode: 'NEW' | 'EXISTING'
      api_key: string | null
      key_id: string
      masked_key: string
      expires_at: string | null
      models: string[]
    }>(`/me/api-key-claims/${claimId.value}/claim`, {
      method: 'POST',
      body: {
        mode: mode.value,
        existing_api_key_id: mode.value === 'EXISTING' ? selectedKeyId.value : null
      },
      headers: { 'Idempotency-Key': `claim:${claimId.value}:${mode.value}:${selectedKeyId.value ?? 'new'}` }
    })
    revealedSecret.value = data.api_key
    result.value = data
    toast.add({ title: mode.value === 'NEW' ? 'New API key created' : 'Purchased access attached', color: 'success', icon: 'i-lucide-circle-check' })
  } catch (cause) {
    const e = toSpApiError(cause)
    error.value = e.message
    if (e.code === 'already_claimed') {
      await loadClaim()
    }
  } finally {
    claiming.value = false
  }
}

const copySecret = async () => {
  if (revealedSecret.value && import.meta.client) await navigator.clipboard.writeText(revealedSecret.value)
}

onMounted(async () => {
  await Promise.all([loadClaim(), loadKeys()])
})
</script>

<template>
  <SpDashboardPage
    title="Activate purchased access"
    icon="i-lucide-key-round"
    description="Choose a new SP Cambo key or keep using an existing one. Your purchased entitlement stays on your account either way."
  >
    <div class="mx-auto w-full max-w-3xl space-y-5">
      <UAlert
        v-if="!claimId"
        color="warning"
        icon="i-lucide-triangle-alert"
        title="Missing activation claim"
        description="Return to the fulfilled order and open its API access activation link."
      />

      <UCard v-else-if="!result">
        <div class="space-y-5">
          <div>
            <p v-if="claimInfo" class="text-xs font-medium tracking-wide text-primary uppercase">{{ claimInfo.package_name }}</p>
            <h2 class="font-semibold text-highlighted">How should this purchase use API keys?</h2>
            <p class="mt-1 text-sm text-muted">Reusing a key is best when Claude Code is already configured. A new key is best for a separate project or your first purchase.</p>
          </div>

          <URadioGroup v-model="mode" :items="[
            { label: 'Use an existing API key', value: 'EXISTING', description: 'Recommended for repeat purchases. Your current Claude Code / SDK settings stay unchanged.' },
            { label: 'Create a new API key', value: 'NEW', description: 'The full secret is shown once on the next screen.' }
          ]" />

          <UFormField v-if="mode === 'EXISTING'" label="Existing key" help="Only active keys owned by this account are shown.">
            <USelectMenu v-model="selectedKeyId" :items="keyOptions" value-key="value" :loading="loadingKeys" :disabled="activeKeys.length === 0" class="w-full" placeholder="Select an active key" />
          </UFormField>

          <UAlert v-if="mode === 'EXISTING' && !loadingKeys && activeKeys.length === 0" color="warning" icon="i-lucide-key" title="No active key available" description="Choose Create a new API key for this purchase." />
          <UAlert v-if="error" color="error" icon="i-lucide-circle-x" title="Activation failed" :description="error" />

          <UButton block size="lg" :loading="claiming" :disabled="mode === 'EXISTING' && !selectedKeyId" @click="claim">
            {{ mode === 'NEW' ? 'Create key and activate' : 'Use this key and activate' }}
          </UButton>
        </div>
      </UCard>

      <template v-else>
        <UCard>
          <div class="space-y-4">
            <UAlert
              color="success"
              icon="i-lucide-circle-check"
              :title="result.delivery_mode === 'NEW' ? 'New key ready' : 'Existing key updated'"
              :description="result.delivery_mode === 'NEW' ? 'Copy the full secret now. SP Cambo will not show it again.' : `Purchased model access is now available through ${result.masked_key}.`"
            />

            <div v-if="revealedSecret" class="space-y-2">
              <div class="rounded-lg border border-default bg-elevated p-3 font-mono text-sm break-all">{{ revealedSecret }}</div>
              <UButton icon="i-lucide-copy" color="neutral" variant="outline" @click="copySecret">Copy API key</UButton>
            </div>
            <div v-else class="text-sm text-muted">Key: <span class="font-mono text-default">{{ result.masked_key }}</span></div>

            <div v-if="result.models?.length" class="text-sm text-muted">Model: <span class="font-mono text-default">{{ result.models.join(', ') }}</span></div>
          </div>
        </UCard>

        <UCard>
          <div class="space-y-4">
            <div>
              <h2 class="font-semibold text-highlighted">Ready-to-copy Claude Code setup</h2>
              <p class="mt-1 text-sm text-muted">Anthropic uses the gateway root only. Do not add <code>/v1</code> to <code>ANTHROPIC_BASE_URL</code>.</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-lg border border-default p-3">
                <p class="text-xs text-dimmed">Anthropic base</p>
                <div class="mt-1 flex items-center gap-2"><code class="min-w-0 flex-1 truncate text-sm">{{ inferenceRoot }}</code><SpCopyButton :value="inferenceRoot" /></div>
              </div>
              <div class="rounded-lg border border-default p-3">
                <p class="text-xs text-dimmed">OpenAI / Codex base</p>
                <div class="mt-1 flex items-center gap-2"><code class="min-w-0 flex-1 truncate text-sm">{{ openAiBase }}</code><SpCopyButton :value="openAiBase" /></div>
              </div>
            </div>

            <UAlert
              v-if="result.delivery_mode === 'EXISTING'"
              color="info"
              variant="subtle"
              icon="i-lucide-key-round"
              title="Keep your existing secret"
              description="SP Cambo does not reveal an existing key again. The templates below therefore keep a placeholder; use the same secret already configured in your CLI."
            />

            <SpCodeBlock filename="Windows PowerShell" :code="claudeCodePowerShell" />
            <SpCodeBlock filename=".claude/settings.json" :code="claudeCodeSettingsJson" />

            <div class="flex flex-wrap gap-2">
              <UButton :to="selectedModel ? `/dashboard/cli-setup?model=${encodeURIComponent(selectedModel)}` : '/dashboard/cli-setup'" icon="i-lucide-terminal">More CLI / SDK examples</UButton>
              <UButton to="/dashboard/api-keys" color="neutral" variant="subtle" icon="i-lucide-key-round">Open API keys</UButton>
            </div>
          </div>
        </UCard>
      </template>
    </div>
  </SpDashboardPage>
</template>
