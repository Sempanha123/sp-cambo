<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'System health', robots: 'noindex' })

const api = useSpApi()
const health = await useSpResource('admin:system-health-page', () => api.admin.systemHealth(), { server: false })
const refreshedAt = computed(() => new Date().toLocaleTimeString())
</script>

<template>
  <SpDashboardPage title="System health" icon="i-lucide-heart-pulse" description="Live dependency readiness for the control plane, inference route, scheduler, payments and Telegram services.">
    <template #actions><UButton color="neutral" variant="subtle" icon="i-lucide-refresh-cw" @click="health.refresh()">Refresh</UButton></template>
    <SpAsyncSection :loading="health.initialLoading.value" :unavailable="health.unavailable.value" :failed="health.failed.value" :error-message="health.error.value?.message" error-title="System health could not be loaded" @retry="health.refresh()">
      <div v-if="health.data.value" class="space-y-5">
        <UCard class="sp-app-card">
          <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm text-muted">Overall state</p><p class="mt-1 text-2xl font-semibold text-highlighted">{{ health.data.value.overall }}</p></div><UBadge :color="health.data.value.overall === 'operational' ? 'success' : health.data.value.overall === 'outage' ? 'error' : 'warning'" size="lg" variant="subtle">{{ health.data.value.overall }}</UBadge></div>
          <p class="mt-3 text-xs text-muted">Last browser refresh: {{ refreshedAt }}</p>
        </UCard>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <UCard v-for="component in health.data.value.components" :key="component.key" class="sp-app-card">
            <div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-highlighted">{{ component.label }}</p><p v-if="component.detail" class="mt-2 text-sm text-muted">{{ component.detail }}</p></div><UBadge :color="component.status === 'operational' ? 'success' : component.status === 'outage' ? 'error' : 'warning'" variant="subtle">{{ component.status }}</UBadge></div>
          </UCard>
        </div>
        <UAlert color="neutral" variant="subtle" icon="i-lucide-shield-check" title="Health responses are secret-safe" description="Provider credentials, Telegram tokens, Bakong tokens and customer API keys are never returned by this endpoint." />
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
