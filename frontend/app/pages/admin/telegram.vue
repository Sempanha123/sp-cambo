<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Telegram Store admin', robots: 'noindex' })

type DeliveryMode = 'OFF' | 'BOT_ONLY' | 'CHANNELS_ONLY' | 'BOTH'

interface TelegramChannel {
  id: string
  name: string
  chat_id: string
  enabled: boolean
  created_at: string | null
  updated_at: string | null
}

interface TelegramNotificationSettings {
  enabled: boolean
  event_routes: Record<string, DeliveryMode>
  qr_countdown_enabled: boolean
  qr_countdown_interval_seconds: number
  event_definitions: Array<{ key: string, label: string }>
  route_modes: Array<{ value: DeliveryMode, label: string }>
}

interface TelegramAnnouncementRow {
  id: string
  kind: string
  title: string
  body: string
  status: string
  recipient_count: number
  sent_count: number
  failed_count: number
  package: { id: string, name: string, slug: string } | null
  model: { id: string, public_alias: string, display_name: string } | null
  created_at: string | null
  finished_at: string | null
}

interface TelegramOverview {
  storefront_bot_configured: boolean
  storefront_bot_username: string | null
  purchase_activity_enabled: boolean
  active_accounts: number
  announcement_subscribers: number
  queued_announcements: number
  sellable_package_count: number
  limited_stock_packages: number
  sold_out_packages: number
  recent_announcements: TelegramAnnouncementRow[]
  notification_settings: TelegramNotificationSettings
  alert_channels: TelegramChannel[]
}

const api = useSpApi()
const toast = useToast()

const overview = await useSpResource(
  'admin:telegram-store:r12',
  () => api.request<TelegramOverview>('/admin/telegram-store'),
  { server: false }
)

const packages = await useSpResource('admin:telegram-packages:r12', () => api.admin.packages(), { server: false })

const savingSettings = ref(false)
const savingChannel = ref(false)
const testingChannelId = ref<string | null>(null)
const deletingChannelId = ref<string | null>(null)
const sending = ref(false)
const retryingId = ref<string | null>(null)

const settings = reactive<{
  enabled: boolean
  event_routes: Record<string, DeliveryMode>
  qr_countdown_enabled: boolean
  qr_countdown_interval_seconds: number
}>({
  enabled: true,
  event_routes: {},
  qr_countdown_enabled: true,
  qr_countdown_interval_seconds: 15
})

const channelForm = reactive({
  name: '',
  chat_id: '',
  enabled: true
})

const form = reactive({
  title: '',
  body: '',
  package_id: undefined as string | undefined,
  target_mode: 'BOT_ONLY' as Exclude<DeliveryMode, 'OFF'>
})

watch(
  () => overview.data.value?.notification_settings,
  (value) => {
    if (!value) return
    settings.enabled = value.enabled
    settings.event_routes = { ...value.event_routes }
    settings.qr_countdown_enabled = value.qr_countdown_enabled
    settings.qr_countdown_interval_seconds = value.qr_countdown_interval_seconds
  },
  { immediate: true, deep: true }
)

const routeOptions = computed(() =>
  overview.data.value?.notification_settings.route_modes ?? [
    { value: 'OFF' as DeliveryMode, label: 'Off' },
    { value: 'BOT_ONLY' as DeliveryMode, label: 'Bot only' },
    { value: 'CHANNELS_ONLY' as DeliveryMode, label: 'Channels only' },
    { value: 'BOTH' as DeliveryMode, label: 'Bot + channels' }
  ]
)

const manualRouteOptions = computed(() =>
  routeOptions.value.filter(item => item.value !== 'OFF')
)

const countdownIntervals = [
  { label: '10 seconds', value: 10 },
  { label: '15 seconds · recommended', value: 15 },
  { label: '30 seconds', value: 30 },
  { label: '60 seconds', value: 60 }
]

const eventHelp: Record<string, string> = {
  package_created: 'When a new customer-visible package becomes sellable.',
  package_updated: 'Price, allowance, validity, aliases or other customer-facing package updates.',
  stock_updated: 'Finite stock additions and restocks.',
  model_created: 'A new public model alias becomes available.',
  model_updated: 'A customer-visible public model alias is changed.',
  promotion_changed: 'An enabled promotion code is created or updated.',
  purchase_activity: 'Verified paid + fulfilled Telegram Store purchase activity.'
}

const packageOptions = computed(() => [
  { label: 'No Buy button', value: undefined },
  ...(packages.data.value ?? [])
    .filter(item => item.enabled && item.customer_visible && item.auto_creates_api_key && item.stock_status !== 'OUT_OF_STOCK')
    .map(item => ({ label: item.name, value: item.id }))
])

const saveSettings = async () => {
  if (savingSettings.value) return
  savingSettings.value = true

  try {
    const response = await api.request<{ settings: TelegramNotificationSettings, message: string }>(
      '/admin/telegram-store/settings',
      {
        method: 'PUT',
        body: {
          enabled: settings.enabled,
          event_routes: { ...settings.event_routes },
          qr_countdown_enabled: settings.qr_countdown_enabled,
          qr_countdown_interval_seconds: settings.qr_countdown_interval_seconds
        }
      }
    )

    toast.add({ title: 'Telegram settings saved', description: response.message, color: 'success' })
    await overview.refresh()
  } catch (error) {
    toast.add({
      title: 'Could not save Telegram settings',
      description: error instanceof Error ? error.message : 'Please try again.',
      color: 'error'
    })
  } finally {
    savingSettings.value = false
  }
}

const addChannel = async () => {
  if (!channelForm.name.trim() || !channelForm.chat_id.trim() || savingChannel.value) return
  savingChannel.value = true

  try {
    const result = await api.request<{ channel: TelegramChannel, message: string }>(
      '/admin/telegram-store/channels',
      {
        method: 'POST',
        body: {
          name: channelForm.name.trim(),
          chat_id: channelForm.chat_id.trim(),
          enabled: channelForm.enabled
        }
      }
    )

    toast.add({ title: 'Channel added', description: result.message, color: 'success' })
    channelForm.name = ''
    channelForm.chat_id = ''
    channelForm.enabled = true
    await overview.refresh()
  } catch (error) {
    toast.add({
      title: 'Could not add channel',
      description: error instanceof Error ? error.message : 'Check the Telegram chat/channel ID.',
      color: 'error'
    })
  } finally {
    savingChannel.value = false
  }
}

const setChannelEnabled = async (channel: TelegramChannel, enabled: boolean) => {
  try {
    const result = await api.request<{ channel: TelegramChannel, message: string }>(
      `/admin/telegram-store/channels/${channel.id}`,
      {
        method: 'PUT',
        body: {
          name: channel.name,
          chat_id: channel.chat_id,
          enabled
        }
      }
    )

    toast.add({
      title: enabled ? 'Channel enabled' : 'Channel disabled',
      description: result.message,
      color: 'success'
    })
    await overview.refresh()
  } catch (error) {
    toast.add({
      title: 'Could not update channel',
      description: error instanceof Error ? error.message : 'Please try again.',
      color: 'error'
    })
  }
}

const testChannel = async (channel: TelegramChannel) => {
  testingChannelId.value = channel.id
  try {
    const result = await api.request<{ success: boolean, message: string }>(
      `/admin/telegram-store/channels/${channel.id}/test`,
      { method: 'POST' }
    )
    toast.add({ title: 'Test sent', description: result.message, color: 'success' })
  } catch (error) {
    toast.add({
      title: 'Channel test failed',
      description: error instanceof Error
        ? error.message
        : 'Add the bot to the channel/group and allow it to post messages.',
      color: 'error'
    })
  } finally {
    testingChannelId.value = null
  }
}

const deleteChannel = async (channel: TelegramChannel) => {
  if (!window.confirm(`Remove Telegram alert channel "${channel.name}"?`)) return

  deletingChannelId.value = channel.id
  try {
    const result = await api.request<{ success: boolean, message: string }>(
      `/admin/telegram-store/channels/${channel.id}`,
      { method: 'DELETE' }
    )
    toast.add({ title: 'Channel removed', description: result.message, color: 'success' })
    await overview.refresh()
  } catch (error) {
    toast.add({
      title: 'Could not remove channel',
      description: error instanceof Error ? error.message : 'Please try again.',
      color: 'error'
    })
  } finally {
    deletingChannelId.value = null
  }
}

const retryFailed = async (id: string) => {
  const reason = window.prompt(
    'Why are you retrying these failed Telegram recipients?',
    'Retry failed recipients after reviewing the delivery errors'
  )
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
    const result = await api.request<{ id: string | null, status: string, channel_count: number, message: string }>(
      '/admin/telegram-store/announcements',
      {
        method: 'POST',
        body: {
          title: form.title.trim(),
          body: form.body.trim(),
          package_id: form.package_id ?? null,
          target_mode: form.target_mode
        }
      }
    )

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
    eyebrow="Store Bot + custom alert channels"
    description="Control Store Bot subscriber announcements, multiple Telegram channels, and the live Bakong KHQR countdown from one saved configuration."
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
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <SpMetric
            label="Store Bot"
            icon="i-lucide-bot"
            :value="overview.data.value.storefront_bot_configured ? 'Configured' : 'Needs setup'"
          />
          <SpMetric
            label="Bot subscribers"
            icon="i-lucide-users"
            :value="formatCount(overview.data.value.announcement_subscribers)"
          />
          <SpMetric
            label="Alert channels"
            icon="i-lucide-radio-tower"
            :value="formatCount(overview.data.value.alert_channels.filter(item => item.enabled).length)"
          />
          <SpMetric
            label="Queued bot updates"
            icon="i-lucide-clock-3"
            :value="formatCount(overview.data.value.queued_announcements)"
          />
        </div>

        <UCard class="sp-premium-card sp-app-card">
          <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="font-semibold text-highlighted">Automatic notification routing</h2>
                <p class="mt-1 max-w-3xl text-sm text-muted">
                  Choose where each future event goes. “Bot” means opted-in SP Cambo Store Bot customers.
                  “Channels” means every enabled alert channel below.
                </p>
              </div>

              <USwitch
                v-model="settings.enabled"
                label="Automatic alerts"
              />
            </div>
          </template>

          <div class="space-y-3">
            <div
              v-for="event in overview.data.value.notification_settings.event_definitions"
              :key="event.key"
              class="sp-admin-mini-card grid gap-3 rounded-xl border border-default/50 p-4 md:grid-cols-[minmax(0,1fr)_13rem] md:items-center"
            >
              <div>
                <p class="font-medium text-highlighted">{{ event.label }}</p>
                <p class="mt-1 text-xs leading-5 text-muted">
                  {{ eventHelp[event.key] ?? 'Choose where this Telegram event is delivered.' }}
                </p>
              </div>

              <USelectMenu
                v-model="settings.event_routes[event.key]"
                :items="routeOptions"
                value-key="value"
                :disabled="!settings.enabled"
                class="w-full"
              />
            </div>
          </div>

          <div class="mt-5 grid gap-4 rounded-xl border border-primary/10 bg-primary/5 p-4 lg:grid-cols-[minmax(0,1fr)_14rem]">
            <div>
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-timer" class="size-4 text-primary" />
                <p class="font-medium text-highlighted">Live KHQR remaining time</p>
              </div>
              <p class="mt-1 text-sm leading-6 text-muted">
                Edit the existing QR photo caption instead of sending new countdown messages.
                It stops when payment is verified or the QR expires.
              </p>
              <div class="mt-3">
                <USwitch
                  v-model="settings.qr_countdown_enabled"
                  label="Enable live QR countdown"
                  :disabled="!settings.enabled"
                />
              </div>
            </div>

            <UFormField label="Update interval">
              <USelectMenu
                v-model="settings.qr_countdown_interval_seconds"
                :items="countdownIntervals"
                value-key="value"
                :disabled="!settings.enabled || !settings.qr_countdown_enabled"
                class="w-full"
              />
              <template #help>
                <span class="text-xs text-muted">15 seconds is recommended to stay smooth without Telegram rate-limit noise.</span>
              </template>
            </UFormField>
          </div>

          <template #footer>
            <div class="flex justify-end">
              <UButton
                icon="i-lucide-save"
                :loading="savingSettings"
                @click="saveSettings"
              >
                Save Telegram settings
              </UButton>
            </div>
          </template>
        </UCard>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,.72fr)_minmax(0,1.28fr)]">
          <UCard class="sp-premium-card sp-app-card">
            <template #header>
              <div>
                <h2 class="font-semibold text-highlighted">Add alert channel</h2>
                <p class="mt-1 text-sm text-muted">
                  Add as many channels/groups as you need. The Store Bot must be allowed to post there.
                </p>
              </div>
            </template>

            <div class="space-y-4">
              <UFormField label="Display name">
                <UInput v-model="channelForm.name" class="w-full" placeholder="SP Cambo Updates" />
              </UFormField>

              <UFormField label="Telegram chat / channel ID">
                <UInput
                  v-model="channelForm.chat_id"
                  class="w-full font-mono"
                  placeholder="-1001234567890 or @channelusername"
                />
                <template #help>
                  <span class="text-xs text-muted">
                    Private channels normally use a numeric -100… ID. Public channels may use @username.
                  </span>
                </template>
              </UFormField>

              <USwitch v-model="channelForm.enabled" label="Enable immediately" />

              <UButton
                block
                icon="i-lucide-plus"
                :loading="savingChannel"
                :disabled="!channelForm.name.trim() || !channelForm.chat_id.trim()"
                @click="addChannel"
              >
                Add alert channel
              </UButton>
            </div>
          </UCard>

          <UCard class="sp-premium-card sp-app-card">
            <template #header>
              <div>
                <h2 class="font-semibold text-highlighted">Saved alert channels</h2>
                <p class="mt-1 text-sm text-muted">
                  Enable/disable each destination independently. Test before turning on automatic channel alerts.
                </p>
              </div>
            </template>

            <div v-if="overview.data.value.alert_channels.length" class="space-y-2">
              <div
                v-for="channel in overview.data.value.alert_channels"
                :key="channel.id"
                class="sp-admin-mini-card flex flex-col gap-3 rounded-xl border border-default/50 p-4 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <strong class="truncate text-highlighted">{{ channel.name }}</strong>
                    <UBadge :color="channel.enabled ? 'success' : 'neutral'" variant="subtle">
                      {{ channel.enabled ? 'Enabled' : 'Disabled' }}
                    </UBadge>
                  </div>
                  <code class="mt-1 block truncate text-xs text-muted">{{ channel.chat_id }}</code>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                  <USwitch
                    :model-value="channel.enabled"
                    aria-label="Enable channel"
                    @update:model-value="setChannelEnabled(channel, $event)"
                  />
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="subtle"
                    icon="i-lucide-send"
                    :loading="testingChannelId === channel.id"
                    @click="testChannel(channel)"
                  >
                    Test
                  </UButton>
                  <UButton
                    size="xs"
                    color="error"
                    variant="subtle"
                    icon="i-lucide-trash-2"
                    :loading="deletingChannelId === channel.id"
                    @click="deleteChannel(channel)"
                  >
                    Remove
                  </UButton>
                </div>
              </div>
            </div>

            <p v-else class="py-10 text-center text-sm text-muted">
              No extra alert channels yet. Store Bot subscriber alerts still work according to the routing table.
            </p>
          </UCard>
        </div>

        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-info"
          title="Bot and channel routing are independent"
          description="Example: choose Bot only for routine package edits, Both for a new model, Channels only for internal stock monitoring, or Off when an edit should stay silent."
        />

        <div class="grid gap-5 xl:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
          <UCard class="sp-premium-card sp-app-card">
            <template #header>
              <div>
                <h2 class="font-semibold text-highlighted">Send a manual update</h2>
                <p class="mt-1 text-sm text-muted">
                  Choose Store Bot subscribers, saved channels, or both.
                </p>
              </div>
            </template>

            <div class="space-y-4">
              <UFormField label="Title">
                <UInput v-model="form.title" class="w-full" placeholder="Weekend token offer" />
              </UFormField>

              <UFormField label="Message">
                <UTextarea v-model="form.body" :rows="6" class="w-full" placeholder="Short, useful Telegram update" />
              </UFormField>

              <UFormField label="Send to">
                <USelectMenu
                  v-model="form.target_mode"
                  :items="manualRouteOptions"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>

              <UFormField label="Optional Buy Now package">
                <USelectMenu
                  v-model="form.package_id"
                  :items="packageOptions"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>

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
                <h2 class="font-semibold text-highlighted">Recent Store Bot jobs</h2>
                <p class="mt-1 text-sm text-muted">
                  Subscriber deliveries are recorded here. Channel sends run as retryable queue jobs.
                </p>
              </div>
            </template>

            <div v-if="overview.data.value.recent_announcements.length" class="divide-y divide-default/40">
              <div
                v-for="row in overview.data.value.recent_announcements"
                :key="row.id"
                class="py-4 first:pt-0 last:pb-0"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <strong class="text-highlighted">{{ row.title }}</strong>
                      <UBadge color="neutral" variant="subtle">{{ row.kind }}</UBadge>
                      <UBadge
                        :color="row.status === 'CANCELLED'
                          ? 'neutral'
                          : row.failed_count
                            ? 'warning'
                            : row.status === 'COMPLETED'
                              ? 'success'
                              : 'primary'"
                        variant="subtle"
                      >
                        {{ row.status }}
                      </UBadge>
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
                    Retry failed
                  </UButton>
                </div>
              </div>
            </div>

            <p v-else class="py-10 text-center text-sm text-muted">
              No Telegram Store update jobs yet.
            </p>
          </UCard>
        </div>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
