<script setup lang="ts">
import type { ApiKeySummary } from '~/types/commerce'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({
  title: 'Choose purchase access',
  description: 'Choose whether a fulfilled SP Cambo purchase belongs to Playground, a new API key, or one existing API key.',
  robots: 'noindex'
})

const route = useRoute()
const api = useSpApi()
const toast = useToast()
const claimId = computed(() => String(route.query.claim ?? ''))
type AccessMode = 'PLAYGROUND' | 'NEW' | 'EXISTING'
const requestedMode = String(route.query.mode ?? '').toUpperCase()
const mode = ref<AccessMode>(['PLAYGROUND', 'NEW', 'EXISTING'].includes(requestedMode) ? requestedMode as AccessMode : 'PLAYGROUND')
const keys = ref<ApiKeySummary[]>([])
const selectedKeyId = ref<string | undefined>()
const loadingKeys = ref(false)
const claiming = ref(false)
const revealedSecret = ref<string | null>(null)
const claimInfo = ref<{
  id: string
  order_id: string
  status: string
  delivery_mode?: AccessMode | null
  package_name: string
  allowed_model_aliases: string[]
  api_key_id?: string | null
  masked_key?: string | null
} | null>(null)
const result = ref<{
  masked_key?: string | null
  key_id?: string | null
  models?: string[]
  expires_at?: string | null
  delivery_mode: AccessMode
} | null>(null)
const error = ref<string | null>(null)

const activeKeys = computed(() => keys.value.filter(key => key.status === 'ACTIVE'))
const keyOptions = computed(() => activeKeys.value.map(key => ({
  label: `${key.label} · ${key.prefix}…${key.last_four}`,
  value: key.id
})))
const selectedModel = computed(() => result.value?.models?.[0] ?? claimInfo.value?.allowed_model_aliases?.[0] ?? '')

const { claudeCodePowerShell, claudeCodeSettingsJson } = useCliSnippets({ modelAlias: selectedModel, apiKey: revealedSecret })

const loadClaim = async () => {
  if (!claimId.value) return
  try {
    const claims = await api.request<Array<{
      id: string
      order_id: string
      status: string
      delivery_mode?: AccessMode | null
      package_name: string
      allowed_model_aliases: string[]
      api_key_id?: string | null
      masked_key?: string | null
    }>>('/me/api-key-claims', { collection: true })
    claimInfo.value = claims.find(claim => claim.id === claimId.value) ?? null

    if (claimInfo.value?.status === 'CLAIMED') {
      result.value = {
        delivery_mode: claimInfo.value.delivery_mode ?? (claimInfo.value.api_key_id ? 'EXISTING' : 'PLAYGROUND'),
        key_id: claimInfo.value.api_key_id,
        masked_key: claimInfo.value.masked_key,
        models: claimInfo.value.allowed_model_aliases
      }
    }
  } catch (cause) {
    error.value = toSpApiError(cause).message
  }
}

const loadKeys = async () => {
  loadingKeys.value = true
  try {
    keys.value = await api.account.apiKeys()
    if (!selectedKeyId.value && activeKeys.value.length > 0) selectedKeyId.value = activeKeys.value[0]!.id
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
      delivery_mode: AccessMode
      api_key: string | null
      key_id: string | null
      masked_key: string | null
      expires_at: string | null
      models: string[]
    }>(`/me/api-key-claims/${claimId.value}/claim`, {
      method: 'POST',
      body: {
        mode: mode.value,
        existing_api_key_id: mode.value === 'EXISTING' ? selectedKeyId.value : null
      },
      headers: { 'Idempotency-Key': `claim:${claimId.value}:${mode.value}:${selectedKeyId.value ?? 'none'}` }
    })

    revealedSecret.value = data.api_key
    result.value = data
    toast.add({
      title: mode.value === 'PLAYGROUND' ? 'Added to Playground' : mode.value === 'NEW' ? 'Dedicated API key created' : 'Added to selected key',
      color: 'success',
      icon: 'i-lucide-circle-check'
    })
  } catch (cause) {
    const e = toSpApiError(cause)
    error.value = e.message
    if (e.code === 'already_claimed') await loadClaim()
  } finally {
    claiming.value = false
  }
}

const copySecret = async () => {
  if (!revealedSecret.value || !import.meta.client) return
  await navigator.clipboard.writeText(revealedSecret.value)
  toast.add({ title: 'API key copied', color: 'success' })
}

onMounted(async () => {
  await loadClaim()
  void loadKeys()
})
</script>

<template>
  <SpDashboardPage
    title="Choose where this purchase goes"
    icon="i-lucide-route"
    description="A paid package is not merged automatically. Allocate it to Playground, a separate new API key, or one existing API key."
  >
    <div class="mx-auto w-full max-w-4xl space-y-5">
      <UAlert
        v-if="!claimId"
        color="warning"
        icon="i-lucide-triangle-alert"
        title="Missing purchase allocation"
        description="Return to the fulfilled order and choose access from there."
      />

      <UCard v-else-if="!result" class="sp-app-card">
        <div class="space-y-6">
          <div>
            <p v-if="claimInfo" class="text-xs font-medium tracking-wide text-primary uppercase">
              {{ claimInfo.package_name }}
            </p>
            <h2 class="text-lg font-semibold text-highlighted">How do you want to use these purchased tokens or credits?</h2>
            <p class="mt-1 text-sm text-muted">
              This choice controls who can spend this package. Other keys cannot consume a dedicated key's package, and API keys cannot consume Playground-only purchases.
            </p>
            <p class="mt-2 text-xs font-medium text-warning">
              Choose carefully: allocation is final for this package so the same purchased balance cannot be assigned twice.
            </p>
          </div>

          <URadioGroup
            v-model="mode"
            :items="[
              { label: 'Add to Playground balance', value: 'PLAYGROUND', description: 'Best when you bought tokens to continue chatting in SP Cambo. No API key is created.' },
              { label: 'Create a new API key', value: 'NEW', description: 'Creates a separate key with this package dedicated to it. Best for another person, device, app, or project.' },
              { label: 'Add to an existing API key', value: 'EXISTING', description: 'Only the selected key receives this purchased balance and model access.' }
            ]"
          />

          <UFormField
            v-if="mode === 'EXISTING'"
            label="Existing API key"
            help="Only active keys owned by this account are shown. This purchase will not be available to your other dedicated keys."
          >
            <USelectMenu
              v-model="selectedKeyId"
              :items="keyOptions"
              value-key="value"
              :loading="loadingKeys"
              :disabled="activeKeys.length === 0"
              class="w-full"
              placeholder="Select an active key"
            />
          </UFormField>

          <UAlert
            v-if="mode === 'EXISTING' && !loadingKeys && activeKeys.length === 0"
            color="warning"
            icon="i-lucide-key"
            title="No active key available"
            description="Choose Create a new API key or Playground instead."
          />
          <UAlert v-if="error" color="error" icon="i-lucide-circle-x" title="Could not allocate purchase" :description="error" />

          <UButton
            block
            size="lg"
            :loading="claiming"
            :disabled="mode === 'EXISTING' && !selectedKeyId"
            @click="claim"
          >
            {{ mode === 'PLAYGROUND' ? 'Add package to Playground' : mode === 'NEW' ? 'Create dedicated key' : 'Add package to this key' }}
          </UButton>
        </div>
      </UCard>

      <template v-else>
        <UCard class="sp-app-card">
          <div class="space-y-5">
            <UAlert
              v-if="result.delivery_mode === 'PLAYGROUND'"
              color="success"
              icon="i-lucide-flask-conical"
              title="Purchase added to Playground"
              description="The package balance is now usable in Playground for its purchased model scope. Normal API keys cannot spend this dedicated Playground balance."
            />
            <UAlert
              v-else
              color="success"
              icon="i-lucide-key-round"
              :title="result.delivery_mode === 'NEW' ? 'Dedicated API key ready' : 'Existing API key updated'"
              :description="result.delivery_mode === 'NEW' ? 'This key has a dedicated package balance, so it can be given to another person or used for one separate project without spending other dedicated purchases.' : `This package is now dedicated to ${result.masked_key ?? 'the selected key'}.`"
            />

            <div v-if="revealedSecret" class="space-y-2">
              <div class="rounded-lg border border-default bg-elevated p-3 font-mono text-sm break-all">{{ revealedSecret }}</div>
              <UButton icon="i-lucide-copy" color="neutral" variant="outline" @click="copySecret">Copy API key</UButton>
            </div>

            <div v-if="result.models?.length" class="rounded-lg border border-default bg-elevated/30 p-4 text-sm">
              <p class="mb-2 text-xs font-medium text-muted uppercase">Purchased model scope</p>
              <div class="flex flex-wrap gap-2">
                <UBadge v-for="model in result.models" :key="model" color="neutral" variant="subtle">{{ model }}</UBadge>
              </div>
            </div>

            <div class="flex flex-wrap gap-2">
              <UButton v-if="result.delivery_mode === 'PLAYGROUND'" to="/dashboard/playground" icon="i-lucide-flask-conical">Open Playground</UButton>
              <UButton v-if="result.key_id" :to="`/dashboard/api-keys/${result.key_id}`" icon="i-lucide-key-round">View key details</UButton>
              <UButton to="/dashboard/orders" color="neutral" variant="subtle" icon="i-lucide-receipt">Orders</UButton>
            </div>
          </div>
        </UCard>

        <UCard v-if="revealedSecret && result.delivery_mode === 'NEW'" class="sp-app-card">
          <template #header><h3 class="font-semibold">Quick CLI setup</h3></template>
          <p class="mb-3 text-sm text-muted">The key can be securely re-copied later from API Keys.</p>
          <pre class="overflow-x-auto rounded-lg border border-default bg-elevated p-3 text-xs"><code>{{ claudeCodePowerShell }}</code></pre>
          <details class="mt-3"><summary class="cursor-pointer text-sm font-medium">settings.json</summary><pre class="mt-2 overflow-x-auto rounded-lg border border-default bg-elevated p-3 text-xs"><code>{{ claudeCodeSettingsJson }}</code></pre></details>
        </UCard>
      </template>
    </div>
  </SpDashboardPage>
</template>
