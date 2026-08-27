<script setup lang="ts">
import type { AdminRecoveryAction } from '~/types/admin'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Operations', robots: 'noindex' })

const api = useSpApi()
const toast = useToast()
const operations = await useSpResource('admin:operations', () => api.admin.operations(), { server: false })
const health = await useSpResource('admin:operations-health', () => api.admin.systemHealth(), { server: false })
const reconciliation = await useSpResource('admin:reconciliation-reservations', () => api.admin.reconciliationReservations({ limit: 50 }), { server: false })

const action = ref<AdminRecoveryAction>('payments')
const batch = ref(10)
const reason = ref('Manual recovery after operator review')
const running = ref(false)
const releasingId = ref<string | null>(null)

const refreshOperations = async () => {
  await Promise.all([operations.refresh(), health.refresh(), reconciliation.refresh()])
}

const actionOptions = [
  { label: 'Payments', value: 'payments' },
  { label: 'Telegram purchases', value: 'telegram_purchases' },
  { label: 'Inference reservations', value: 'reservations' },
  { label: 'Expired entitlements', value: 'entitlements' },
  { label: 'Telegram announcements', value: 'announcements' }
]

const runRecovery = async () => {
  if (reason.value.trim().length < 10) {
    toast.add({ title: 'Reason required', description: 'Write at least 10 characters for the audit trail.', color: 'warning' })
    return
  }
  running.value = true
  try {
    const result = await api.admin.runRecovery({ action: action.value, batch: batch.value, reason: reason.value.trim() })
    toast.add({ title: 'Recovery completed', description: JSON.stringify(result.result), color: 'success' })
    await Promise.all([operations.refresh(), health.refresh(), reconciliation.refresh()])
  } catch (error) {
    toast.add({ title: 'Recovery failed', description: error instanceof Error ? error.message : 'The recovery action failed.', color: 'error' })
  } finally {
    running.value = false
  }
 }

const releaseReconciliation = async (id: string) => {
  if (reason.value.trim().length < 10) {
    toast.add({ title: 'Reason required', description: 'Write at least 10 characters explaining why upstream usage is confirmed absent.', color: 'warning' })
    return
  }

  const confirmation = window.prompt('This releases held customer balance. Type exactly: CONFIRMED NO UPSTREAM USAGE')
  if (confirmation !== 'CONFIRMED NO UPSTREAM USAGE') {
    toast.add({ title: 'Release cancelled', description: 'The confirmation phrase did not match.', color: 'neutral' })
    return
  }

  releasingId.value = id
  try {
    await api.admin.releaseReconciliationReservation(id, reason.value.trim(), 'CONFIRMED NO UPSTREAM USAGE')
    toast.add({ title: 'Reservation released', description: 'Held balance was released after explicit no-usage confirmation. The action was audited.', color: 'success' })
    await Promise.all([operations.refresh(), reconciliation.refresh()])
  } catch (error) {
    toast.add({ title: 'Release failed', description: error instanceof Error ? error.message : 'The reconciliation reservation could not be released.', color: 'error' })
  } finally {
    releasingId.value = null
  }
}
</script>

<template>
  <SpDashboardPage
    title="Operations"
    icon="i-lucide-activity"
    description="Recovery queues, scheduler-facing state and dependency health. Recovery actions are audited and use the same idempotent services as normal production processing."
  >
    <template #actions>
      <UButton color="neutral" variant="subtle" icon="i-lucide-refresh-cw" @click="refreshOperations">
        Refresh
      </UButton>
    </template>

    <SpAsyncSection
      :loading="operations.initialLoading.value"
      :unavailable="operations.unavailable.value"
      :failed="operations.failed.value"
      :error-message="operations.error.value?.message"
      error-title="Operations state could not be loaded"
      @retry="operations.refresh()"
    >
      <div v-if="operations.data.value" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SpMetric label="Release" icon="i-lucide-tag" :value="operations.data.value.release" hint="Current control-plane checkpoint" />
          <SpMetric label="Recoverable payments" icon="i-lucide-qr-code" :value="String(operations.data.value.payments_recoverable)" hint="Stale VERIFYING leases" />
          <SpMetric label="Telegram recovery" icon="i-lucide-send" :value="String(operations.data.value.telegram_recoverable)" hint="Undelivered eligible purchases" />
          <SpMetric label="Stale reservations" icon="i-lucide-timer-reset" :value="String(operations.data.value.reservations.stale)" hint="Expired ACTIVE reservations only" />
          <SpMetric label="Active API keys" icon="i-lucide-key-round" :value="String(operations.data.value.api_keys.active)" :hint="`${operations.data.value.api_keys.total} total`" />
          <SpMetric label="Active entitlements" icon="i-lucide-layers" :value="String(operations.data.value.entitlements.active)" :hint="`${operations.data.value.entitlements.expired} expired`" />
          <SpMetric label="Announcement failures" icon="i-lucide-megaphone" :value="String(operations.data.value.telegram_announcement_failures)" hint="Recipients requiring retry" />
          <SpMetric label="Reconciliation required" icon="i-lucide-refresh-cw" :value="String(operations.data.value.reservations.reconciliation_required)" hint="Inference reservations" />
        </div>

        <UCard class="sp-app-card">
          <template #header>
            <div>
              <p class="font-semibold text-highlighted">Run a safe recovery batch</p>
              <p class="mt-1 text-sm text-muted">Only use this after reviewing the affected queue. The reason is written to the audit log.</p>
            </div>
          </template>
          <div class="grid gap-4 lg:grid-cols-[1fr_10rem_2fr_auto] lg:items-end">
            <UFormField label="Recovery area">
              <USelect v-model="action" :items="actionOptions" value-key="value" class="w-full" />
            </UFormField>
            <UFormField label="Batch size">
              <UInput v-model.number="batch" type="number" :min="1" :max="200" />
            </UFormField>
            <UFormField label="Operator reason">
              <UInput v-model="reason" placeholder="Why this recovery is being run" />
            </UFormField>
            <UButton icon="i-lucide-play" :loading="running" @click="runRecovery">Run recovery</UButton>
          </div>
        </UCard>

        <UCard class="sp-app-card">
          <template #header>
            <div>
              <p class="font-semibold text-highlighted">Inference reconciliation hold</p>
              <p class="mt-1 text-sm text-muted">These reservations are intentionally not auto-expired. Keep the balance held until authoritative usage settles, or release it only after confirming the upstream provider processed no usage.</p>
            </div>
          </template>

          <SpAsyncSection
            :loading="reconciliation.initialLoading.value"
            :unavailable="reconciliation.unavailable.value"
            :failed="reconciliation.failed.value"
            :error-message="reconciliation.error.value?.message"
            error-title="Reconciliation queue could not be loaded"
            @retry="reconciliation.refresh()"
          >
            <div v-if="(reconciliation.data.value?.length ?? 0) === 0" class="rounded-lg border border-dashed border-default p-5 text-sm text-muted">
              No inference reservations currently require reconciliation.
            </div>
            <div v-else class="space-y-3">
              <div v-for="item in reconciliation.data.value" :key="item.id" class="rounded-lg border border-default p-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                  <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <UBadge color="warning" variant="subtle">HELD</UBadge>
                      <span class="font-medium text-highlighted">{{ item.public_model }}</span>
                      <span class="text-xs text-muted">{{ item.billing_mode }}</span>
                    </div>
                    <div class="grid gap-x-6 gap-y-1 text-sm text-muted sm:grid-cols-2">
                      <p><span class="text-highlighted">Reserved:</span> {{ item.reserved_units }}</p>
                      <p><span class="text-highlighted">Customer:</span> {{ item.user?.email ?? 'Unknown' }}</p>
                      <p><span class="text-highlighted">API key:</span> {{ item.api_key?.masked ?? 'Playground / unavailable' }}</p>
                      <p><span class="text-highlighted">Requested:</span> {{ item.requested_at ? new Date(item.requested_at).toLocaleString() : 'Unknown' }}</p>
                    </div>
                    <p class="text-sm"><span class="font-medium text-highlighted">Reason:</span> <span class="text-muted">{{ item.reason ?? 'Not recorded' }}</span></p>
                  </div>
                  <UButton
                    color="error"
                    variant="soft"
                    icon="i-lucide-unlock"
                    :loading="releasingId === item.id"
                    :disabled="releasingId !== null && releasingId !== item.id"
                    @click="releaseReconciliation(item.id)"
                  >
                    Release: confirmed no usage
                  </UButton>
                </div>
              </div>
            </div>
          </SpAsyncSection>
        </UCard>

        <UCard class="sp-app-card">
          <template #header>
            <p class="font-semibold text-highlighted">Dependency health</p>
          </template>
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="component in health.data.value?.components ?? []" :key="component.key" class="rounded-lg border border-default p-3">
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-medium text-highlighted">{{ component.label }}</span>
                <UBadge :color="component.status === 'operational' ? 'success' : component.status === 'outage' ? 'error' : 'warning'" variant="subtle">
                  {{ component.status }}
                </UBadge>
              </div>
              <p v-if="component.detail" class="mt-2 text-xs text-muted">{{ component.detail }}</p>
            </div>
          </div>
        </UCard>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
