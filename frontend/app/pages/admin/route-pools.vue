<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Model routing', robots: 'noindex' })

type RouteHealth = {
  status: 'READY' | 'CIRCUIT_OPEN'
  consecutive_failures: number
  circuit_open_until: string | null
  last_failure_at: string | null
  last_success_at: string | null
  last_error_code: string | null
}

type AliasSummary = {
  id: string
  public_alias: string
  display_name: string
  primary_provider: string | null
  route_pool: {
    configured: boolean
    enabled: boolean
    route_count: number
    max_concurrency: number | null
  }
}

type Candidate = {
  candidate_key: string
  ai_model_id: string
  revision_id: string
  provider_id: string
  provider_name: string | null
  private_model: string
  internal_model_id: string
  route_version: number
  connection_type: string
  timeout_ms: number
  masked_credential: string | null
  active_connections: number
  health: RouteHealth
}

type PoolEntry = {
  id?: string
  ai_model_id: string
  revision_id: string
  enabled: boolean
  weight: number
  max_concurrency: number | null
  priority: number
  provider_name?: string | null
  private_model?: string | null
  internal_model_id?: string | null
  route_version?: number | null
  connection_type?: string | null
  active_connections?: number
  health?: RouteHealth
}

type PoolDetail = {
  model: { id: string, public_alias: string, display_name: string }
  pool: {
    configured: boolean
    enabled: boolean
    strategy: 'WEIGHTED_LEAST_CONNECTIONS'
    max_concurrency: number | null
    max_failover_attempts: number
    circuit_failure_threshold: number
    circuit_cooldown_seconds: number
    entries: PoolEntry[]
  }
  candidates: Candidate[]
  active_model_connections: number
}

const api = useSpApi()
const toast = useToast()

const aliases = await useSpResource(
  'admin:model-route-pools:list',
  () => api.request<AliasSummary[]>('/admin/model-route-pools'),
  { server: false }
)

const selectedAliasId = ref<string | undefined>()
const detail = ref<PoolDetail | null>(null)
const loading = ref(false)
const saving = ref(false)
const resettingRevision = ref<string | null>(null)
const selectedCandidate = ref<string | undefined>()
const reason = ref('Configure scalable multi-provider routing and failover capacity')

const form = reactive({
  enabled: false,
  strategy: 'WEIGHTED_LEAST_CONNECTIONS' as const,
  max_concurrency: null as number | null,
  max_failover_attempts: 2,
  circuit_failure_threshold: 3,
  circuit_cooldown_seconds: 30,
  entries: [] as PoolEntry[]
})

const aliasOptions = computed(() =>
  (aliases.data.value ?? []).map(alias => ({
    label: `${alias.display_name} · ${alias.public_alias}${alias.route_pool.enabled ? ' · pooled' : ''}`,
    value: alias.id
  }))
)

const candidateOptions = computed(() => {
  const used = new Set(form.entries.map(entry => `${entry.ai_model_id}:${entry.revision_id}`))

  return (detail.value?.candidates ?? [])
    .filter(candidate => !used.has(candidate.candidate_key))
    .map(candidate => ({
      label: `${candidate.provider_name ?? 'Provider'} · ${candidate.private_model} · R${candidate.route_version} · ${candidate.active_connections} active`,
      value: candidate.candidate_key
    }))
})

const candidateMap = computed(() =>
  new Map((detail.value?.candidates ?? []).map(candidate => [candidate.candidate_key, candidate]))
)

const loadDetail = async () => {
  if (!selectedAliasId.value) {
    detail.value = null
    return
  }

  loading.value = true
  try {
    const data = await api.request<PoolDetail>(`/admin/model-route-pools/${selectedAliasId.value}`)
    detail.value = data
    form.enabled = data.pool.enabled
    form.max_concurrency = data.pool.max_concurrency
    form.max_failover_attempts = data.pool.max_failover_attempts
    form.circuit_failure_threshold = data.pool.circuit_failure_threshold
    form.circuit_cooldown_seconds = data.pool.circuit_cooldown_seconds
    form.entries = data.pool.entries.map(entry => ({ ...entry }))
    selectedCandidate.value = undefined
  } catch (error) {
    toast.add({
      title: 'Could not load model routing',
      description: error instanceof Error ? error.message : 'Please try again.',
      color: 'error'
    })
  } finally {
    loading.value = false
  }
}

watch(selectedAliasId, () => void loadDetail())

const addRoute = () => {
  if (!selectedCandidate.value) return

  const candidate = candidateMap.value.get(selectedCandidate.value)
  if (!candidate) return

  form.entries.push({
    ai_model_id: candidate.ai_model_id,
    revision_id: candidate.revision_id,
    enabled: candidate.health.status !== 'CIRCUIT_OPEN',
    weight: 100,
    max_concurrency: 10,
    priority: (form.entries.length + 1) * 100,
    provider_name: candidate.provider_name,
    private_model: candidate.private_model,
    internal_model_id: candidate.internal_model_id,
    route_version: candidate.route_version,
    connection_type: candidate.connection_type,
    active_connections: candidate.active_connections,
    health: candidate.health
  })

  selectedCandidate.value = undefined
}

const removeRoute = (index: number) => {
  form.entries.splice(index, 1)
}

const save = async () => {
  if (!selectedAliasId.value || saving.value) return

  saving.value = true
  try {
    const data = await api.request<PoolDetail>(
      `/admin/model-route-pools/${selectedAliasId.value}`,
      {
        method: 'PUT',
        body: {
          enabled: form.enabled,
          strategy: form.strategy,
          max_concurrency: form.max_concurrency,
          max_failover_attempts: form.max_failover_attempts,
          circuit_failure_threshold: form.circuit_failure_threshold,
          circuit_cooldown_seconds: form.circuit_cooldown_seconds,
          entries: form.entries.map(entry => ({
            ai_model_id: Number(entry.ai_model_id),
            revision_id: entry.revision_id,
            enabled: entry.enabled,
            weight: Number(entry.weight),
            max_concurrency: entry.max_concurrency === null ? null : Number(entry.max_concurrency),
            priority: Number(entry.priority)
          })),
          reason: reason.value.trim()
        }
      }
    )

    detail.value = data
    toast.add({
      title: 'Model routing saved',
      description: `${data.model.public_alias} can now scale across the configured healthy routes.`,
      color: 'success'
    })
    await Promise.all([loadDetail(), aliases.refresh()])
  } catch (error) {
    toast.add({
      title: 'Could not save model routing',
      description: error instanceof Error ? error.message : 'Check each private model and READY revision.',
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}

const resetCircuit = async (entry: PoolEntry) => {
  if (!selectedAliasId.value) return

  const confirmed = window.confirm(
    `Reset the circuit for ${entry.provider_name ?? 'this provider'} revision ${entry.route_version ?? ''}?`
  )
  if (!confirmed) return

  resettingRevision.value = entry.revision_id
  try {
    await api.request(
      `/admin/model-route-pools/${selectedAliasId.value}/revisions/${entry.revision_id}/reset-circuit`,
      {
        method: 'POST',
        body: {
          reason: 'Operator confirmed route recovery after reviewing provider health'
        }
      }
    )
    toast.add({ title: 'Circuit reset', description: 'The route can be selected again.', color: 'success' })
    await loadDetail()
  } catch (error) {
    toast.add({
      title: 'Could not reset circuit',
      description: error instanceof Error ? error.message : 'Please try again.',
      color: 'error'
    })
  } finally {
    resettingRevision.value = null
  }
}

const totalRouteCapacity = computed(() =>
  form.entries
    .filter(entry => entry.enabled && entry.max_concurrency !== null)
    .reduce((sum, entry) => sum + Number(entry.max_concurrency ?? 0), 0)
)
</script>

<template>
  <SpDashboardPage
    title="Model routing"
    eyebrow="Scalable multi-provider inference"
    description="Keep one public model name while SP Cambo distributes requests across any number of healthy private provider / OmniRoute routes."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        :loading="loading"
        :disabled="!selectedAliasId"
        @click="loadDetail"
      >
        Refresh
      </UButton>
    </template>

    <div class="space-y-5">
      <UCard class="sp-premium-card sp-app-card">
        <UFormField label="Public model alias">
          <USelectMenu
            v-model="selectedAliasId"
            :items="aliasOptions"
            value-key="value"
            class="w-full"
            placeholder="Choose a customer-facing model"
          />
        </UFormField>
      </UCard>

      <template v-if="detail">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <SpMetric label="Public alias" icon="i-lucide-route" :value="detail.model.public_alias" />
          <SpMetric label="Configured routes" icon="i-lucide-network" :value="String(form.entries.length)" />
          <SpMetric label="Active requests" icon="i-lucide-activity" :value="String(detail.active_model_connections)" />
          <SpMetric
            label="Route capacity"
            icon="i-lucide-gauge"
            :value="totalRouteCapacity > 0 ? String(totalRouteCapacity) : 'Dynamic'"
          />
        </div>

        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-shuffle"
          title="Customers never change model names"
          :description="`model: ${detail.model.public_alias}` stays unchanged. Provider, revision and private model mapping stay inside SP Cambo."
        />

        <UCard class="sp-premium-card sp-app-card">
          <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="font-semibold text-highlighted">Routing policy</h2>
                <p class="mt-1 max-w-3xl text-sm text-muted">
                  Weighted least-connections chooses the healthiest low-load route. Circuit breaking temporarily removes repeatedly failing routes.
                </p>
              </div>
              <USwitch v-model="form.enabled" label="Enable route pool" />
            </div>
          </template>

          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <UFormField label="Strategy" class="md:col-span-2">
              <UInput model-value="WEIGHTED_LEAST_CONNECTIONS" disabled class="w-full font-mono" />
            </UFormField>

            <UFormField label="Global model concurrency">
              <UInput
                v-model.number="form.max_concurrency"
                type="number"
                min="1"
                max="100000"
                placeholder="Unlimited"
              />
            </UFormField>

            <UFormField label="Failover attempts">
              <UInput
                v-model.number="form.max_failover_attempts"
                type="number"
                min="0"
                max="5"
              />
            </UFormField>

            <UFormField label="Circuit threshold">
              <UInput
                v-model.number="form.circuit_failure_threshold"
                type="number"
                min="1"
                max="20"
              />
            </UFormField>
          </div>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <UFormField label="Circuit cooldown (seconds)">
              <UInput
                v-model.number="form.circuit_cooldown_seconds"
                type="number"
                min="5"
                max="900"
              />
            </UFormField>

            <UFormField label="Audit reason">
              <UInput v-model="reason" class="w-full" />
            </UFormField>
          </div>
        </UCard>

        <UCard class="sp-premium-card sp-app-card">
          <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
              <div>
                <h2 class="font-semibold text-highlighted">Private route pool</h2>
                <p class="mt-1 text-sm text-muted">
                  Add more READY routes whenever user traffic grows. A pool can contain routes from different enabled providers.
                </p>
              </div>

              <div class="flex min-w-0 flex-1 gap-2 sm:max-w-2xl">
                <USelectMenu
                  v-model="selectedCandidate"
                  :items="candidateOptions"
                  value-key="value"
                  class="min-w-0 flex-1"
                  placeholder="Choose READY private model + route"
                />
                <UButton
                  icon="i-lucide-plus"
                  :disabled="!selectedCandidate"
                  @click="addRoute"
                >
                  Add route
                </UButton>
              </div>
            </div>
          </template>

          <div v-if="form.entries.length" class="space-y-3">
            <div
              v-for="(entry, index) in form.entries"
              :key="`${entry.ai_model_id}:${entry.revision_id}`"
              class="rounded-xl border border-default/50 p-4"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <strong class="text-highlighted">
                      {{ entry.provider_name ?? 'Provider' }} · {{ entry.private_model ?? entry.internal_model_id }}
                    </strong>
                    <UBadge color="neutral" variant="subtle">
                      R{{ entry.route_version ?? '—' }}
                    </UBadge>
                    <UBadge
                      :color="entry.health?.status === 'CIRCUIT_OPEN' ? 'warning' : 'success'"
                      variant="subtle"
                    >
                      {{ entry.health?.status ?? 'READY' }}
                    </UBadge>
                    <UBadge color="primary" variant="subtle">
                      {{ entry.active_connections ?? 0 }} active
                    </UBadge>
                  </div>
                  <p class="mt-1 truncate font-mono text-xs text-muted">
                    {{ entry.internal_model_id }} · {{ entry.connection_type }}
                  </p>
                  <p
                    v-if="entry.health?.last_error_code"
                    class="mt-1 text-xs text-warning"
                  >
                    Last route error: {{ entry.health.last_error_code }}
                  </p>
                </div>

                <div class="flex items-center gap-2">
                  <UButton
                    v-if="entry.health?.status === 'CIRCUIT_OPEN'"
                    size="xs"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-heart-pulse"
                    :loading="resettingRevision === entry.revision_id"
                    @click="resetCircuit(entry)"
                  >
                    Reset circuit
                  </UButton>
                  <UButton
                    size="xs"
                    color="error"
                    variant="ghost"
                    icon="i-lucide-trash-2"
                    @click="removeRoute(index)"
                  >
                    Remove
                  </UButton>
                </div>
              </div>

              <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-[8rem_10rem_8rem_auto] xl:items-end">
                <UFormField label="Weight">
                  <UInput v-model.number="entry.weight" type="number" min="1" max="1000" />
                </UFormField>

                <UFormField label="Route concurrency">
                  <UInput
                    v-model.number="entry.max_concurrency"
                    type="number"
                    min="1"
                    max="100000"
                    placeholder="Unlimited"
                  />
                </UFormField>

                <UFormField label="Priority">
                  <UInput v-model.number="entry.priority" type="number" min="0" max="10000" />
                </UFormField>

                <USwitch v-model="entry.enabled" label="Use this route" />
              </div>
            </div>
          </div>

          <p v-else class="py-10 text-center text-sm text-muted">
            No pooled routes configured. Add at least one READY private model + revision.
          </p>

          <template #footer>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <p class="text-xs text-muted">
                Add a third, fourth or more route later without changing customer API keys or public model aliases.
              </p>
              <UButton
                icon="i-lucide-save"
                :loading="saving"
                :disabled="form.entries.length === 0 || reason.trim().length < 10"
                @click="save"
              >
                Save production routing
              </UButton>
            </div>
          </template>
        </UCard>
      </template>

      <UCard v-else-if="!loading" class="sp-premium-card sp-app-card">
        <div class="py-10 text-center">
          <UIcon name="i-lucide-route" class="mx-auto size-8 text-muted" />
          <p class="mt-3 text-sm text-muted">Choose a public model alias to configure scalable routing.</p>
        </div>
      </UCard>
    </div>
  </SpDashboardPage>
</template>
