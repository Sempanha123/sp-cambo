<script setup lang="ts">
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Overview',
  description: 'Your SP Cambo balance, recent activity and next steps.',
  robots: 'noindex'
})

const auth = useAuthStore()
const api = useSpApi()

const balance = await useSpResource('dashboard:balance', () => api.account.balance(), { server: false })
const activity = await useSpResource('dashboard:activity', () => api.account.activity({ limit: 5 }), { server: false })

const tokenPercent = computed(() => {
  const quota = balance.data.value?.token_quota
  return quota ? percentOfUnits(quota.remaining_units, quota.original_units) : null
})

const expiryHint = computed(() => {
  const next = balance.data.value?.next_expires_at
  return next ? `Next expiry ${formatDateTime(next)}` : undefined
})

const formatSpCreditBalance = (value: string | null | undefined) => {
  if (value == null) return '$0'
  const amount = Number(value)
  return Number.isFinite(amount)
    ? `$${amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 5 })}`
    : value
}

const quickStart = [
  {
    title: 'Buy packages',
    description: 'Choose prepaid access and activate it with Bakong KHQR.',
    icon: 'i-lucide-package',
    to: '/dashboard/buy',
    label: 'Explore packages'
  },
  {
    title: 'API keys',
    description: 'Create, rotate and scope credentials for each app or device.',
    icon: 'i-lucide-key-round',
    to: '/dashboard/api-keys',
    label: 'Manage keys'
  },
  {
    title: 'Playground',
    description: 'Test available model aliases before wiring them into an app.',
    icon: 'i-lucide-flask-conical',
    to: '/dashboard/playground',
    label: 'Open playground'
  },
  {
    title: 'CLI setup',
    description: 'Copy the exact base URL and environment variables for your tools.',
    icon: 'i-lucide-terminal',
    to: '/dashboard/cli-setup',
    label: 'Configure CLI'
  }
]
</script>

<template>
  <SpDashboardPage
    title="Overview"
    icon="i-lucide-layout-dashboard"
  >
    <template #actions>
      <UButton
        to="/dashboard/buy"
        icon="i-lucide-plus"
        size="sm"
      >
        Buy package
      </UButton>
    </template>

    <section class="sp-r9-overview-hero">
      <div class="sp-r9-overview-hero__copy">
        <div class="flex flex-wrap items-center gap-2">
          <UBadge
            color="neutral"
            variant="subtle"
            class="rounded-full"
          >
            <span class="flex items-center gap-2">
              <span class="relative flex size-2">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-50" />
                <span class="relative inline-flex size-2 rounded-full bg-success" />
              </span>
              AI WORKSPACE READY
            </span>
          </UBadge>
          <span class="sp-r9-overview-kicker">Prepaid · metered · developer-first</span>
        </div>

        <div class="space-y-3">
          <h1 class="max-w-2xl text-3xl font-semibold tracking-tight text-highlighted text-balance sm:text-4xl lg:text-5xl">
            Your AI access,
            <span class="sp-r9-overview-gradient">one workspace.</span>
          </h1>
          <p class="max-w-xl text-sm leading-6 text-muted sm:text-base">
            Packages, API keys, usage and Playground in one calm dashboard.
            Your public model aliases stay stable while routing remains private.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <UButton
            to="/dashboard/buy"
            trailing-icon="i-lucide-arrow-right"
          >
            Explore packages
          </UButton>
          <UButton
            to="/dashboard/playground"
            color="neutral"
            variant="subtle"
            icon="i-lucide-play"
          >
            Open Playground
          </UButton>
          <UButton
            to="/docs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-book-open"
          >
            Docs
          </UButton>
        </div>
      </div>

      <div class="sp-r9-overview-orbit hidden lg:block" aria-hidden="true">
        <div class="sp-r9-overview-orbit__halo" />
        <div class="sp-r9-overview-orbit__ring sp-r9-overview-orbit__ring--a" />
        <div class="sp-r9-overview-orbit__ring sp-r9-overview-orbit__ring--b" />
        <div class="sp-r9-overview-orbit__core">
          <SpBrandMark :size="48" />
        </div>
        <span class="sp-r9-overview-node sp-r9-overview-node--key"><UIcon name="i-lucide-key-round" class="size-4" /></span>
        <span class="sp-r9-overview-node sp-r9-overview-node--code"><UIcon name="i-lucide-code-xml" class="size-4" /></span>
        <span class="sp-r9-overview-node sp-r9-overview-node--chart"><UIcon name="i-lucide-chart-no-axes-combined" class="size-4" /></span>
      </div>
    </section>

    <section class="space-y-3">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <SpSectionHeading
          title="Account snapshot"
          description="Current spendable entitlement and usage state."
        />
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="balance.loading.value"
          @click="balance.refresh()"
        >
          Refresh
        </UButton>
      </div>

      <SpAsyncSection
        :loading="balance.initialLoading.value"
        :unavailable="balance.unavailable.value"
        :failed="balance.failed.value"
        :offline="balance.error.value?.code === 'network_unreachable'"
        :error-message="balance.error.value?.message"
        loading-variant="metrics"
        :loading-count="4"
        @retry="balance.refresh()"
      >
        <div
          v-if="balance.data.value"
          class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          <SpMetric
            label="Total Tokens"
            icon="i-lucide-layers-3"
            :value="formatUnits(balance.data.value.token_quota.remaining_units)"
            :hint="tokenPercent === null ? undefined : `${tokenPercent}% of purchased quota`"
            :tone="isUnitsDepleted(balance.data.value.token_quota.remaining_units) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Credits"
            icon="i-lucide-wallet-cards"
            :value="formatSpCreditBalance(balance.data.value.sp_credit_quota?.remaining)"
            :hint="balance.data.value.sp_credit_quota
              ? `$1 Credit = ${formatUnits(balance.data.value.sp_credit_quota.billable_units_per_credit)} Tokens`
              : 'Dollar-denominated platform Credits'"
            :tone="Number(balance.data.value.sp_credit_quota?.remaining ?? 0) <= 0 ? 'warning' : 'default'"
          />
          <SpMetric
            label="Wallet credit"
            icon="i-lucide-circle-dollar-sign"
            :value="formatMoney(balance.data.value.credit_balance.remaining)"
            hint="Account money credit, separate from package Credits"
            :tone="isZeroMoney(balance.data.value.credit_balance.remaining) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Active lots"
            icon="i-lucide-boxes"
            :value="formatCount(balance.data.value.active_lot_count)"
            :hint="expiryHint"
          />
        </div>
      </SpAsyncSection>
    </section>

    <div class="grid gap-4 xl:grid-cols-[1.45fr_0.85fr]">
      <section class="sp-dashboard-section sp-r9-activity-panel space-y-3 rounded-xl p-4">
        <SpSectionHeading
          title="Recent activity"
          description="Request metadata only. Prompts and completions are not stored here."
        >
          <template #actions>
            <UButton
              to="/dashboard/usage"
              color="neutral"
              variant="ghost"
              size="sm"
              trailing-icon="i-lucide-arrow-right"
            >
              View all
            </UButton>
          </template>
        </SpSectionHeading>

        <SpAsyncSection
          :loading="activity.initialLoading.value"
          :unavailable="activity.unavailable.value"
          :failed="activity.failed.value"
          :empty="activity.isEmpty.value"
          :offline="activity.error.value?.code === 'network_unreachable'"
          :error-message="activity.error.value?.message"
          empty-title="No requests yet"
          empty-description="Your successful API requests will appear here."
          empty-icon="i-lucide-activity"
          loading-variant="rows"
          @retry="activity.refresh()"
        >
          <ul
            v-if="activity.data.value"
            class="sp-r9-overview-activity"
          >
            <li
              v-for="item in activity.data.value"
              :key="item.id"
              class="sp-r9-overview-activity__row"
            >
              <div class="flex min-w-0 items-center gap-2.5">
                <SpPublicAliasIcon
                  :alias="item.public_model"
                  :label="item.public_model"
                  size="sm"
                />
                <div class="min-w-0">
                  <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-medium text-highlighted">
                      {{ modelPresentation(item.public_model).label }}
                    </p>
                    <SpStatusBadge :status="item.state" />
                  </div>
                  <p class="truncate text-[11px] text-muted">
                    {{ item.public_model }} · {{ item.endpoint }} · {{ formatDateTime(item.started_at) }}
                  </p>
                </div>
              </div>

              <dl class="flex shrink-0 items-center gap-4 text-[11px]">
                <div>
                  <dt class="text-dimmed">Units</dt>
                  <dd class="sp-numeric mt-0.5 text-default">{{ formatUnits(item.metered_units) }}</dd>
                </div>
                <div>
                  <dt class="text-dimmed">Latency</dt>
                  <dd class="sp-numeric mt-0.5 text-default">{{ formatLatency(item.duration_ms) }}</dd>
                </div>
              </dl>
            </li>
          </ul>
        </SpAsyncSection>
      </section>

      <section class="sp-dashboard-section sp-r9-playground-preview space-y-3 rounded-xl p-4">
        <SpSectionHeading
          title="Playground"
          description="Run a model from the browser."
        >
          <template #actions>
            <UButton
              to="/dashboard/playground"
              color="neutral"
              variant="ghost"
              size="sm"
              trailing-icon="i-lucide-external-link"
            >
              Open
            </UButton>
          </template>
        </SpSectionHeading>

        <div class="sp-r9-playground-preview__window">
          <div class="sp-r9-playground-preview__bar">
            <span class="relative flex size-1.5">
              <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-45" />
              <span class="relative inline-flex size-1.5 rounded-full bg-success" />
            </span>
            gateway ready
          </div>

          <div class="space-y-3 p-3">
            <div class="sp-r9-playground-preview__bubble sp-r9-playground-preview__bubble--user">
              Explain this code in simple terms.
            </div>
            <div class="sp-r9-playground-preview__bubble">
              A routed model response will stream here...
            </div>
          </div>
        </div>
      </section>
    </div>

    <section class="space-y-3">
      <SpSectionHeading
        title="Quick actions"
        :description="`Signed in as ${auth.user?.email ?? 'your account'}.`"
      />

      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <UCard
          v-for="item in quickStart"
          :key="item.to"
          :ui="{ root: 'sp-premium-card sp-r9-command-card h-full rounded-xl', body: 'flex h-full flex-col gap-3' }"
          class="sp-app-card"
        >
          <div class="sp-r9-command-card__icon">
            <UIcon :name="item.icon" class="size-4.5" />
          </div>

          <div class="space-y-1">
            <h3 class="text-sm font-medium text-highlighted">{{ item.title }}</h3>
            <p class="text-xs leading-5 text-muted">{{ item.description }}</p>
          </div>

          <UButton
            :to="item.to"
            color="neutral"
            variant="subtle"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
            class="mt-auto self-start"
          >
            {{ item.label }}
          </UButton>
        </UCard>
      </div>
    </section>
  </SpDashboardPage>
</template>

<style scoped>
.sp-r9-overview-hero {
  position: relative;
  min-height: 21rem;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / .04);
  border-radius: 1.35rem;
  background:
    radial-gradient(circle at 82% 50%, rgb(67 105 255 / .09), transparent 22rem),
    linear-gradient(145deg, rgb(255 255 255 / .012), transparent 46%),
    color-mix(in oklab, var(--ui-bg-elevated) 33%, transparent);
  padding: 1.35rem;
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .018);
  backdrop-filter: blur(14px);
}

.sp-r9-overview-hero__copy {
  position: relative;
  z-index: 2;
  max-width: 46rem;
}

.sp-r9-overview-kicker {
  font-size: .64rem;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--ui-text-dimmed);
}

.sp-r9-overview-gradient {
  color: transparent;
  background: linear-gradient(110deg, rgb(99 159 255), rgb(72 203 255), rgb(127 101 255));
  background-size: 180% 100%;
  background-clip: text;
  -webkit-background-clip: text;
  animation: sp-r9-overview-gradient 7s linear infinite;
}

.sp-r9-overview-orbit {
  position: absolute;
  right: -2rem;
  top: 50%;
  width: 31rem;
  height: 23rem;
  transform: translateY(-50%);
}

.sp-r9-overview-orbit__halo {
  position: absolute;
  inset: 15%;
  border-radius: 9999px;
  background: radial-gradient(circle, rgb(63 103 255 / .12), transparent 69%);
  filter: blur(10px);
  animation: sp-r9-overview-halo 6s ease-in-out infinite;
}

.sp-r9-overview-orbit__ring {
  position: absolute;
  left: 50%;
  top: 50%;
  border: 1px solid rgb(97 132 255 / .12);
  border-radius: 9999px;
}

.sp-r9-overview-orbit__ring--a {
  width: 19rem;
  height: 11rem;
  transform: translate(-50%, -50%) rotate(-14deg);
  animation: sp-r9-overview-ring-a 18s linear infinite;
}

.sp-r9-overview-orbit__ring--b {
  width: 15rem;
  height: 15rem;
  transform: translate(-50%, -50%);
  border-color: rgb(122 83 255 / .09);
  animation: sp-r9-overview-ring-b 23s linear infinite reverse;
}

.sp-r9-overview-orbit__core {
  position: absolute;
  left: 50%;
  top: 50%;
  display: grid;
  width: 6.4rem;
  height: 6.4rem;
  place-items: center;
  transform: translate(-50%, -50%) rotate(-6deg);
  border: 1px solid rgb(135 157 255 / .14);
  border-radius: 29%;
  background: linear-gradient(145deg, rgb(58 88 200 / .62), rgb(73 59 162 / .62));
  box-shadow: 0 22px 50px rgb(31 47 125 / .16);
  animation: sp-r9-overview-core 5.3s ease-in-out infinite;
}

.sp-r9-overview-node {
  position: absolute;
  display: grid;
  width: 2.8rem;
  height: 2.8rem;
  place-items: center;
  border: 1px solid rgb(128 147 206 / .09);
  border-radius: .85rem;
  color: rgb(181 198 242);
  background: rgb(18 29 57 / .54);
  backdrop-filter: blur(10px);
  animation: sp-r9-overview-node 5s ease-in-out infinite;
}

.sp-r9-overview-node--key { left: 16%; top: 20%; animation-delay: -1s; }
.sp-r9-overview-node--code { right: 11%; top: 39%; animation-delay: -2.3s; }
.sp-r9-overview-node--chart { right: 28%; bottom: 9%; animation-delay: -3.5s; }

.sp-r9-overview-activity {
  overflow: hidden;
  border-top: 1px solid rgb(255 255 255 / .03);
}

.sp-r9-overview-activity__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .8rem;
  padding: .72rem 0;
}

.sp-r9-overview-activity__row + .sp-r9-overview-activity__row {
  border-top: 1px solid rgb(255 255 255 / .03);
}

.sp-r9-playground-preview__window {
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / .035);
  border-radius: .85rem;
  background: color-mix(in oklab, var(--ui-bg) 38%, transparent);
}

.sp-r9-playground-preview__bar {
  display: flex;
  align-items: center;
  gap: .45rem;
  border-bottom: 1px solid rgb(255 255 255 / .03);
  padding: .6rem .7rem;
  font-size: .65rem;
  color: var(--ui-text-muted);
}

.sp-r9-playground-preview__bubble {
  width: fit-content;
  max-width: 88%;
  border-radius: .75rem;
  background: rgb(255 255 255 / .025);
  padding: .55rem .7rem;
  font-size: .7rem;
  color: var(--ui-text-muted);
}

.sp-r9-playground-preview__bubble--user {
  margin-left: auto;
  background: rgb(69 112 255 / .08);
  color: var(--ui-text-default);
}

.sp-r9-command-card__icon {
  display: grid;
  width: 2.3rem;
  height: 2.3rem;
  place-items: center;
  border: 1px solid rgb(255 255 255 / .035);
  border-radius: .7rem;
  background: rgb(69 112 255 / .065);
  color: var(--ui-primary);
}

@keyframes sp-r9-overview-gradient {
  from { background-position: 0% 50%; }
  to { background-position: 180% 50%; }
}

@keyframes sp-r9-overview-halo {
  0%, 100% { transform: scale(.92); opacity: .45; }
  50% { transform: scale(1.07); opacity: .78; }
}

@keyframes sp-r9-overview-ring-a {
  from { transform: translate(-50%, -50%) rotate(-14deg); }
  to { transform: translate(-50%, -50%) rotate(346deg); }
}

@keyframes sp-r9-overview-ring-b {
  from { transform: translate(-50%, -50%) rotate(0); }
  to { transform: translate(-50%, -50%) rotate(360deg); }
}

@keyframes sp-r9-overview-core {
  0%, 100% { transform: translate(-50%, -50%) rotate(-6deg) translateY(0); }
  50% { transform: translate(-50%, -50%) rotate(-3deg) translateY(-8px); }
}

@keyframes sp-r9-overview-node {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-7px); }
}

@media (min-width: 1024px) {
  .sp-r9-overview-hero {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    align-items: center;
    padding: 1.8rem;
  }
}

@media (max-width: 1023px) {
  .sp-r9-overview-orbit {
    opacity: .22;
    right: -13rem;
  }
}

@media (max-width: 639px) {
  .sp-r9-overview-hero {
    min-height: auto;
    padding: 1rem;
  }

  .sp-r9-overview-orbit {
    display: none;
  }

  .sp-r9-overview-activity__row {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
