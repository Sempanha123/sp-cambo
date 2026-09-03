<script setup lang="ts">
import type { EntitlementLot } from '~/types/commerce'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Entitlements',
  description: 'Every package lot on your account, what remains in it, what is reserved, and when it expires.',
  robots: 'noindex'
})

const api = useSpApi()

const balance = await useSpResource('dashboard:balance', () => api.account.balance(), { server: false })
const lots = await useSpResource('dashboard:entitlements', () => api.account.entitlements(), { server: false })

const reuseSavings = await useSpResource(
  'dashboard:entitlements-reuse-savings',
  () => api.account.usageSummary({
    from: new Date(Date.now() - 30 * 24 * 3600_000).toISOString(),
    to: new Date().toISOString(),
    bucket: 'day'
  }),
  { server: false }
)

const redeemCode = ref('')
const redeeming = ref(false)
const redeemError = ref<string | null>(null)
const redeemSuccess = ref<string | null>(null)

const submitRedeemCode = async () => {
  const code = redeemCode.value.trim()
  if (!code || redeeming.value) return
  redeeming.value = true
  redeemError.value = null
  redeemSuccess.value = null
  try {
    const result = await api.account.redeemCode({
      code,
      idempotency_key: `web:${Date.now()}:${Math.random().toString(36).slice(2)}`
    })
    redeemSuccess.value = `${customerUnitLabel(result.package_name)} was added to your account.`
    redeemCode.value = ''
    await Promise.all([balance.refresh(), lots.refresh()])
  } catch (error) {
    redeemError.value = error instanceof Error ? error.message : 'The redeem code could not be applied.'
  } finally {
    redeeming.value = false
  }
}

/** Ticks the relative expiry labels without touching the server-side numbers. */
const now = ref(Date.now())
let tickTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  tickTimer = setInterval(() => {
    now.value = Date.now()
  }, 30_000)
})

onBeforeUnmount(() => clearInterval(tickTimer))

/**
 * Spendable lots in the order the backend will consume them: soonest expiry
 * first (FEFO), so the list reads the same way the meter behaves. Lots without
 * an expiry are consumed after those that have one.
 */
const spendable = computed(() => spendableLots(lots.data.value ?? []))

const pending = computed(() => pendingLots(lots.data.value ?? []))

const inactive = computed(() => closedLots(lots.data.value ?? []))

const showInactive = ref(false)

/** Highlights a lot that is nearly out of time so it can be used before it lapses. */
const expiringSoon = (lot: EntitlementLot) => isLotExpiringSoon(lot, now.value)
const accessLabel = (lot: EntitlementLot) => {
  if (lot.access_scope === 'PLAYGROUND') return 'Playground only'
  if (lot.access_scope === 'API_KEY') return lot.bound_api_key ? `Key · ${lot.bound_api_key.masked_key}` : 'Dedicated API key'
  if (lot.access_scope === 'UNASSIGNED') return 'Choose access'
  return 'Legacy shared balance'
}
const accessTone = (lot: EntitlementLot) => lot.access_scope === 'UNASSIGNED' ? 'warning' : lot.access_scope === 'PLAYGROUND' ? 'info' : lot.access_scope === 'API_KEY' ? 'success' : 'neutral'


const tokenPercent = computed(() => {
  const quota = balance.data.value?.token_quota

  return quota ? percentOfUnits(quota.remaining_units, quota.original_units) : null
})

const formatSpCreditBalance = (value: string | null | undefined) => {
  if (value == null) return '$0'
  const amount = Number(value)
  return Number.isFinite(amount)
    ? `$${amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 5 })}`
    : value
}

const customerUnitLabel = (value: string | null | undefined) => (value ?? 'Tokens')
  .replaceAll('SP billable tokens', 'Tokens')
  .replaceAll('SP billable units', 'Tokens')
  .replaceAll('SP Tokens', 'Tokens')
  .replaceAll('SP Credits', 'Credits')

const refreshAll = () => {
  balance.refresh()
  lots.refresh()
  reuseSavings.refresh()
}
</script>

<template>
  <SpDashboardPage
    title="Entitlements"
    icon="i-lucide-hourglass"
    description="Each purchase becomes its own lot with its own quantity and its own expiry. Requests spend the soonest-expiring lot first."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-refresh-cw"
        :loading="lots.loading.value || balance.loading.value || reuseSavings.loading.value"
        @click="refreshAll"
      >
        Refresh
      </UButton>
      <UButton
        to="/dashboard/buy"
        icon="i-lucide-plus"
      >
        Buy more
      </UButton>
    </template>

    <section class="space-y-4">
      <SpAsyncSection
        :loading="balance.initialLoading.value"
        :unavailable="balance.unavailable.value"
        :failed="balance.failed.value"
        :offline="balance.error.value?.code === 'network_unreachable'"
        :error-message="balance.error.value?.message"
        unavailable-title="Balances are not published yet"
        unavailable-description="The control plane has not shipped the balance endpoint. SP Cambo will not show an invented figure in its place."
        loading-variant="metrics"
        :loading-count="4"
        @retry="balance.refresh()"
      >
        <div
          v-if="balance.data.value"
          class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
          <SpMetric
            label="Total Tokens"
            icon="i-lucide-hourglass"
            :value="formatUnits(balance.data.value.token_quota.remaining_units)"
            :hint="tokenPercent === null ? undefined : `${tokenPercent}% of purchased quota · purchased Token quota`"
            :tone="isUnitsDepleted(balance.data.value.token_quota.remaining_units) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Credits"
            icon="i-lucide-wallet-cards"
            :value="formatSpCreditBalance(balance.data.value.sp_credit_quota?.remaining)"
            :hint="balance.data.value.sp_credit_quota
              ? `${formatSpCreditBalance(balance.data.value.sp_credit_quota.reserved)} reserved · $1 Credit = ${formatUnits(balance.data.value.sp_credit_quota.billable_units_per_credit)} Tokens`
              : 'Dollar-denominated platform Credits'"
            :tone="Number(balance.data.value.sp_credit_quota?.remaining ?? 0) <= 0 ? 'warning' : 'default'"
          />
          <SpMetric
            label="Wallet credit"
            icon="i-lucide-circle-dollar-sign"
            :value="formatMoney(balance.data.value.credit_balance.remaining)"
            :hint="isZeroMoney(balance.data.value.credit_balance.reserved)
              ? 'Money credit from rewards or wallet funding'
              : `${formatMoney(balance.data.value.credit_balance.reserved)} reserved`"
            :tone="isZeroMoney(balance.data.value.credit_balance.remaining) ? 'warning' : 'default'"
          />
          <SpMetric
            label="Next expiry"
            icon="i-lucide-calendar-clock"
            :value="balance.data.value.next_expires_at
              ? formatRemaining(Date.parse(balance.data.value.next_expires_at) - now)
              : '—'"
            :hint="balance.data.value.next_expires_at
              ? formatDateTime(balance.data.value.next_expires_at)
              : 'No lot is due to expire'"
          />
        </div>
      </SpAsyncSection>
    </section>

    <UAlert
      v-if="reuseSavings.data.value && Number(reuseSavings.data.value.saved_tokens) > 0"
      icon="i-lucide-sparkles"
      color="success"
      variant="subtle"
      title="Your balance went further with smart reuse"
      :description="`${formatUnits(reuseSavings.data.value.saved_tokens)} Tokens saved in the last 30 days · ${Number(reuseSavings.data.value.savings_rate_percent).toFixed(1)}% average savings on locally metered Token usage.`"
    />

    <section class="space-y-4">
      <SpSectionHeading
        title="Redeem free tokens or credit"
        description="Codes are validated and consumed by the control plane. A successful redemption creates a normal entitlement lot with its own model scope and expiry."
      />
      <div class="rounded-xl border border-default bg-elevated/30 p-4 sm:p-5">
        <form
          class="flex flex-col gap-3 sm:flex-row"
          @submit.prevent="submitRedeemCode"
        >
          <UInput
            v-model="redeemCode"
            class="flex-1"
            autocomplete="off"
            spellcheck="false"
            placeholder="SPC-FREE-…"
            icon="i-lucide-ticket"
          />
          <UButton
            type="submit"
            :loading="redeeming"
            :disabled="!redeemCode.trim() || redeeming"
          >
            Redeem code
          </UButton>
        </form>
        <UAlert
          v-if="redeemError"
          class="mt-3"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Code not redeemed"
          :description="redeemError"
        />
        <UAlert
          v-if="redeemSuccess"
          class="mt-3"
          color="success"
          variant="subtle"
          icon="i-lucide-circle-check"
          title="Entitlement added"
          :description="redeemSuccess"
        />
      </div>
    </section>

    <section class="space-y-4">
      <SpSectionHeading
        title="Spendable lots"
        description="Listed in consumption order. The lot at the top is the one your next request draws from."
      />

      <SpAsyncSection
        :loading="lots.initialLoading.value"
        :unavailable="lots.unavailable.value"
        :failed="lots.failed.value"
        :empty="lots.isEmpty.value"
        :offline="lots.error.value?.code === 'network_unreachable'"
        :error-message="lots.error.value?.message"
        unavailable-title="Entitlements are not published yet"
        unavailable-description="SP Cambo reads your lots from the control plane. Until that endpoint is live there is nothing to display, and no placeholder quantity will be shown."
        empty-title="No entitlements yet"
        empty-description="Buy a package to create your first lot. Access begins the moment payment is confirmed."
        empty-icon="i-lucide-hourglass"
        loading-variant="rows"
        @retry="lots.refresh()"
      >
        <div class="space-y-3">
          <p
            v-if="spendable.length === 0 && (lots.data.value?.length ?? 0) > 0"
            class="rounded-lg border border-warning/30 bg-warning/5 p-4 text-sm text-toned"
          >
            Nothing on this account can serve a request right now — every lot is spent, expired or not yet
            active. Requests will be refused rather than billed as an overage.
          </p>

          <article
            v-for="(lot, index) in spendable"
            :key="lot.id"
            class="rounded-lg border p-5"
            :class="index === 0 ? 'border-primary/40 bg-primary/5' : 'border-default bg-elevated/30'"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="truncate font-medium text-highlighted">
                    {{ customerUnitLabel(lot.package_name) }}
                  </h3>
                  <SpStatusBadge :status="lot.status.toLowerCase()" />
                  <UBadge :color="accessTone(lot)" variant="subtle" size="sm">{{ accessLabel(lot) }}</UBadge>
                  <UBadge
                    v-if="index === 0"
                    color="primary"
                    variant="subtle"
                    size="sm"
                  >
                    Spent next
                  </UBadge>
                  <UBadge
                    v-if="expiringSoon(lot)"
                    color="warning"
                    variant="subtle"
                    size="sm"
                    icon="i-lucide-triangle-alert"
                  >
                    Expiring soon
                  </UBadge>
                </div>
                <p class="text-xs text-muted">
                  {{ lot.family_label }} ·
                  {{ lot.billing_mode === 'TOKEN_QUOTA' ? 'Token quota' : 'Credit balance' }} ·
                  {{ lotSourceLabel(lot) }}
                </p>
              </div>

              <div class="text-right">
                <p class="sp-numeric text-lg font-semibold text-highlighted">
                  {{ lot.billing_mode === 'CREDIT_BALANCE' && lot.remaining_amount
                    ? formatMoney(lot.remaining_amount)
                    : formatUnits(lot.remaining_units) }}
                </p>
                <p class="text-xs text-muted">
                  {{ lot.billing_mode === 'CREDIT_BALANCE' ? 'remaining' : `${customerUnitLabel(lot.unit_label)} remaining` }}
                </p>
              </div>
            </div>

            <UProgress
              v-if="lotPercentRemaining(lot) !== null"
              :model-value="lotPercentRemaining(lot) ?? 0"
              :max="100"
              size="sm"
              class="mt-4"
              :color="expiringSoon(lot) ? 'warning' : 'primary'"
              :aria-label="`${lotPercentRemaining(lot)}% of ${customerUnitLabel(lot.package_name)} remaining`"
            />

            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-4">
              <div>
                <dt class="text-dimmed">
                  Purchased
                </dt>
                <dd class="sp-numeric text-default">
                  {{ formatUnits(lot.original_units) }}
                </dd>
              </div>
              <div>
                <dt class="text-dimmed">
                  Reserved
                </dt>
                <dd class="sp-numeric text-default">
                  {{ formatUnits(lot.reserved_units) }}
                </dd>
              </div>
              <div>
                <dt class="text-dimmed">
                  Activated
                </dt>
                <dd class="text-default">
                  {{ lot.activated_at ? formatDateTime(lot.activated_at) : 'Not yet' }}
                </dd>
              </div>
              <div>
                <dt class="text-dimmed">
                  Expires
                </dt>
                <dd
                  class="text-default"
                  :title="formatExactTimestamp(lot.expires_at)"
                >
                  <template v-if="lot.expires_at">
                    {{ formatRemaining(Date.parse(lot.expires_at) - now) }}
                    <span class="text-dimmed">· {{ formatDateTime(lot.expires_at) }}</span>
                  </template>
                  <template v-else>
                    No expiry
                  </template>
                </dd>
              </div>
            </dl>

            <div class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-default pt-3">
              <span class="text-xs text-dimmed">Models:</span>
              <template v-if="lot.allowed_model_aliases.length > 0">
                <SpModelBadge
                  v-for="alias in lot.allowed_model_aliases"
                  :key="alias"
                  :model="alias"
                  compact
                />
              </template>
              <span
                v-else
                class="text-xs text-muted"
              >Every model in the catalogue</span>
            </div>
          </article>
        </div>
      </SpAsyncSection>
    </section>

    <section
      v-if="pending.length > 0"
      class="space-y-4"
    >
      <SpSectionHeading
        title="Choose access"
        description="These paid lots are secured but not spendable until you choose Playground, a new dedicated API key, or an existing API key."
        :level="3"
      />

      <ul class="divide-y divide-default overflow-hidden rounded-lg border border-default">
        <li
          v-for="lot in pending"
          :key="lot.id"
          class="flex flex-wrap items-center justify-between gap-3 bg-elevated/30 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-highlighted">
              {{ customerUnitLabel(lot.package_name) }}
            </p>
            <p class="text-xs text-muted">
              {{ formatUnits(lot.original_units) }} {{ customerUnitLabel(lot.unit_label) }} · {{ lot.family_label }}
            </p>
          </div>
          <div class="flex items-center gap-2">
            <UBadge color="warning" variant="subtle">Not active yet · Choose access</UBadge>
            <UButton
              v-if="lot.fulfillment_claim_id"
              :to="`/dashboard/claim-key?claim=${lot.fulfillment_claim_id}`"
              size="sm"
              icon="i-lucide-route"
            >
              Allocate
            </UButton>
          </div>
        </li>
      </ul>
    </section>

    <section
      v-if="inactive.length > 0"
      class="space-y-4"
    >
      <SpSectionHeading
        title="Closed lots"
        :description="`${inactive.length} lot${inactive.length === 1 ? '' : 's'} that can no longer serve a request.`"
        :level="3"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            :icon="showInactive ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            @click="showInactive = !showInactive"
          >
            {{ showInactive ? 'Hide' : 'Show' }}
          </UButton>
        </template>
      </SpSectionHeading>

      <ul
        v-if="showInactive"
        class="divide-y divide-default overflow-hidden rounded-lg border border-default"
      >
        <li
          v-for="lot in inactive"
          :key="lot.id"
          class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="truncate text-sm text-default">
              {{ customerUnitLabel(lot.package_name) }}
            </p>
            <p class="text-xs text-muted">
              {{ formatUnits(lot.original_units) }} {{ customerUnitLabel(lot.unit_label) }} purchased ·
              {{ lot.expires_at ? `ended ${formatDateTime(lot.expires_at)}` : 'no expiry recorded' }}
            </p>
          </div>
          <div class="flex items-center gap-3">
            <span
              v-if="!isUnitsDepleted(lot.remaining_units) && lot.status === 'EXPIRED'"
              class="sp-numeric text-xs text-warning"
            >
              {{ formatUnits(lot.remaining_units) }} forfeited
            </span>
            <SpStatusBadge :status="lot.status.toLowerCase()" />
          </div>
        </li>
      </ul>
    </section>

    <div class="rounded-lg border border-default p-4 text-xs text-muted">
      <p class="font-medium text-default">
        How a lot is spent
      </p>
      <p class="mt-1">
        Each new purchase is assigned to one access target. Playground-only lots are not spendable by normal API keys, and dedicated API-key lots cannot be consumed by another key. A request reserves what it might use before any upstream call is made, then settles against the actual
        amount when it finishes. Reserved quantity is not yet spent, and an aborted request releases its
        reservation. Quantity still sitting in a lot when its lifetime ends is forfeited, which is why the
        soonest-expiring lot is always drawn from first.
      </p>
      <NuxtLink
        to="/docs/billing"
        class="mt-2 inline-flex items-center gap-1 text-primary underline decoration-dotted underline-offset-4"
      >
        Billing model
      </NuxtLink>
    </div>
  </SpDashboardPage>
</template>
