<script setup lang="ts">
useSeoMeta({
  title: 'Service status',
  description: 'Live reachability of the SP Cambo control plane, plus per-component service status.'
})

const api = useSpApi()

const health = await useSpResource('status:health', () => api.health())
const system = await useSpResource('status:system', () => api.status.system())

const lastCheckedAt = ref<string | null>(null)

const refreshAll = async () => {
  await Promise.all([health.refresh(), system.refresh()])
  lastCheckedAt.value = new Date().toISOString()
}

/** Reachability is measured, never assumed: only a real 2xx counts as reachable. */
const controlPlane = computed(() => {
  if (health.loading.value && health.data.value === null) {
    return { status: 'checking', label: 'Checking…', tone: 'neutral' as const }
  }

  if (health.error.value) {
    return health.error.value.code === 'network_unreachable'
      ? { status: 'unreachable', label: 'Unreachable', tone: 'error' as const }
      : { status: 'degraded', label: 'Responding with errors', tone: 'warning' as const }
  }

  if (health.data.value?.status === 'ok') {
    return { status: 'operational', label: 'Operational', tone: 'success' as const }
  }

  return { status: 'unknown', label: 'Unknown', tone: 'neutral' as const }
})

const toneRing = {
  success: 'bg-success',
  warning: 'bg-warning',
  error: 'bg-error',
  neutral: 'bg-dimmed'
}

/**
 * True when the published summary claims health this page has just measured to be
 * absent.
 *
 * The published state is the control plane's to declare and this page does not
 * override it — but reachability here is measured from a real response, and a green
 * "operational" badge sitting above a failed probe is the one thing a status page
 * must never show. When the two disagree, both are shown and the disagreement is
 * named, so a customer whose requests are failing is not told everything is fine.
 */
const publishedContradictsMeasured = computed(() =>
  system.data.value?.overall === 'operational'
  && (controlPlane.value.tone === 'error' || controlPlane.value.tone === 'warning')
)

onMounted(() => {
  lastCheckedAt.value = new Date().toISOString()

  const timer = setInterval(() => {
    void refreshAll()
  }, 60_000)

  onBeforeUnmount(() => clearInterval(timer))
})
</script>

<template>
  <div>
    <UContainer class="py-14 sm:py-16">
      <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <div class="max-w-2xl space-y-4">
          <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance">
            Service status
          </h1>
          <p class="text-lg text-muted text-pretty">
            Measured from your browser against the SP Cambo control plane. This page never claims
            a service is healthy without a successful response.
          </p>
        </div>

        <UButton
          color="neutral"
          variant="subtle"
          icon="i-lucide-refresh-cw"
          :loading="health.loading.value || system.loading.value"
          @click="refreshAll()"
        >
          Re-check now
        </UButton>
      </div>

      <div class="mt-10 rounded-xl border border-default bg-elevated/30 p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-4">
            <span class="relative flex size-3">
              <span
                v-if="controlPlane.tone === 'success'"
                class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-75"
              />
              <span
                class="relative inline-flex size-3 rounded-full"
                :class="toneRing[controlPlane.tone]"
              />
            </span>
            <div class="space-y-1">
              <p class="font-medium text-highlighted">
                Control plane · {{ controlPlane.label }}
              </p>
              <p class="text-sm text-muted">
                Accounts, packages, orders, entitlements and API key management.
              </p>
            </div>
          </div>

          <p class="text-xs text-muted">
            Checked {{ lastCheckedAt ? formatDateTime(lastCheckedAt) : '—' }}
          </p>
        </div>

        <p
          v-if="controlPlane.tone === 'error'"
          class="mt-4 rounded-lg bg-error/10 px-4 py-3 text-sm text-error"
        >
          The control plane did not respond. Requests from your applications may also be failing.
        </p>
      </div>

      <div class="mt-10 space-y-4">
        <SpSectionHeading
          title="Components"
          description="Per-component health as published by the SP Cambo control plane. Only the components listed below are reported; anything absent is not being measured here."
        />

        <SpAsyncSection
          :loading="system.initialLoading.value"
          :unavailable="system.unavailable.value"
          :failed="system.failed.value"
          :offline="system.error.value?.code === 'network_unreachable'"
          :error-message="system.error.value?.message"
          unavailable-description="Per-component status is published by the SP Cambo control plane and has not been made available yet. Control-plane reachability above is measured directly and is accurate."
          loading-variant="rows"
          :loading-count="4"
          @retry="system.refresh()"
        >
          <div
            v-if="system.data.value"
            class="space-y-4"
          >
            <div class="flex flex-wrap items-center gap-3">
              <SpStatusBadge
                :status="system.data.value.overall"
                size="lg"
              />
              <p class="text-sm text-muted">
                Published {{ formatDateTime(system.data.value.updated_at) }}
              </p>
            </div>

            <UAlert
              v-if="publishedContradictsMeasured"
              icon="i-lucide-triangle-alert"
              color="warning"
              variant="subtle"
              title="This does not match what we just measured"
              :description="controlPlane.tone === 'error'
                ? 'SP Cambo publishes an operational summary, but the control plane did not respond to this page a moment ago. Trust the measured result above: your requests may be failing.'
                : 'SP Cambo publishes an operational summary, but the control plane responded with an error to this page a moment ago. Trust the measured result above.'"
            />

            <ul class="divide-y divide-default overflow-hidden rounded-lg border border-default">
              <li
                v-for="component in system.data.value.components"
                :key="component.key"
                class="flex flex-col gap-2 bg-elevated/30 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0 space-y-1">
                  <p class="font-medium text-highlighted">
                    {{ component.label }}
                  </p>
                  <p
                    v-if="component.detail"
                    class="text-sm text-muted"
                  >
                    {{ component.detail }}
                  </p>
                </div>
                <SpStatusBadge :status="component.status" />
              </li>
            </ul>
          </div>
        </SpAsyncSection>
      </div>
    </UContainer>

    <div class="border-t border-default bg-elevated/25">
      <UContainer class="py-12">
        <div class="grid gap-6 sm:grid-cols-3">
          <div class="space-y-2">
            <h2 class="font-medium text-highlighted">
              Seeing errors on your side?
            </h2>
            <p class="text-sm text-muted">
              Check the error code your client received before assuming an outage. Most failures are
              expired packages, revoked keys or rate limits.
            </p>
            <UButton
              to="/docs/errors"
              color="neutral"
              variant="link"
              size="sm"
              class="px-0"
              trailing-icon="i-lucide-arrow-right"
            >
              Error reference
            </UButton>
          </div>
          <div class="space-y-2">
            <h2 class="font-medium text-highlighted">
              Rate limits
            </h2>
            <p class="text-sm text-muted">
              Limits are applied per API key and per package. A burst of 429s is not an outage.
            </p>
            <UButton
              to="/docs/rate-limits"
              color="neutral"
              variant="link"
              size="sm"
              class="px-0"
              trailing-icon="i-lucide-arrow-right"
            >
              Rate limit reference
            </UButton>
          </div>
          <div class="space-y-2">
            <h2 class="font-medium text-highlighted">
              Check your own account
            </h2>
            <p class="text-sm text-muted">
              Your dashboard shows key status, remaining balance and the last requests SP Cambo saw.
            </p>
            <UButton
              to="/dashboard"
              color="neutral"
              variant="link"
              size="sm"
              class="px-0"
              trailing-icon="i-lucide-arrow-right"
            >
              Open dashboard
            </UButton>
          </div>
        </div>
      </UContainer>
    </div>
  </div>
</template>
