<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Telegram Store admin', robots: 'noindex' })

const api = useSpApi()
const toast = useToast()
const overview = await useSpResource('admin:telegram-store', () => api.admin.telegramStore(), { server: false })
const packages = await useSpResource('admin:telegram-packages', () => api.admin.packages(), { server: false })
const sending = ref(false)
const retryingId = ref<string | null>(null)
const form = reactive({ title: '', body: '', package_id: undefined as string | undefined })

const packageOptions = computed(() => [
  { label: 'No Buy button', value: undefined },
  ...(packages.data.value ?? [])
    .filter(item => item.enabled && item.customer_visible && item.auto_creates_api_key && item.stock_status !== 'OUT_OF_STOCK')
    .map(item => ({ label: item.name, value: item.id }))
])

const retryFailed = async (id: string) => {
  const reason = window.prompt('Why are you retrying these failed Telegram recipients?', 'Retry failed recipients after reviewing the delivery errors')
  if (!reason || reason.trim().length < 10) return

  retryingId.value = id
  try {
    const result = await api.admin.retryTelegramAnnouncementFailures(id, reason.trim())
    toast.add({ title: 'Retry queued', description: result.message, color: 'success' })
    await overview.refresh()
  } catch (error) {
    toast.add({ title: 'Retry failed', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally {
    retryingId.value = null
  }
}

const broadcast = async () => {
  if (!form.title.trim() || !form.body.trim() || sending.value) return

  sending.value = true
  try {
    const result = await api.admin.sendTelegramAnnouncement({
      title: form.title.trim(),
      body: form.body.trim(),
      package_id: form.package_id ?? null
    })
    toast.add({ title: 'Telegram update queued', description: result.message, color: 'success' })
    form.title = ''
    form.body = ''
    form.package_id = undefined
    await overview.refresh()
  } catch (error) {
    toast.add({ title: 'Could not queue update', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Telegram Store"
    eyebrow="One customer Store Bot"
    description="Telegram is a separate click-to-buy storefront. Website checkout stays normal and never sends Telegram order alerts. Products, stock and promotions are controlled from SP Cambo Admin."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        :loading="overview.loading.value"
        @click="overview.refresh()"
      >
        Refresh
      </UButton>
    </template>

    <SpAsyncSection
      :loading="overview.initialLoading.value"
      :failed="overview.failed.value"
      :unavailable="overview.unavailable.value"
      :error-message="overview.error.value?.message"
      @retry="overview.refresh()"
    >
      <div v-if="overview.data.value" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SpMetric
            label="Store Bot"
            icon="i-lucide-bot"
            :value="overview.data.value.storefront_bot_configured ? 'Configured' : 'Needs setup'"
          />
          <SpMetric
            label="Telegram customers"
            icon="i-lucide-users"
            :value="formatCount(overview.data.value.active_accounts)"
          />
          <SpMetric
            label="Update subscribers"
            icon="i-lucide-bell"
            :value="formatCount(overview.data.value.announcement_subscribers)"
          />
          <SpMetric
            label="Sellable packages"
            icon="i-lucide-package-check"
            :value="formatCount(overview.data.value.sellable_package_count)"
          />
          <SpMetric
            label="Limited stock"
            icon="i-lucide-boxes"
            :value="formatCount(overview.data.value.limited_stock_packages)"
          />
          <SpMetric
            label="Sold out"
            icon="i-lucide-package-x"
            :value="formatCount(overview.data.value.sold_out_packages)"
          />
          <SpMetric
            label="Queued updates"
            icon="i-lucide-clock-3"
            :value="formatCount(overview.data.value.queued_announcements)"
          />
          <SpMetric
            label="New-order activity"
            icon="i-lucide-shopping-cart"
            :value="overview.data.value.purchase_activity_enabled ? 'Enabled' : 'Disabled'"
          />
        </div>

        <UAlert
          color="success"
          variant="subtle"
          icon="i-lucide-shield-check"
          title="Website Telegram messaging is disabled"
          description="Website orders, website payments and website fulfillment stay on the website. Only purchases made through this Telegram Store Bot can create Telegram NEW ORDER activity."
        />

        <UCard class="sp-premium-card sp-app-card">
          <template #header>
            <div>
              <h2 class="font-semibold text-highlighted">Automatic Store Bot messages</h2>
              <p class="mt-1 text-sm text-muted">You do not build these buttons in BotFather. SP Cambo creates the menu, product buttons and Buy Now buttons from your database.</p>
            </div>
          </template>

          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div class="sp-admin-mini-card rounded-xl border border-default p-4">
              <p class="font-medium text-highlighted">📦 New package</p>
              <p class="mt-1 text-sm text-muted">Automatically sent to opted-in subscribers when a sellable package is published.</p>
              <UBadge class="mt-3" color="primary" variant="subtle">🛒 Buy Now</UBadge>
            </div>
            <div class="sp-admin-mini-card rounded-xl border border-default p-4">
              <p class="font-medium text-highlighted">✨ Package updated</p>
              <p class="mt-1 text-sm text-muted">Price, limits, model access or customer-facing package changes can announce automatically.</p>
              <UBadge class="mt-3" color="primary" variant="subtle">🛒 Buy Now</UBadge>
            </div>
            <div class="sp-admin-mini-card rounded-xl border border-default p-4">
              <p class="font-medium text-highlighted">📥 Stock / restock</p>
              <p class="mt-1 text-sm text-muted">Adding finite stock sends an inventory update; stock returning from 0 is labeled RESTOCKED.</p>
              <UBadge class="mt-3" color="primary" variant="subtle">🛒 Buy Now</UBadge>
            </div>
            <div class="sp-admin-mini-card rounded-xl border border-default p-4">
              <p class="font-medium text-highlighted">🧠 New model</p>
              <p class="mt-1 text-sm text-muted">Published model availability is announced to Store Bot subscribers.</p>
              <UBadge class="mt-3" color="primary" variant="subtle">Open Store</UBadge>
            </div>
            <div class="sp-admin-mini-card rounded-xl border border-default p-4">
              <p class="font-medium text-highlighted">🏷 Promotion</p>
              <p class="mt-1 text-sm text-muted">Enabled promotions can be broadcast automatically and link to an eligible package.</p>
              <UBadge class="mt-3" color="primary" variant="subtle">🛒 Buy Now</UBadge>
            </div>
            <div class="sp-admin-mini-card rounded-xl border border-default p-4">
              <p class="font-medium text-highlighted">🎉 NEW ORDER</p>
              <p class="mt-1 text-sm text-muted">Only a paid, fulfilled and delivered Telegram Store purchase can create subscriber purchase activity.</p>
              <UBadge class="mt-3" color="primary" variant="subtle">🛒 Buy Now</UBadge>
            </div>
          </div>

          <div class="sp-admin-callout mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-elevated p-4">
            <div>
              <p class="font-medium text-highlighted">Admin controls the Telegram catalog</p>
              <p class="mt-1 text-sm text-muted">Create packages, set finite/unlimited stock, add stock and publish products in Admin → Packages.</p>
            </div>
            <UButton to="/admin/packages" icon="i-lucide-package-plus" color="neutral" variant="subtle">Manage packages & stock</UButton>
          </div>
        </UCard>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
          <UCard class="sp-premium-card sp-app-card">
            <template #header>
              <div>
                <h2 class="font-semibold text-highlighted">Send a manual update</h2>
                <p class="mt-1 text-sm text-muted">For service news or a custom promotion. Choose a package to attach a Buy Now button.</p>
              </div>
            </template>

            <div class="space-y-4">
              <UFormField label="Title">
                <UInput v-model="form.title" class="w-full" placeholder="Weekend token offer" />
              </UFormField>
              <UFormField label="Message">
                <UTextarea v-model="form.body" :rows="6" class="w-full" placeholder="Short, useful message for subscribed customers" />
              </UFormField>
              <UFormField label="Optional Buy Now package">
                <USelectMenu v-model="form.package_id" :items="packageOptions" value-key="value" class="w-full" />
              </UFormField>
              <UAlert
                color="neutral"
                variant="subtle"
                icon="i-lucide-bell"
                title="Subscriber controlled"
                description="Customers can mute or re-enable Store updates from the bot's Updates menu."
              />
              <UButton
                block
                icon="i-lucide-send"
                :loading="sending"
                :disabled="!form.title.trim() || !form.body.trim()"
                @click="broadcast"
              >
                Queue Telegram update
              </UButton>
            </div>
          </UCard>

          <UCard class="sp-premium-card sp-app-card">
            <template #header>
              <div>
                <h2 class="font-semibold text-highlighted">Recent Store Bot update jobs</h2>
                <p class="mt-1 text-sm text-muted">The scheduler sends queued subscriber messages in batches and records delivery results.</p>
              </div>
            </template>

            <div v-if="overview.data.value.recent_announcements.length" class="divide-y divide-default">
              <div v-for="row in overview.data.value.recent_announcements" :key="row.id" class="py-4 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <div class="flex flex-wrap items-center gap-2">
                      <strong class="text-highlighted">{{ row.title }}</strong>
                      <UBadge color="neutral" variant="subtle">{{ row.kind }}</UBadge>
                      <UBadge :color="row.failed_count ? 'warning' : row.status === 'COMPLETED' ? 'success' : 'primary'" variant="subtle">{{ row.status }}</UBadge>
                    </div>
                    <p class="mt-1 line-clamp-2 text-sm text-muted">{{ row.body }}</p>
                  </div>
                  <span class="text-xs text-dimmed">{{ row.created_at ? formatDateTime(row.created_at) : '—' }}</span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-muted">
                  <span>Recipients {{ row.recipient_count }}</span>
                  <span class="text-success">Sent {{ row.sent_count }}</span>
                  <span v-if="row.failed_count" class="text-warning">Failed {{ row.failed_count }}</span>
                  <span v-if="row.package">Buy → {{ row.package.name }}</span>
                  <span v-if="row.model">Model → {{ row.model.public_alias }}</span>
                  <UButton
                    v-if="row.failed_count"
                    size="xs"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-refresh-cw"
                    :loading="retryingId === row.id"
                    @click="retryFailed(row.id)"
                  >
                    Retry failed recipients
                  </UButton>
                </div>
              </div>
            </div>
            <p v-else class="py-10 text-center text-sm text-muted">No Telegram Store update jobs yet.</p>
          </UCard>
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
