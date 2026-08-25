<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Telegram Store admin', robots: 'noindex' })

const api = useSpApi()
const toast = useToast()
const overview = await useSpResource('admin:telegram-store', () => api.admin.telegramStore(), { server: false })
const packages = await useSpResource('admin:telegram-packages', () => api.admin.packages(), { server: false })
const sending = ref(false)
const form = reactive({ title: '', body: '', package_id: undefined as string | undefined })
const packageOptions = computed(() => [
  { label: 'No package button', value: undefined },
  ...(packages.data.value ?? []).filter(item => item.enabled && item.customer_visible).map(item => ({ label: item.name, value: item.id }))
])

const broadcast = async () => {
  if (!form.title.trim() || !form.body.trim() || sending.value) return
  sending.value = true
  try {
    const result = await api.admin.sendTelegramAnnouncement({ title: form.title.trim(), body: form.body.trim(), package_id: form.package_id ?? null })
    toast.add({ title: 'Telegram announcement queued', description: result.message, color: 'success' })
    form.title = ''
    form.body = ''
    form.package_id = undefined
    await overview.refresh()
  } catch (error) {
    toast.add({ title: 'Could not queue announcement', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally { sending.value = false }
}
</script>

<template>
  <SpDashboardPage title="Telegram Store" eyebrow="Storefront & broadcasts" description="Monitor the standalone Telegram storefront and send optional updates without copying customer API keys or private request content into Telegram.">
    <template #actions><UButton color="neutral" variant="subtle" icon="i-lucide-refresh-cw" :loading="overview.loading.value" @click="overview.refresh()">Refresh</UButton></template>

    <SpAsyncSection :loading="overview.initialLoading.value" :failed="overview.failed.value" :unavailable="overview.unavailable.value" :error-message="overview.error.value?.message" @retry="overview.refresh()">
      <div v-if="overview.data.value" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SpMetric label="Bot status" icon="i-lucide-bot" :value="overview.data.value.configured ? 'Configured' : 'Needs setup'" />
          <SpMetric label="Telegram customers" icon="i-lucide-users" :value="formatCount(overview.data.value.active_accounts)" />
          <SpMetric label="Update subscribers" icon="i-lucide-bell" :value="formatCount(overview.data.value.announcement_subscribers)" />
          <SpMetric label="Queued broadcasts" icon="i-lucide-send-horizontal" :value="formatCount(overview.data.value.queued_announcements)" />
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
          <UCard class="sp-premium-card">
            <template #header><div><h2 class="font-semibold text-highlighted">Send an update</h2><p class="mt-1 text-sm text-muted">Useful for promos or service news. New published models and sellable package changes are announced automatically.</p></div></template>
            <div class="space-y-4">
              <UFormField label="Title"><UInput v-model="form.title" class="w-full" placeholder="Weekend token offer" /></UFormField>
              <UFormField label="Message"><UTextarea v-model="form.body" :rows="6" class="w-full" placeholder="Short, useful message for subscribed customers" /></UFormField>
              <UFormField label="Optional Buy button package"><USelectMenu v-model="form.package_id" :items="packageOptions" value-key="value" class="w-full" /></UFormField>
              <UAlert color="neutral" variant="subtle" icon="i-lucide-shield-check" title="Customer controlled" description="Customers can mute or re-enable storefront announcements at any time from the bot's Updates menu." />
              <UButton block icon="i-lucide-send" :loading="sending" :disabled="!form.title.trim() || !form.body.trim()" @click="broadcast">Queue Telegram update</UButton>
            </div>
          </UCard>

          <UCard class="sp-premium-card">
            <template #header><div><h2 class="font-semibold text-highlighted">Recent announcement jobs</h2><p class="mt-1 text-sm text-muted">The scheduler delivers queued rows in small batches and records successes/failures.</p></div></template>
            <div v-if="overview.data.value.recent_announcements.length" class="divide-y divide-default">
              <div v-for="row in overview.data.value.recent_announcements" :key="row.id" class="py-4 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><div class="flex flex-wrap items-center gap-2"><strong class="text-highlighted">{{ row.title }}</strong><UBadge color="neutral" variant="subtle">{{ row.kind }}</UBadge><UBadge :color="row.failed_count ? 'warning' : row.status === 'COMPLETED' ? 'success' : 'primary'" variant="subtle">{{ row.status }}</UBadge></div><p class="mt-1 line-clamp-2 text-sm text-muted">{{ row.body }}</p></div><span class="text-xs text-dimmed">{{ row.created_at ? formatDateTime(row.created_at) : '—' }}</span></div>
                <div class="mt-3 flex flex-wrap gap-4 text-xs text-muted"><span>Recipients {{ row.recipient_count }}</span><span class="text-success">Sent {{ row.sent_count }}</span><span v-if="row.failed_count" class="text-warning">Failed {{ row.failed_count }}</span><span v-if="row.package">Buy → {{ row.package.name }}</span><span v-if="row.model">Model → {{ row.model.public_alias }}</span></div>
              </div>
            </div>
            <p v-else class="py-10 text-center text-sm text-muted">No Telegram announcement jobs yet.</p>
          </UCard>
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
