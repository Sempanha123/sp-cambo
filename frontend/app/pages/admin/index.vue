<script setup lang="ts">
/**
 * Operator overview: platform counters and measured component health.
 *
 * Both endpoints require the `admin.view` permission. Access is decided by the
 * control plane, never here — `useSpPermissions` only controls whether a *link*
 * is shown, so this page is deliberately reachable by URL and renders the
 * server's 403 honestly rather than pretending the route does not exist.
 *
 * Every number on this page comes from the response as-is. Nothing is summed,
 * scaled or projected in the browser.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Platform overview',
  description: 'SP Cambo operator overview: platform counters and measured component health.',
  robots: 'noindex, nofollow'
})

const api = useSpApi()

const overview = await useSpResource('admin:overview', () => api.admin.overview(), { server: false })
const health = await useSpResource('admin:system-health', () => api.admin.systemHealth(), { server: false })

/**
 * A single 403 panel rather than one per section: the account either holds
 * `admin.view` or it does not, and repeating the same refusal twice reads as two
 * separate failures.
 */
const accessDenied = computed(() => overview.forbidden.value || health.forbidden.value)
const accessCode = computed(() =>
  (overview.forbidden.value ? overview.error.value?.code : health.error.value?.code) ?? null
)

const refreshAll = () => Promise.all([overview.refresh(), health.refresh()])
const refreshing = computed(() => overview.loading.value || health.loading.value)

const revenue = computed(() => overview.data.value?.fulfilled_revenue ?? null)

/**
 * Fulfilled revenue, stated only as precisely as the contract allows.
 *
 * Three distinct cases, none of which may be papered over:
 *   mixed currency        -> a single figure would be a sum of unlike units, so none is shown
 *   no `exponent`         -> the decimal point cannot be placed, so exact minor units are shown
 *   `exponent` published  -> a real formatted amount
 */
const revenueDisplay = computed(() => {
  const value = revenue.value

  if (!value) {
    return { text: '—', hint: undefined as string | undefined, tone: 'default' as const }
  }

  if (value.mixed_currency) {
    return {
      text: 'Not shown',
      hint: 'Fulfilled in more than one currency',
      tone: 'warning' as const
    }
  }

  if (!value.currency) {
    return {
      text: formatMinorUnits(value.minor),
      hint: 'No fulfilled order has settled yet',
      tone: 'default' as const
    }
  }

  if (value.exponent !== null) {
    return {
      text: formatMoney({ minor: value.minor, currency: value.currency, exponent: value.exponent }),
      hint: 'Fulfilled orders only',
      tone: 'default' as const
    }
  }

  return {
    text: formatMinorUnits(value.minor, value.currency),
    hint: 'Exact minor units',
    tone: 'default' as const
  }
})

/** True while the control plane publishes an amount whose currency scale is unknown. */
const revenueScaleUnknown = computed(() => {
  const value = revenue.value

  return value !== null && !value.mixed_currency && value.currency !== null && value.exponent === null
})

/**
 * Exact fulfilled revenue per currency, in the order the control plane grouped it.
 *
 * Shown only when there is more than one, because that is the case where no single
 * total may be stated. Refusing to add unlike currencies is correct; refusing to
 * show either figure is not — SP Cambo settles in USD and KHR, so a deployment
 * taking both would otherwise read as having no revenue at all.
 */
const revenueByCurrency = computed(() => {
  const value = revenue.value

  return value && value.by_currency.length > 1 ? value.by_currency : []
})

/** Status counts, busiest first. An absent or empty map yields no rows. */
const statusRows = (byStatus: Record<string, number> | undefined) =>
  Object.entries(byStatus ?? {}).sort(([, a], [, b]) => b - a)

const orderStatuses = computed(() => statusRows(overview.data.value?.orders.by_status))
const paymentStatuses = computed(() => statusRows(overview.data.value?.payments.by_status))

const components = computed(() => health.data.value?.components ?? [])

/**
 * Whether the overall reading is driven purely by components the control plane
 * does not measure yet, rather than by anything actually reporting a fault.
 *
 * Derived from the response, so it stops appearing the moment real probes ship.
 */
const unmeasuredComponents = computed(() => components.value.filter(item => item.status === 'maintenance'))
const faultingComponents = computed(() =>
  components.value.filter(item => item.status === 'degraded' || item.status === 'outage')
)
const degradedByUnmeasuredOnly = computed(() =>
  health.data.value !== null
  && health.data.value.overall !== 'operational'
  && unmeasuredComponents.value.length > 0
  && faultingComponents.value.length === 0
)

/** The backend measures lag from the oldest queued job; zero is reported as zero. */
const lagLabel = (seconds: number) => (seconds <= 0 ? '0 seconds' : formatDurationSeconds(seconds))
</script>

<template>
  <SpDashboardPage
    title="Platform overview"
    icon="i-lucide-shield"
    description="Operator view of SP Cambo. Counters and component health are read directly from the control plane at the moment you load or refresh this page."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        :loading="refreshing"
        @click="refreshAll()"
      >
        Refresh
      </UButton>
    </template>

    <SpStateForbidden
      v-if="accessDenied"
      :code="accessCode"
      permission="admin.view"
    />

    <template v-else>
      <section class="space-y-4">
        <SpSectionHeading
          title="Platform counters"
          :description="overview.data.value
            ? `Snapshot taken ${formatDateTime(overview.data.value.updated_at)}.`
            : 'Accounts, orders, payments and outstanding entitlement state.'"
        />

        <SpAsyncSection
          :loading="overview.initialLoading.value"
          :unavailable="overview.unavailable.value"
          :failed="overview.failed.value"
          :offline="overview.error.value?.code === 'network_unreachable'"
          :error-message="overview.error.value?.message"
          error-title="The platform overview could not be loaded"
          loading-variant="metrics"
          :loading-count="8"
          @retry="overview.refresh()"
        >
          <div
            v-if="overview.data.value"
            class="space-y-4"
          >
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <SpMetric
                label="Accounts"
                icon="i-lucide-users"
                :value="formatCount(overview.data.value.users.total)"
                :hint="`${formatCount(overview.data.value.users.active)} active`"
              />
              <SpMetric
                label="Orders"
                icon="i-lucide-receipt"
                :value="formatCount(overview.data.value.orders.total)"
                hint="All statuses"
              />
              <SpMetric
                label="Payment attempts"
                icon="i-lucide-qr-code"
                :value="formatCount(overview.data.value.payments.total)"
                hint="All statuses"
              />
              <SpMetric
                label="Fulfilled revenue"
                icon="i-lucide-banknote"
                :value="revenueDisplay.text"
                :hint="revenueDisplay.hint"
                :tone="revenueDisplay.tone"
              />
              <SpMetric
                label="Active entitlement lots"
                icon="i-lucide-layers"
                :value="formatCount(overview.data.value.entitlements.active_lots)"
                hint="Unexpired and spendable"
              />
              <SpMetric
                label="Active reservations"
                icon="i-lucide-lock"
                :value="formatCount(overview.data.value.reservations.active)"
                hint="Held for requests in flight"
              />
              <SpMetric
                label="Ledger entries"
                icon="i-lucide-book-open"
                :value="formatCount(overview.data.value.ledger_entries)"
                hint="Credit ledger, all time"
              />
            </div>

            <UAlert
              v-if="revenue?.mixed_currency"
              color="warning"
              variant="subtle"
              icon="i-lucide-triangle-alert"
              title="Revenue spans more than one currency"
              description="Orders have been fulfilled in several currencies, so no single total is shown. Adding minor units across currencies would produce a figure that does not represent any amount of money. Each currency is totalled exactly below."
            />

            <UAlert
              v-else-if="revenueScaleUnknown"
              color="neutral"
              variant="subtle"
              icon="i-lucide-info"
              title="Revenue is shown in exact minor units"
              description="The control plane does not publish this currency's minor-unit scale on the overview endpoint, so the decimal point cannot be placed. The integer above is exact and unrounded."
            />

            <div
              v-if="revenueByCurrency.length > 0"
              class="overflow-hidden rounded-lg border border-default"
            >
              <p class="border-b border-default bg-elevated/60 px-4 py-2.5 text-xs font-medium tracking-wide text-muted uppercase">
                Fulfilled revenue by currency
              </p>
              <dl class="divide-y divide-default">
                <div
                  v-for="amount in revenueByCurrency"
                  :key="`${amount.currency}-${amount.exponent}`"
                  class="flex items-baseline justify-between gap-4 px-4 py-2.5 text-sm"
                >
                  <dt class="font-mono text-xs text-muted">
                    {{ amount.currency }}
                  </dt>
                  <dd class="sp-numeric font-medium text-highlighted">
                    {{ formatMoney(amount) }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>
        </SpAsyncSection>
      </section>

      <section
        v-if="orderStatuses.length > 0 || paymentStatuses.length > 0"
        class="space-y-4"
      >
        <SpSectionHeading
          title="Status breakdown"
          description="Counts exactly as the control plane groups them. A status you have not seen before is shown under its own name rather than folded into a familiar one."
          :level="3"
        />

        <div class="grid gap-4 lg:grid-cols-2">
          <div
            v-for="group in [
              { key: 'orders', title: 'Orders', rows: orderStatuses },
              { key: 'payments', title: 'Payment attempts', rows: paymentStatuses }
            ]"
            :key="group.key"
            class="overflow-hidden rounded-lg border border-default"
          >
            <p class="border-b border-default bg-elevated/60 px-4 py-2.5 text-xs font-medium tracking-wide text-muted uppercase">
              {{ group.title }}
            </p>

            <p
              v-if="group.rows.length === 0"
              class="px-4 py-4 text-sm text-muted"
            >
              None recorded yet.
            </p>

            <ul
              v-else
              class="divide-y divide-default"
            >
              <li
                v-for="[status, count] in group.rows"
                :key="status"
                class="flex items-center justify-between gap-3 bg-elevated/20 px-4 py-2.5"
              >
                <SpStatusBadge :status="status" />
                <span class="sp-numeric text-sm text-highlighted">{{ formatCount(count) }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="space-y-4">
        <SpSectionHeading
          title="System health"
          :description="health.data.value
            ? `Measured ${formatDateTime(health.data.value.updated_at)}.`
            : 'Live component checks reported by the control plane.'"
        >
          <template
            v-if="health.data.value"
            #actions
          >
            <SpStatusBadge
              :status="health.data.value.overall"
              size="md"
            />
          </template>
        </SpSectionHeading>

        <SpAsyncSection
          :loading="health.initialLoading.value"
          :unavailable="health.unavailable.value"
          :failed="health.failed.value"
          :offline="health.error.value?.code === 'network_unreachable'"
          :error-message="health.error.value?.message"
          error-title="System health could not be read"
          loading-variant="rows"
          :loading-count="7"
          @retry="health.refresh()"
        >
          <div
            v-if="health.data.value"
            class="space-y-4"
          >
            <UAlert
              v-if="degradedByUnmeasuredOnly"
              color="info"
              variant="subtle"
              icon="i-lucide-wrench"
              :title="`Overall reads ${health.data.value.overall} because ${unmeasuredComponents.length} component${unmeasuredComponents.length === 1 ? '' : 's'} are not measured yet`"
              description="No component is reporting a fault. Components marked Maintenance have no probe in the control plane yet, so their real state is unknown rather than healthy."
            />

            <ul class="divide-y divide-default overflow-hidden rounded-lg border border-default">
              <li
                v-for="component in components"
                :key="component.key"
                class="flex flex-col gap-2 bg-elevated/30 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div class="min-w-0 space-y-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-medium text-highlighted">
                      {{ component.label }}
                    </p>
                    <SpStatusBadge :status="component.status" />
                  </div>
                  <p
                    v-if="component.detail"
                    class="text-xs text-muted"
                  >
                    {{ component.detail }}
                  </p>
                  <code class="block font-mono text-xs text-dimmed">{{ component.key }}</code>
                </div>

                <dl
                  v-if="component.lag_seconds !== undefined || component.last_heartbeat_at !== undefined"
                  class="flex shrink-0 flex-wrap items-center gap-5 text-xs"
                >
                  <div v-if="component.lag_seconds !== undefined">
                    <dt class="text-dimmed">
                      Oldest queued job
                    </dt>
                    <dd class="sp-numeric text-default">
                      {{ lagLabel(component.lag_seconds) }}
                    </dd>
                  </div>
                  <div v-if="component.last_heartbeat_at !== undefined">
                    <dt class="text-dimmed">
                      Last heartbeat
                    </dt>
                    <dd class="text-default">
                      {{ component.last_heartbeat_at ? formatDateTime(component.last_heartbeat_at) : 'Never recorded' }}
                    </dd>
                  </div>
                </dl>
              </li>
            </ul>
          </div>
        </SpAsyncSection>
      </section>
    </template>
  </SpDashboardPage>
</template>
