<script setup lang="ts">
import type { RequestActivity } from '~/types/commerce'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Usage & activity',
  description: 'What your account consumed, per model and per request. Metadata only — SP Cambo never stores prompts or completions.',
  robots: 'noindex'
})

const api = useSpApi()
const route = useRoute()

const formatSpCredits = (value: string | null | undefined) => {
  if (value == null) return '—'
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  return `$${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 5 })}`
}

const modelUi = (alias: string) => modelPresentation(alias)

const ranges = [
  { label: 'Last 24 hours', value: '24h', hours: 24, bucket: 'hour' as const },
  { label: 'Last 7 days', value: '7d', hours: 24 * 7, bucket: 'day' as const },
  { label: 'Last 30 days', value: '30d', hours: 24 * 30, bucket: 'day' as const }
]

const rangeValue = ref('7d')
const range = computed(() => ranges.find(item => item.value === rangeValue.value) ?? ranges[1]!)

const windowBounds = () => {
  const to = new Date()
  const from = new Date(to.getTime() - range.value.hours * 3600_000)
  return { from: from.toISOString(), to: to.toISOString() }
}

const summary = await useSpResource(
  'dashboard:usage-summary',
  () => api.account.usageSummary({ ...windowBounds(), bucket: range.value.bucket }),
  { server: false, watch: [rangeValue] }
)

const balance = await useSpResource(
  'dashboard:usage-balance',
  () => api.account.balance(),
  { server: false }
)

const savingsRateLabel = computed(() => `${Number(summary.data.value?.savings_rate_percent ?? 0).toFixed(1)}%`)
const hasSmartSavings = computed(() => Number(summary.data.value?.saved_tokens ?? 0) > 0)

const modelFilter = ref<string | undefined>(undefined)
const keyFilter = ref<string | undefined>(undefined)
const activityLimit = ref(25)
const liveRefresh = ref(true)
const liveClock = ref(Date.now())

let activityTimer: ReturnType<typeof setInterval> | undefined
let clockTimer: ReturnType<typeof setInterval> | undefined
let summaryTimer: ReturnType<typeof setInterval> | undefined

const keys = await useSpResource(
  'dashboard:usage-api-keys',
  () => api.account.apiKeys(),
  { server: false }
)

watch([() => route.query.key, keys.data], ([requestedId, list]) => {
  if (!list) return

  const requested = typeof requestedId === 'string'
    ? list.find(key => key.id === requestedId)
    : undefined

  if (requested) keyFilter.value = requested.id
}, { immediate: true })

const selectedKey = computed(() =>
  (keys.data.value ?? []).find(key => key.id === keyFilter.value) ?? null
)

watch(keys.data, (list) => {
  if (keyFilter.value && !list?.some(key => key.id === keyFilter.value)) {
    keyFilter.value = undefined
  }
})

const activity = await useSpResource(
  'dashboard:usage-activity',
  () => api.account.activity({
    limit: activityLimit.value,
    model: modelFilter.value,
    key_id: selectedKey.value?.id
  }),
  { server: false, watch: [modelFilter, keyFilter, activityLimit] }
)

const modelOptions = computed(() => [
  { label: 'All models', value: undefined },
  ...(summary.data.value?.by_model ?? []).map(entry => ({
    label: entry.public_model,
    value: entry.public_model as string | undefined
  }))
])

const keyOptions = computed(() => [
  { label: 'All API keys', value: undefined },
  ...(keys.data.value ?? []).map(key => ({
    label: `${key.label} · ${maskApiKey(key.prefix, key.last_four)}`,
    value: key.id
  }))
])

const limitOptions = [25, 50, 100].map(value => ({ label: `${value} requests`, value }))

const activityEmptyDescription = computed(() => selectedKey.value
  ? `Once a request runs against ${selectedKey.value.label}, it appears here within seconds.`
  : 'Once a request runs against your key it appears here within seconds.'
)

const buckets = computed(() => summary.data.value?.buckets ?? [])
const peakUnits = computed(() => peakBucketUnits(buckets.value.map(bucket => bucket.billed_tokens)))
const barHeight = (units: string) => barHeightPercent(units, peakUnits.value)

const bucketLabel = (iso: string) => {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'

  return range.value.bucket === 'hour'
    ? new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(date)
    : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(date)
}

const axisIndexes = computed(() => axisLabelIndexes(buckets.value.length))
const hasUsage = computed(() => buckets.value.some(bucket => !isUnitsDepleted(bucket.billed_tokens)))

const liveStates: RequestActivity['state'][] = ['reserved', 'connecting', 'streaming']
const liveRequests = computed(() => (activity.data.value ?? []).filter(item => liveStates.includes(item.state)))

const displayDuration = (item: RequestActivity) => {
  if (item.duration_ms !== null) return formatLatency(item.duration_ms)
  if (!liveStates.includes(item.state)) return '—'
  const elapsedMs = Math.max(0, liveClock.value - new Date(item.started_at).getTime())
  return `${(elapsedMs / 1000).toFixed(1)} s live`
}

const tokenValueTone = (label: string) =>
  label === 'Input' || label === 'Saved'
    ? 'text-success'
    : label === 'Output'
      ? 'text-error'
      : label === 'Reused input'
        ? 'text-info'
        : 'text-primary'

const refreshAll = () => {
  summary.refresh()
  balance.refresh()
  keys.refresh()
  activity.refresh()
}

onMounted(() => {
  activityTimer = setInterval(() => {
    if (liveRefresh.value) void activity.refresh()
  }, 3000)

  summaryTimer = setInterval(() => {
    if (liveRefresh.value) void summary.refresh()
  }, 12000)

  clockTimer = setInterval(() => {
    liveClock.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (activityTimer) clearInterval(activityTimer)
  if (summaryTimer) clearInterval(summaryTimer)
  if (clockTimer) clearInterval(clockTimer)
})
</script>

<template>
  <SpDashboardPage
    title="Usage & activity"
    icon="i-lucide-chart-line"
    description="See what you used, what smart reuse saved, and what was actually charged. All customer metering is calculated locally by SP Cambo."
  >
    <template #actions>
      <UBadge
        v-if="liveRequests.length"
        color="warning"
        variant="subtle"
        size="sm"
      >
        <UIcon name="i-lucide-loader-circle" class="mr-1 size-3 animate-spin" />
        {{ liveRequests.length }} running
      </UBadge>

      <USwitch
        v-model="liveRefresh"
        label="Live"
      />

      <USelectMenu
        v-model="rangeValue"
        :items="ranges"
        value-key="value"
        class="w-36 sm:w-44"
      />

      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-refresh-cw"
        size="sm"
        :loading="summary.loading.value || balance.loading.value || keys.loading.value || activity.loading.value"
        @click="refreshAll"
      >
        Refresh
      </UButton>
    </template>

    <section class="space-y-4">
      <SpAsyncSection
        :loading="summary.initialLoading.value"
        :unavailable="summary.unavailable.value"
        :failed="summary.failed.value"
        :offline="summary.error.value?.code === 'network_unreachable'"
        :error-message="summary.error.value?.message"
        unavailable-title="Usage reporting is not published yet"
        unavailable-description="The control plane has not shipped the usage summary endpoint. SP Cambo will not estimate your consumption in its place."
        loading-variant="metrics"
        :loading-count="5"
        @retry="summary.refresh()"
      >
        <div
          v-if="summary.data.value"
          class="space-y-4"
        >
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <SpMetric
              label="Available Tokens"
              icon="i-lucide-wallet-cards"
              tone="default"
              :value="balance.data.value ? formatUnits(balance.data.value.token_quota.remaining_units) : '—'"
              hint="Current spendable Token balance"
            />
            <SpMetric
              label="Saved by reuse"
              icon="i-lucide-sparkles"
              tone="success"
              :value="formatUnits(summary.data.value.saved_tokens)"
              :hint="`${formatSpCredits(summary.data.value.credits_saved)} Credits equivalent saved`"
            />
            <SpMetric
              label="Charged Tokens"
              icon="i-lucide-gauge"
              tone="info"
              :value="formatUnits(summary.data.value.billed_tokens)"
              hint="Actual settled charge"
            />
            <SpMetric
              label="Average savings"
              icon="i-lucide-percent"
              tone="success"
              :value="savingsRateLabel"
              hint="Smart-reuse discount"
            />
            <SpMetric
              label="Reused context"
              icon="i-lucide-refresh-cw"
              tone="primary"
              :value="formatUnits(summary.data.value.cached_input_tokens)"
              hint="Repeated prompt prefix"
            />
            <SpMetric
              label="Requests"
              icon="i-lucide-activity"
              tone="primary"
              :value="formatCount(summary.data.value.requests)"
              :hint="range.label.toLowerCase()"
            />
          </div>

          <UAlert
            icon="i-lucide-sparkles"
            :color="hasSmartSavings ? 'success' : 'info'"
            variant="subtle"
            :title="hasSmartSavings ? 'Smart reuse is saving your balance' : 'Smart reuse is active'"
            :description="hasSmartSavings
              ? `${formatUnits(summary.data.value.cached_input_tokens)} of repeated context was recognized in this period, saving ${formatUnits(summary.data.value.saved_tokens)} Tokens (${savingsRateLabel}).`
              : 'When a recent request repeats a large prompt prefix, SP Cambo bills that reused context at 25% of the normal Token rate.'"
          />

          <div class="sp-r9-usage-chart rounded-xl p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <div>
                <h2 class="text-sm font-medium text-highlighted">
                  Charged Tokens over time
                </h2>
                <p class="mt-1 text-[11px] text-muted">
                  Settled customer charge only.
                </p>
              </div>

              <p class="text-[10px] text-dimmed">
                {{ formatDateTime(summary.data.value.range.from) }} →
                {{ formatDateTime(summary.data.value.range.to) }}
              </p>
            </div>

            <p
              v-if="buckets.length === 0 || !hasUsage"
              class="py-10 text-center text-sm text-muted"
            >
              No charged activity in this range.
            </p>

            <template v-else>
              <ul class="mt-5 flex h-36 items-end gap-1">
                <li
                  v-for="bucket in buckets"
                  :key="bucket.at"
                  class="group relative flex h-full min-w-0 flex-1 items-end"
                >
                  <div
                    class="sp-r9-usage-bar w-full rounded-t"
                    :style="{ height: `${barHeight(bucket.billed_tokens)}%` }"
                  />
                  <span class="sr-only">
                    {{ bucketLabel(bucket.at) }}: {{ formatUnits(bucket.billed_tokens) }}
                  </span>

                  <div
                    class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden -translate-x-1/2 rounded-lg border border-white/5 bg-default/95 px-2.5 py-1.5 text-xs whitespace-nowrap shadow-xl group-hover:block"
                  >
                    <p class="font-medium text-highlighted">{{ bucketLabel(bucket.at) }}</p>
                    <p class="sp-numeric text-muted">
                      {{ formatUnits(bucket.billed_tokens) }} charged ·
                      {{ formatUnits(bucket.saved_tokens) }} saved ·
                      {{ formatCount(bucket.requests) }} req
                    </p>
                  </div>
                </li>
              </ul>

              <div class="mt-2 flex justify-between text-[10px] text-dimmed">
                <span
                  v-for="index in [...axisIndexes].sort((a, b) => a - b)"
                  :key="index"
                >
                  {{ bucketLabel(buckets[index]?.at ?? '') }}
                </span>
              </div>
            </template>
          </div>

          <div v-if="summary.data.value.by_model.length > 0">
            <SpSectionHeading
              title="By model"
              description="Usage grouped by stable public model alias."
              :level="3"
            />

            <ul class="sp-r9-usage-list mt-3 overflow-hidden rounded-xl">
              <li
                v-for="entry in summary.data.value.by_model"
                :key="entry.public_model"
                class="sp-r9-usage-row flex flex-wrap items-center justify-between gap-3 px-3.5 py-3"
              >
                <div class="flex min-w-0 items-center gap-2.5">
                  <SpPublicAliasIcon
                    :alias="entry.public_model"
                    :label="modelUi(entry.public_model).label"
                    size="md"
                  />

                  <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-highlighted">
                      {{ modelUi(entry.public_model).label }}
                    </p>
                    <p class="truncate font-mono text-[10px] text-dimmed">
                      {{ entry.public_model }} · {{ formatCount(entry.requests) }} requests
                    </p>
                  </div>
                </div>

                <dl class="grid grid-cols-4 gap-4 text-[10px] sm:flex sm:items-center sm:gap-5">
                  <div class="text-right">
                    <dt class="text-dimmed">Charged</dt>
                    <dd class="sp-numeric font-semibold text-info">{{ formatUnits(entry.billed_tokens) }}</dd>
                  </div>
                  <div class="text-right">
                    <dt class="text-dimmed">Saved</dt>
                    <dd class="sp-numeric font-semibold text-success">{{ formatUnits(entry.saved_tokens) }}</dd>
                  </div>
                  <div class="text-right">
                    <dt class="text-dimmed">Credits</dt>
                    <dd class="sp-numeric font-semibold text-primary">{{ formatSpCredits(entry.sp_credits_used) }}</dd>
                  </div>
                  <div class="text-right">
                    <dt class="text-dimmed">Wallet</dt>
                    <dd class="sp-numeric font-semibold text-warning">{{ formatMoney(entry.credit_charge) }}</dd>
                  </div>
                </dl>
              </li>
            </ul>
          </div>
        </div>
      </SpAsyncSection>
    </section>

    <section class="space-y-3">
      <SpSectionHeading
        title="Request log"
        :description="selectedKey
          ? `Individual requests made with ${selectedKey.label}.`
          : 'Per-request usage, smart-reuse savings and final charge. Prompt and output content are never shown here.'"
      >
        <template #actions>
          <div class="flex min-w-0 flex-wrap items-center gap-2">
            <USelectMenu
              v-model="keyFilter"
              :items="keyOptions"
              value-key="value"
              :loading="keys.loading.value"
              :disabled="keys.initialLoading.value || keys.unavailable.value || keys.failed.value"
              placeholder="All API keys"
              size="sm"
              class="w-full sm:w-48"
            />

            <USelectMenu
              v-model="modelFilter"
              :items="modelOptions"
              value-key="value"
              placeholder="All models"
              size="sm"
              class="w-full sm:w-44"
            />

            <USelectMenu
              v-model="activityLimit"
              :items="limitOptions"
              value-key="value"
              size="sm"
              class="w-full sm:w-32"
            />
          </div>
        </template>
      </SpSectionHeading>

      <UAlert
        v-if="keys.unavailable.value || keys.failed.value"
        icon="i-lucide-key-round"
        color="warning"
        variant="subtle"
        :title="keys.unavailable.value ? 'API-key filtering is not available' : 'API keys could not be loaded'"
        :description="keys.unavailable.value
          ? 'The request log remains unfiltered because SP Cambo cannot safely validate a key selection.'
          : `${keys.error.value?.message ?? 'SP Cambo could not load your key list.'} The request log remains unfiltered.`"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="subtle"
            size="sm"
            :loading="keys.loading.value"
            @click="keys.refresh()"
          >
            Retry keys
          </UButton>
        </template>
      </UAlert>

      <SpAsyncSection
        :loading="activity.initialLoading.value"
        :unavailable="activity.unavailable.value"
        :failed="activity.failed.value"
        :empty="activity.isEmpty.value"
        :offline="activity.error.value?.code === 'network_unreachable'"
        :error-message="activity.error.value?.message"
        unavailable-title="The request log is not published yet"
        unavailable-description="SP Cambo reads per-request metadata from the control plane."
        empty-title="No requests in this view"
        :empty-description="activityEmptyDescription"
        empty-icon="i-lucide-activity"
        loading-variant="rows"
        @retry="activity.refresh()"
      >
        <ul
          v-if="activity.data.value"
          class="sp-r9-usage-list overflow-hidden rounded-xl"
        >
          <li
            v-for="item in activity.data.value"
            :key="item.id"
            class="sp-r9-usage-row flex flex-col gap-3 px-3.5 py-3 lg:flex-row lg:items-center lg:justify-between"
          >
            <div class="flex min-w-0 items-start gap-2.5">
              <SpPublicAliasIcon
                :alias="item.public_model"
                :label="modelUi(item.public_model).label"
                size="sm"
              />

              <div class="min-w-0 space-y-1">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                  <p class="truncate text-sm font-semibold text-highlighted">
                    {{ modelUi(item.public_model).label }}
                  </p>

                  <span class="font-mono text-[9px] text-dimmed">
                    {{ item.public_model }}
                  </span>

                  <SpStatusBadge :status="item.state" />

                  <UBadge
                    v-if="item.estimated"
                    color="warning"
                    variant="subtle"
                    size="xs"
                  >
                    Estimated
                  </UBadge>

                  <UBadge
                    v-if="item.error_code"
                    :color="item.state === 'reconciling' ? 'warning' : 'error'"
                    variant="subtle"
                    size="xs"
                    class="font-mono"
                  >
                    {{ item.error_code }}
                  </UBadge>
                </div>

                <p class="truncate text-[10px] text-muted">
                  {{ item.endpoint }} · {{ item.api_key_label }}
                  <span class="font-mono text-dimmed">{{ item.api_key_prefix }}…</span>
                  · {{ formatDateTime(item.started_at) }}
                </p>
              </div>
            </div>

            <div class="grid min-w-0 shrink-0 gap-x-5 gap-y-3 sm:grid-cols-[minmax(11rem,1fr)_auto]">
              <dl class="grid grid-cols-3 gap-x-4 gap-y-2 text-[10px]">
                <div
                  v-for="row in activityTokenRows(item)"
                  :key="row.label"
                >
                  <dt class="font-medium text-dimmed">{{ row.label }}</dt>
                  <dd class="sp-numeric font-semibold" :class="tokenValueTone(row.label)">
                    {{ formatCompactUnits(row.value) }}
                  </dd>
                </div>
              </dl>

              <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-[10px]">
                <div>
                  <dt class="text-dimmed">Charged</dt>
                  <dd class="sp-numeric font-semibold text-info">
                    {{ item.billed_tokens !== null ? formatUnits(item.billed_tokens) : item.reserved_units !== null ? `${formatUnits(item.reserved_units)} reserved` : '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-dimmed">Credits</dt>
                  <dd class="sp-numeric font-semibold text-primary">{{ formatSpCredits(item.sp_credits_used) }}</dd>
                </div>
                <div>
                  <dt class="text-dimmed">Wallet</dt>
                  <dd class="sp-numeric font-semibold text-warning">
                    {{ item.credit_charge ? formatMoney(item.credit_charge) : '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-dimmed">Duration</dt>
                  <dd class="sp-numeric font-semibold text-warning">{{ displayDuration(item) }}</dd>
                </div>
              </dl>
            </div>
          </li>
        </ul>
      </SpAsyncSection>

      <p class="text-[10px] leading-5 text-muted">
        Input/output and reuse figures come from SP Cambo’s local meter. Charged Tokens,
        Credits and wallet charges are the authoritative customer figures.
      </p>
    </section>

    <div class="grid gap-3 sm:grid-cols-2">
      <div class="sp-r9-usage-panel rounded-xl p-4 text-xs text-muted">
        <p class="font-medium text-default">Why a figure can change</p>
        <p class="mt-1 leading-5">
          A request reserves budget before it runs, then settles on what it actually used.
          Anything marked Estimated is still interim.
        </p>
      </div>

      <div class="sp-r9-usage-panel rounded-xl p-4 text-xs text-muted">
        <p class="font-medium text-default">What is recorded</p>
        <p class="mt-1 leading-5">
          Model alias, key, endpoint, timing, local usage, smart-reuse savings, outcome and charge.
          Never your prompts, completions, tool arguments or file contents.
        </p>
        <NuxtLink
          to="/legal/privacy"
          class="mt-2 inline-flex items-center gap-1 text-primary underline decoration-dotted underline-offset-4"
        >
          What SP Cambo stores
        </NuxtLink>
      </div>
    </div>
  </SpDashboardPage>
</template>

<style scoped>
.sp-r9-usage-bar {
  min-height: 3px;
  background: linear-gradient(to top, rgb(70 117 255 / .42), rgb(74 135 255 / .78));
  box-shadow: 0 0 12px -5px rgb(72 128 255 / .55);
  transition: background .18s ease, box-shadow .18s ease;
}

.group:hover .sp-r9-usage-bar {
  background: linear-gradient(to top, rgb(70 117 255 / .55), rgb(83 151 255 / .96));
  box-shadow: 0 0 18px -4px rgb(72 128 255 / .7);
}
</style>
