<script setup lang="ts">
import type { AdminAuditLog } from '~/types/admin'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Audit log', robots: 'noindex' })

const api = useSpApi()
const rows = await useSpResource('admin:audit-log', () => api.admin.auditLogs({ limit: 150 }), { server: false })
const search = ref('')

const filtered = computed<AdminAuditLog[]>(() => {
  const q = search.value.trim().toLowerCase()
  const data = rows.data.value ?? []
  if (!q) return data
  return data.filter(row => [row.action, row.subject_type, row.subject_id, row.reason, row.actor?.name, row.actor?.email]
    .filter(Boolean)
    .some(value => String(value).toLowerCase().includes(q)))
})

const prettyMetadata = (metadata: Record<string, unknown> | null) => metadata ? JSON.stringify(metadata, null, 2) : '—'
</script>

<template>
  <SpDashboardPage
    title="Audit log"
    icon="i-lucide-scroll-text"
    description="Immutable operator and system actions. Secret-like metadata is redacted before it is persisted."
  >
    <template #actions>
      <UButton color="neutral" variant="subtle" icon="i-lucide-refresh-cw" :loading="rows.loading.value" @click="rows.refresh()">
        Refresh
      </UButton>
    </template>

    <SpAsyncSection
      :loading="rows.initialLoading.value"
      :unavailable="rows.unavailable.value"
      :failed="rows.failed.value"
      :error-message="rows.error.value?.message"
      error-title="Audit log could not be loaded"
      @retry="rows.refresh()"
    >
      <div class="space-y-4">
        <UInput v-model="search" icon="i-lucide-search" placeholder="Search action, subject, actor or reason" class="max-w-xl" />

        <div v-if="filtered.length" class="overflow-hidden rounded-xl border border-default">
          <div v-for="row in filtered" :key="row.id" class="border-b border-default p-4 last:border-b-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-medium text-highlighted">{{ row.action }}</p>
                <p class="mt-1 text-xs text-muted">{{ row.subject_type }} · {{ row.subject_id }}</p>
              </div>
              <div class="text-right text-xs text-muted">
                <p>{{ row.actor?.email ?? 'system' }}</p>
                <p>{{ row.created_at ? new Date(row.created_at).toLocaleString() : '—' }}</p>
              </div>
            </div>
            <p v-if="row.reason" class="mt-3 text-sm text-muted">{{ row.reason }}</p>
            <details v-if="row.metadata" class="mt-3">
              <summary class="cursor-pointer text-xs font-medium text-muted">Metadata</summary>
              <pre class="mt-2 overflow-auto whitespace-pre-wrap rounded-lg bg-elevated p-3 text-xs">{{ prettyMetadata(row.metadata) }}</pre>
            </details>
          </div>
        </div>

        <div v-else class="rounded-xl border border-dashed border-default p-8 text-center text-sm text-muted">
          No audit records match this view.
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
