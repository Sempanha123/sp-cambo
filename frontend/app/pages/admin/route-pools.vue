<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Model route pools', robots: 'noindex' })

type AliasRow = {
  id: string
  public_alias: string
  display_name: string
  model: {
    provider: { id: string, name: string, slug: string } | null
  } | null
}

type Revision = {
  id: string
  route_version: number
  connection_type: string
  lifecycle_status: string
  last_probe_status: string | null
  timeout_ms: number
  masked_credential: string | null
  active_connections: number
  is_legacy_active: boolean
}

type PoolEntry = {
  revision_id: string
  enabled: boolean
  weight: number
  max_concurrency: number | null
  priority: number
  active_connections?: number
}

type PoolResponse = {
  model: {
    id: string
    public_alias: string
    display_name: string
    provider_id: string
    provider_name: string
  }
  pool: {
    exists: boolean
    enabled: boolean
    strategy: 'LEAST_CONNECTIONS'
    max_concurrency: number | null
    entries: PoolEntry[]
  }
  available_revisions: Revision[]
}

const api = useSpApi()
const toast = useToast()

const aliases = await useSpResource(
  'admin:route-pool-aliases',
  () => api.request<AliasRow[]>('/admin/model-aliases'),
  { server: false }
)

const selectedAliasId = ref<string | undefined>()
const pool = ref<PoolResponse | null>(null)
const loadingPool = ref(false)
const saving = ref(false)
const reason = ref('Configure weighted least-connections routing for production capacity')

const aliasOptions = computed(() =>
  (aliases.data.value ?? []).map(alias => ({
    label: `${alias.display_name} · ${alias.public_alias}`,
    value: String(alias.id)
  }))
)

const form = reactive<{
  enabled: boolean
  strategy: 'LEAST_CONNECTIONS'
  max_concurrency: number | null
  entries: PoolEntry[]
}>({
  enabled: false,
  strategy: 'LEAST_CONNECTIONS',
  max_concurrency: null,
  entries: []
})

const loadPool = async () => {
  if (!selectedAliasId.value) {
    pool.value = null
    return
  }

  loadingPool.value = true
  try {
    const data = await api.request<PoolResponse>(
      `/admin/model-route-pools/${selectedAliasId.value}`
    )
    pool.value = data

    form.enabled = data.pool.enabled
    form.strategy = 'LEAST_CONNECTIONS'
    form.max_concurrency = data.pool.max_concurrency

    const saved = new Map(
      data.pool.entries.map(entry => [entry.revision_id, entry])
    )

    form.entries = data.available_revisions.map((revision, index) => {
      const existing = saved.get(revision.id)

      return {
        revision_id: revision.id,
        enabled: existing?.enabled
          ?? (revision.lifecycle_status === 'READY' && revision.last_probe_status === 'SUCCESS'),
        weight: existing?.weight ?? 100,
        max_concurrency: existing?.max_concurrency ?? null,
        priority: existing?.priority ?? ((index + 1) * 100)
      }
    })
  } catch (error) {
    toast.add({
      title: 'Could not load route pool',
      description: error instanceof Error ? error.message : 'Please try again.',
      color: 'error'
    })
  } finally {
    loadingPool.value = false
  }
}

watch(selectedAliasId, loadPool)

const revisionById = computed(() =>
  new Map((pool.value?.available_revisions ?? []).map(revision => [revision.id, revision]))
)

const isReady = (entry: PoolEntry) => {
  const revision = revisionById.value.get(entry.revision_id)
  return revision?.lifecycle_status === 'READY' && revision?.last_probe_status === 'SUCCESS'
}

const save = async () => {
  if (!selectedAliasId.value || !pool.value || saving.value) return

  saving.value = true
  try {
    const data = await api.request<PoolResponse>(
      `/admin/model-route-pools/${selectedAliasId.value}`,
      {
        method: 'PUT',
        body: {
          enabled: form.enabled,
          strategy: form.strategy,
          max_concurrency: form.max_concurrency,
          entries: form.entries.map(entry => ({
            revision_id: entry.revision_id,
            enabled: entry.enabled,
            weight: Math.max(1, Number(entry.weight || 1)),
            max_concurrency: entry.max_concurrency === null
              ? null
              : Math.max(1, Number(entry.max_concurrency || 1)),
            priority: Math.max(0, Number(entry.priority || 0))
          })),
          reason: reason.value.trim()
        }
      }
    )

    pool.value = data
    toast.add({
      title: 'Route pool saved',
      description: `${data.model.public_alias} now uses the saved routing policy.`,
      color: 'success'
    })
    await loadPool()
  } catch (error) {
    toast.add({
      title: 'Could not save route pool',
      description: error instanceof Error ? error.message : 'Please check the route settings.',
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Model route pools"
    eyebrow="Production routing"
    description="Keep one public model name while SP Cambo distributes requests across multiple healthy provider revisions."
  >
    <UCard class="sp-premium-card sp-app-card">
      <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
        <UFormField label="Public model">
          <USelectMenu
            v-model="selectedAliasId"
            :items="aliasOptions"
            value-key="value"
            class="w-full"
            placeholder="Choose a public model alias"
          />
        </UFormField>

        <UButton
          color="neutral"
          variant="subtle"
          icon="i-lucide-refresh-cw"
          :loading="loadingPool"
          :disabled="!selectedAliasId"
          @click="loadPool"
        >
          Refresh
        </UButton>
      </div>
    </UCard>

    <div v-if="pool" class="space-y-5">
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <SpMetric label="Public alias" icon="i-lucide-route" :value="pool.model.public_alias" />
        <SpMetric label="Provider" icon="i-lucide-server" :value="pool.model.provider_name" />
        <SpMetric
          label="Healthy routes"
          icon="i-lucide-activity"
          :value="String(pool.available_revisions.filter(item => item.lifecycle_status === 'READY' && item.last_probe_status === 'SUCCESS').length)"
        />
        <SpMetric
          label="Active requests"
          icon="i-lucide-gauge"
          :value="String(pool.available_revisions.reduce((sum, item) => sum + item.active_connections, 0))"
        />
      </div>

      <UCard class="sp-premium-card sp-app-card">
        <template #header>
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h2 class="font-semibold text-highlighted">Weighted least connections</h2>
              <p class="mt-1 max-w-3xl text-sm text-muted">
                Customers keep using <code>{{ pool.model.public_alias }}</code>. SP Cambo chooses the least-loaded READY route for each new request.
              </p>
            </div>
            <USwitch v-model="form.enabled" label="Enable route pool" />
          </div>
        </template>

        <div class="grid gap-4 md:grid-cols-2">
          <UFormField label="Strategy">
            <UInput model-value="LEAST_CONNECTIONS" disabled class="w-full font-mono" />
          </UFormField>

          <UFormField label="Global model concurrency">
            <UInput
              v-model.number="form.max_concurrency"
              type="number"
              min="1"
              max="10000"
              class="w-full"
              placeholder="Unlimited"
            />
            <template #help>
              <span class="text-xs text-muted">Optional cap across every enabled route for this public model.</span>
            </template>
          </UFormField>
        </div>

        <div class="mt-5 space-y-3">
          <div
            v-for="entry in form.entries"
            :key="entry.revision_id"
            class="rounded-xl border border-default/50 p-4"
          >
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_8rem_10rem_8rem_auto] xl:items-end">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <strong class="text-highlighted">
                    Revision {{ revisionById.get(entry.revision_id)?.route_version ?? '—' }}
                  </strong>
                  <UBadge
                    :color="isReady(entry) ? 'success' : 'warning'"
                    variant="subtle"
                  >
                    {{ isReady(entry) ? 'READY' : 'NOT READY' }}
                  </UBadge>
                  <UBadge
                    v-if="revisionById.get(entry.revision_id)?.is_legacy_active"
                    color="primary"
                    variant="subtle"
                  >
                    Legacy active
                  </UBadge>
                </div>
                <p class="mt-1 text-xs text-muted">
                  {{ revisionById.get(entry.revision_id)?.connection_type }}
                  · active {{ revisionById.get(entry.revision_id)?.active_connections ?? 0 }}
                  · timeout {{ revisionById.get(entry.revision_id)?.timeout_ms ?? 0 }}ms
                </p>
              </div>

              <UFormField label="Weight">
                <UInput v-model.number="entry.weight" type="number" min="1" max="1000" />
              </UFormField>

              <UFormField label="Route concurrency">
                <UInput
                  v-model.number="entry.max_concurrency"
                  type="number"
                  min="1"
                  max="10000"
                  placeholder="Unlimited"
                />
              </UFormField>

              <UFormField label="Priority">
                <UInput v-model.number="entry.priority" type="number" min="0" max="10000" />
              </UFormField>

              <USwitch
                v-model="entry.enabled"
                label="Use"
                :disabled="!isReady(entry)"
              />
            </div>
          </div>
        </div>

        <div class="mt-5">
          <UFormField label="Audit reason">
            <UTextarea v-model="reason" :rows="2" class="w-full" />
          </UFormField>
        </div>

        <template #footer>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-muted">
              A 429 is returned when every enabled route reaches its configured concurrency cap.
            </p>
            <UButton
              icon="i-lucide-save"
              :loading="saving"
              :disabled="reason.trim().length < 10"
              @click="save"
            >
              Save route pool
            </UButton>
          </div>
        </template>
      </UCard>

      <UAlert
        color="info"
        variant="subtle"
        icon="i-lucide-shuffle"
        title="Public model name stays unchanged"
        description="This changes only private routing. Customer API calls continue to use the same SP Cambo public alias."
      />
    </div>

    <UCard v-else-if="!loadingPool" class="sp-premium-card sp-app-card">
      <div class="py-10 text-center">
        <UIcon name="i-lucide-route" class="mx-auto size-8 text-muted" />
        <p class="mt-3 text-sm text-muted">Choose a public model to configure its production route pool.</p>
      </div>
    </UCard>
  </SpDashboardPage>
</template>
