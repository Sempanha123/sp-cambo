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
    description: 'Test available model aliases before you wire them into your app.',
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
        class="sp-action-primary"
      >
        Buy package
      </UButton>
    </template>

    <section class="sp-hero-panel px-5 py-7 sm:px-8 sm:py-9 lg:px-10">
      <div class="grid items-center gap-8 lg:grid-cols-[1.15fr_0.85fr]">
        <div class="relative z-10 max-w-2xl space-y-5">
          <UBadge
            color="primary"
            variant="subtle"
            class="rounded-full"
          >
            AI POWERED ACCESS
          </UBadge>

          <div class="space-y-3">
            <h1 class="text-3xl font-semibold tracking-tight text-highlighted sm:text-4xl lg:text-5xl">
              Premium <span class="sp-gradient-text">AI packages</span><br class="hidden sm:block"> & API access
            </h1>
            <p class="max-w-xl text-sm leading-6 text-muted sm:text-base">
              Prepaid model access, clean API keys and predictable usage in one developer workspace.
            </p>
          </div>

          <div class="flex flex-wrap gap-3">
            <UButton
              to="/dashboard/buy"
              size="lg"
              trailing-icon="i-lucide-arrow-right"
              class="sp-action-primary"
            >
              Explore packages
            </UButton>
            <UButton
              to="/docs"
              size="lg"
              color="neutral"
              variant="subtle"
              icon="i-lucide-book-open"
            >
              Documentation
            </UButton>
          </div>

          <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted">
            <span class="inline-flex items-center gap-1.5"><UIcon name="i-lucide-circle-check" class="size-4 text-success" /> Prepaid usage</span>
            <span class="inline-flex items-center gap-1.5"><UIcon name="i-lucide-circle-check" class="size-4 text-success" /> Scoped API keys</span>
            <span class="inline-flex items-center gap-1.5"><UIcon name="i-lucide-circle-check" class="size-4 text-success" /> Bakong KHQR</span>
          </div>
        </div>

        <div class="sp-hero-orbit hidden lg:block" aria-hidden="true">
          <div class="sp-hero-core">
            <SpBrandMark class="size-16 text-white" />
          </div>
          <div class="sp-floating-chip sp-floating-chip--api text-sm font-semibold">API</div>
          <div class="sp-floating-chip sp-floating-chip--code"><UIcon name="i-lucide-code-xml" class="size-6" /></div>
          <div class="sp-floating-chip sp-floating-chip--chart"><UIcon name="i-lucide-chart-no-axes-combined" class="size-6" /></div>
        </div>
      </div>
    </section>

    <section class="space-y-4">
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
          class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
          <SpMetric
            label="Tokens remaining"
            icon="i-lucide-layers-3"
            :value="formatUnits(balance.data.value.token_quota.remaining_units)"
            :hint="tokenPercent === null ? undefined : `${tokenPercent}% of purchased quota`"
            :tone="isUnitsDepleted(balance.data.value.token_quota.remaining_units) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Tokens reserved"
            icon="i-lucide-zap"
            :value="formatUnits(balance.data.value.token_quota.reserved_units)"
            hint="Held for in-flight requests"
          />
          <SpMetric
            label="Credit balance"
            icon="i-lucide-wallet-cards"
            :value="formatMoney(balance.data.value.credit_balance.remaining)"
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

    <div class="grid gap-5 xl:grid-cols-[1.55fr_0.85fr]">
      <section class="sp-dashboard-section sp-activity-panel space-y-4">
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
            class="sp-dashboard-list divide-y divide-default/60 overflow-hidden"
          >
            <li
              v-for="item in activity.data.value"
              :key="item.id"
              class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between"
            >
              <div class="flex min-w-0 items-start gap-3">
                <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
                  <UIcon name="i-lucide-sparkles" class="size-4" />
                </div>
                <div class="min-w-0 space-y-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-medium text-highlighted">{{ item.public_model }}</p>
                    <SpStatusBadge :status="item.state" />
                    <UBadge v-if="item.estimated" color="warning" variant="subtle" size="sm">Estimated</UBadge>
                  </div>
                  <p class="truncate text-xs text-muted">{{ item.endpoint }} · {{ item.api_key_label }} · {{ formatDateTime(item.started_at) }}</p>
                </div>
              </div>

              <dl class="flex shrink-0 items-center gap-5 text-xs">
                <div><dt class="text-dimmed">Units</dt><dd class="sp-numeric mt-0.5 text-default">{{ formatUnits(item.metered_units) }}</dd></div>
                <div><dt class="text-dimmed">Latency</dt><dd class="sp-numeric mt-0.5 text-default">{{ formatLatency(item.duration_ms) }}</dd></div>
              </dl>
            </li>
          </ul>
        </SpAsyncSection>
      </section>

      <section class="sp-dashboard-section sp-playground-preview space-y-4">
        <SpSectionHeading
          title="Playground preview"
          description="Test a routed model from your browser."
        >
          <template #actions>
            <UButton to="/dashboard/playground" color="neutral" variant="ghost" size="sm" trailing-icon="i-lucide-external-link">Open</UButton>
          </template>
        </SpSectionHeading>

        <div class="rounded-xl border border-default/70 bg-default/30 p-3">
          <div class="flex items-center gap-2 border-b border-default/60 pb-3 text-xs text-muted">
            <span class="rounded-lg border border-default bg-elevated/70 px-2.5 py-1.5">Model</span>
            <span class="font-medium text-default">Choose in Playground</span>
          </div>
          <div class="space-y-3 py-3">
            <div>
              <p class="mb-1.5 text-[11px] text-dimmed">Your prompt</p>
              <div class="rounded-lg border border-default/70 bg-elevated/30 px-3 py-2.5 font-mono text-xs text-muted">Explain your task...</div>
            </div>
            <div>
              <p class="mb-1.5 text-[11px] text-dimmed">Response</p>
              <div class="min-h-20 rounded-lg border border-default/70 bg-elevated/30 px-3 py-2.5 font-mono text-xs text-muted">A routed response will appear here after you run the model.</div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <section class="space-y-4">
      <SpSectionHeading
        title="Quick actions"
        :description="`Signed in as ${auth.user?.email ?? 'your account'}.`"
      />

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <UCard
          v-for="item in quickStart"
          :key="item.to"
          :ui="{ root: 'sp-premium-card sp-command-card h-full rounded-2xl', body: 'flex h-full flex-col gap-4' }"
         class="sp-app-card">
          <div class="flex size-10 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
            <UIcon :name="item.icon" class="size-5" />
          </div>
          <div class="space-y-1.5">
            <h3 class="font-medium text-highlighted">{{ item.title }}</h3>
            <p class="text-sm leading-5 text-muted">{{ item.description }}</p>
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
