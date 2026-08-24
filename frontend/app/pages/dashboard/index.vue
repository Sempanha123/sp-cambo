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
    title: 'Upgrade or add quota',
    description: 'Choose a package and pay with Bakong KHQR. Access activates as soon as payment settles.',
    icon: 'i-lucide-package',
    to: '/dashboard/buy',
    label: 'Upgrade plan'
  },
  {
    title: 'Create an API key',
    description: 'Issue a scoped key for Claude Code, Codex CLI or your own SDK integration.',
    icon: 'i-lucide-key-round',
    to: '/dashboard/api-keys',
    label: 'Manage keys'
  },
  {
    title: 'Build a request',
    description: 'Compose a call against one of your aliases and copy it as cURL, Python or Node.',
    icon: 'i-lucide-flask-conical',
    to: '/dashboard/playground',
    label: 'Open playground'
  },
  {
    title: 'Connect your CLI',
    description: 'Copy the exact environment variables and config for your tool of choice.',
    icon: 'i-lucide-terminal',
    to: '/dashboard/cli-setup',
    label: 'CLI setup'
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
      >
        Upgrade plan
      </UButton>
    </template>

    <section class="space-y-4">
      <SpSectionHeading
        title="Balance"
        description="Spendable entitlement lots across your account. Reserved amounts are held for in-flight requests."
      >
        <template #actions>
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
        </template>
      </SpSectionHeading>

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
            icon="i-lucide-hourglass"
            :value="formatUnits(balance.data.value.token_quota.remaining_units)"
            :hint="tokenPercent === null ? undefined : `${tokenPercent}% of purchased quota`"
            :tone="isUnitsDepleted(balance.data.value.token_quota.remaining_units) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Tokens reserved"
            icon="i-lucide-lock"
            :value="formatUnits(balance.data.value.token_quota.reserved_units)"
            hint="Held for requests in flight"
          />
          <SpMetric
            label="Credit balance"
            icon="i-lucide-wallet"
            :value="formatMoney(balance.data.value.credit_balance.remaining)"
            :tone="isZeroMoney(balance.data.value.credit_balance.remaining) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Active lots"
            icon="i-lucide-layers"
            :value="formatCount(balance.data.value.active_lot_count)"
            :hint="expiryHint"
          />
        </div>
      </SpAsyncSection>
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Recent activity"
        description="Request metadata only. SP Cambo never stores your prompts or completions."
      >
        <template #actions>
          <UButton
            to="/dashboard/usage"
            color="neutral"
            variant="subtle"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
          >
            View all usage
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
        empty-description="Once your first API call goes through, it will appear here within seconds."
        empty-icon="i-lucide-activity"
        loading-variant="rows"
        @retry="activity.refresh()"
      >
        <ul
          v-if="activity.data.value"
          class="divide-y divide-default overflow-hidden rounded-lg border border-default"
        >
          <li
            v-for="item in activity.data.value"
            :key="item.id"
            class="flex flex-col gap-2 bg-elevated/30 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0 space-y-1">
              <div class="flex flex-wrap items-center gap-2">
                <p class="truncate text-sm font-medium text-highlighted">
                  {{ item.public_model }}
                </p>
                <SpStatusBadge :status="item.state" />
                <UBadge
                  v-if="item.estimated"
                  color="warning"
                  variant="subtle"
                  size="sm"
                >
                  Estimated
                </UBadge>
              </div>
              <p class="truncate text-xs text-muted">
                {{ item.endpoint }} · {{ item.api_key_label }} · {{ formatDateTime(item.started_at) }}
              </p>
            </div>

            <dl class="flex shrink-0 items-center gap-5 text-xs">
              <div>
                <dt class="text-dimmed">
                  Units
                </dt>
                <dd class="sp-numeric text-default">
                  {{ formatUnits(item.metered_units) }}
                </dd>
              </div>
              <div>
                <dt class="text-dimmed">
                  Latency
                </dt>
                <dd class="sp-numeric text-default">
                  {{ formatLatency(item.duration_ms) }}
                </dd>
              </div>
            </dl>
          </li>
        </ul>
      </SpAsyncSection>
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Next steps"
        :description="`Signed in as ${auth.user?.email ?? 'your account'}.`"
      />

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <UCard
          v-for="item in quickStart"
          :key="item.to"
          :ui="{ root: 'h-full', body: 'flex h-full flex-col gap-3' }"
        >
          <UIcon
            :name="item.icon"
            class="size-5 text-primary"
          />
          <div class="space-y-1.5">
            <h3 class="font-medium text-highlighted">
              {{ item.title }}
            </h3>
            <p class="text-sm text-muted">
              {{ item.description }}
            </p>
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
