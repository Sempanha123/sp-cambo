<script setup lang="ts">
import type { AdminReferralSettings } from '~/types/referrals'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Referral program', robots: 'noindex, nofollow' })

const api = useSpApi()
const toast = useToast()
const overview = await useSpResource('admin:referrals', () => api.admin.referrals(), { server: false, immediate: false })
const saving = ref(false)
const overviewLoading = computed(() => overview.status.value === 'idle' || overview.loading.value)

onMounted(() => {
  void overview.refresh()
})
const reason = ref('')
const form = reactive<AdminReferralSettings>({
  enabled: true,
  registration_reward_enabled: true,
  registration_reward_mode: 'CREDIT_BALANCE',
  registration_credit_minor: '25',
  registration_token_units: '25000',
  registration_reward_model_aliases: [],
  commission_bps: 1000,
  referred_bonus_bps: 500,
  minimum_order_minor: '100',
  cookie_days: 30,
  reward_expiry_days: 90,
  commission_all_orders: true,
  referred_bonus_first_order_only: true
})

watch(() => overview.data.value?.settings, (settings) => {
  if (settings) Object.assign(form, settings)
}, { immediate: true })

const save = async () => {
  if (saving.value) return
  if (reason.value.trim().length < 10) {
    toast.add({ title: 'Reason required', description: 'Enter at least 10 characters for the audit log.', color: 'warning' })
    return
  }
  saving.value = true
  try {
    await api.admin.updateReferralSettings({ ...form, reason: reason.value.trim() })
    reason.value = ''
    await overview.refresh()
    toast.add({ title: 'Referral settings saved', color: 'success', icon: 'i-lucide-circle-check' })
  } catch (cause) {
    toast.add({ title: 'Referral settings not saved', description: toSpApiError(cause).message, color: 'error' })
  } finally {
    saving.value = false
  }
}

const totalRewarded = computed(() => {
  const metrics = overview.data.value?.metrics
  if (!metrics) return '$0.00'

  const totals = new Map<string, { minor: bigint, currency: string, exponent: number }>()
  const add = (minor: bigint, currency: string, exponent: number) => {
    const key = `${currency}:${exponent}`
    const current = totals.get(key)
    totals.set(key, { minor: (current?.minor ?? 0n) + minor, currency, exponent })
  }

  for (const row of metrics.earned) {
    add(BigInt(row.referrer_minor) + BigInt(row.bonus_minor), row.currency, row.exponent)
  }
  for (const row of metrics.registration_earned) {
    if (row.mode === 'CREDIT_BALANCE') {
      add(BigInt(row.units), row.currency ?? 'USD', row.exponent ?? 2)
    }
  }

  return Array.from(totals.values())
    .filter(row => row.minor > 0n)
    .map(row => formatMoney({ minor: row.minor.toString(), currency: row.currency, exponent: row.exponent }))
    .join(' + ') || '$0.00'
})
</script>

<template>
  <UDashboardPanel id="admin-referrals">
    <template #header>
      <UDashboardNavbar title="Referral program" />
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-7xl space-y-6 pb-10">
        <SpAsyncSection
          :loading="overviewLoading"
          :unavailable="overview.unavailable.value"
          :forbidden="overview.forbidden.value"
          :failed="overview.failed.value"
          :error-message="overview.error.value?.message"
          unavailable-title="Referral settings unavailable"
          loading-variant="metrics"
          :loading-count="4"
          @retry="overview.refresh()"
        >
          <template v-if="overview.data.value">
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
              <SpMetric label="Referrers" icon="i-lucide-share-2" :value="String(overview.data.value.metrics.referrers)" />
              <SpMetric label="Referred users" icon="i-lucide-user-plus" :value="String(overview.data.value.metrics.referred_users)" />
              <SpMetric label="Converted" icon="i-lucide-user-check" :value="String(overview.data.value.metrics.converted_users)" />
              <SpMetric label="Signup rewards" icon="i-lucide-user-round-check" :value="String(overview.data.value.metrics.rewarded_registrations)" />
              <SpMetric label="Rewarded orders" icon="i-lucide-receipt-text" :value="String(overview.data.value.metrics.rewarded_orders)" />
              <SpMetric label="Credits issued" icon="i-lucide-coins" :value="totalRewarded" />
            </section>

            <section class="grid gap-5 xl:grid-cols-[1fr_.72fr]">
              <form class="rounded-2xl border border-default bg-elevated/20 p-5 sm:p-6" @submit.prevent="save">
                <div class="mb-5 flex items-center justify-between gap-4">
                  <div>
                    <h2 class="font-semibold text-highlighted">Reward rules</h2>
                    <p class="mt-1 text-sm text-muted">Signup rewards can be issued immediately after registration; purchase commissions and welcome bonuses still settle after eligible fulfilled orders.</p>
                  </div>
                  <USwitch v-model="form.enabled" label="Enabled" />
                </div>

                <div class="mb-5 rounded-xl border border-success/25 bg-success/5 p-4">
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><h3 class="font-semibold text-highlighted">Instant signup reward</h3><p class="mt-1 text-sm text-muted">Reward the inviter immediately when a valid referred account finishes registration.</p></div>
                    <USwitch v-model="form.registration_reward_enabled" label="Reward on signup" />
                  </div>
                  <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <UFormField label="Reward type">
                      <USelect v-model="form.registration_reward_mode" :items="[{ label: 'Account credit', value: 'CREDIT_BALANCE' }, { label: 'Tokens', value: 'TOKEN_QUOTA' }]" value-key="value" />
                    </UFormField>
                    <UFormField v-if="form.registration_reward_mode === 'CREDIT_BALANCE'" label="Credit reward (USD minor units)">
                      <UInput v-model="form.registration_credit_minor" inputmode="numeric" icon="i-lucide-dollar-sign" />
                      <template #help><span class="text-xs text-muted">25 = $0.25 per successful invited registration.</span></template>
                    </UFormField>
                    <UFormField v-else label="Token reward">
                      <UInput v-model="form.registration_token_units" inputmode="numeric" icon="i-lucide-coins" />
                      <template #help><span class="text-xs text-muted">Example: 25000 = 25,000 tokens per successful invited registration.</span></template>
                    </UFormField>
                  </div>
                  <UFormField class="mt-4" label="Eligible public models">
                    <USelectMenu v-model="form.registration_reward_model_aliases" :items="overview.data.value.available_aliases" multiple placeholder="All published models" />
                    <template #help><span class="text-xs text-muted">Leave empty to use all public customer models. Signup credit is still issued if provider routing is temporarily unavailable.</span></template>
                  </UFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                  <UFormField label="Referrer commission" :hint="formatBasisPoints(form.commission_bps)">
                    <UInput v-model.number="form.commission_bps" type="number" min="0" max="5000" step="25" icon="i-lucide-percent" />
                    <template #help><span class="text-xs text-muted">Basis points. 1000 = 10%.</span></template>
                  </UFormField>
                  <UFormField label="New-customer bonus" :hint="formatBasisPoints(form.referred_bonus_bps)">
                    <UInput v-model.number="form.referred_bonus_bps" type="number" min="0" max="5000" step="25" icon="i-lucide-gift" />
                    <template #help><span class="text-xs text-muted">Bonus credit granted to the referred customer.</span></template>
                  </UFormField>
                  <UFormField label="Minimum order (minor units)">
                    <UInput v-model="form.minimum_order_minor" inputmode="numeric" icon="i-lucide-badge-dollar-sign" />
                    <template #help><span class="text-xs text-muted">For USD exponent 2, 100 means $1.00.</span></template>
                  </UFormField>
                  <UFormField label="Attribution window">
                    <UInput v-model.number="form.cookie_days" type="number" min="1" max="365" icon="i-lucide-calendar-days" />
                    <template #help><span class="text-xs text-muted">Days a referral link is remembered.</span></template>
                  </UFormField>
                  <UFormField label="Reward expiry">
                    <UInput v-model.number="form.reward_expiry_days" type="number" min="0" max="3650" icon="i-lucide-clock-3" />
                    <template #help><span class="text-xs text-muted">0 means referral credit does not expire.</span></template>
                  </UFormField>
                </div>

                <div class="mt-5 space-y-3 rounded-xl border border-default bg-default/30 p-4">
                  <USwitch v-model="form.commission_all_orders" label="Pay referrer commission on every eligible referred order" />
                  <USwitch v-model="form.referred_bonus_first_order_only" label="Limit referred-customer bonus to the first eligible order" />
                </div>

                <UFormField class="mt-5" label="Audit reason" required>
                  <UTextarea v-model="reason" :rows="3" placeholder="Why are these referral economics being changed?" />
                </UFormField>

                <div class="mt-5 flex justify-end">
                  <UButton type="submit" :loading="saving" icon="i-lucide-save">Save referral settings</UButton>
                </div>
              </form>

              <div class="space-y-4">
                <UAlert
                  color="info"
                  variant="subtle"
                  icon="i-lucide-shield-check"
                  title="Built-in abuse controls"
                  description="Self-referrals, referral loops and claims after a completed purchase are rejected. Each referred account can trigger the instant signup reward only once, and every fulfilled order is rewarded at most once."
                />
                <UAlert
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-wallet-cards"
                  title="Credits, not cash payouts"
                  description="Referral rewards become normal ACCOUNT credit scoped to the currently published public models. They follow the same reservation, usage and expiry ledger as purchased credit."
                />
              </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-default bg-elevated/20">
              <div class="border-b border-default px-5 py-4">
                <h2 class="font-semibold text-highlighted">Recent signup rewards</h2>
                <p class="mt-1 text-sm text-muted">Immediate inviter rewards created when referred accounts register successfully.</p>
              </div>
              <div v-if="overview.data.value.recent_registration_rewards.length" class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                  <thead class="text-xs uppercase tracking-wide text-muted"><tr><th class="px-5 py-3">Referrer</th><th class="px-5 py-3">New customer</th><th class="px-5 py-3">Reward</th><th class="px-5 py-3">Models</th><th class="px-5 py-3">Status</th></tr></thead>
                  <tbody class="divide-y divide-default">
                    <tr v-for="reward in overview.data.value.recent_registration_rewards" :key="reward.id">
                      <td class="px-5 py-3"><p class="font-medium text-highlighted">{{ reward.referrer?.name ?? '—' }}</p><p class="text-xs text-muted">{{ reward.referrer?.email }}</p></td>
                      <td class="px-5 py-3"><p class="font-medium text-highlighted">{{ reward.referred_user?.name ?? '—' }}</p><p class="text-xs text-muted">{{ reward.referred_user?.email }}</p></td>
                      <td class="px-5 py-3 font-medium text-success">+{{ reward.reward_mode === 'TOKEN_QUOTA' ? `${formatUnits(reward.reward_units)} tokens` : formatMoney({ minor: reward.reward_units, currency: reward.currency ?? 'USD', exponent: reward.currency_exponent ?? 2 }) }}</td>
                      <td class="px-5 py-3"><div class="flex max-w-md flex-wrap gap-1"><UBadge v-for="alias in reward.allowed_model_aliases" :key="alias" color="neutral" variant="subtle">{{ alias }}</UBadge></div></td>
                      <td class="px-5 py-3"><UBadge color="success" variant="subtle">{{ reward.status }}</UBadge></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-else class="px-5 py-10 text-center text-sm text-muted">No signup rewards have been created yet.</div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-default bg-elevated/20">
              <div class="border-b border-default px-5 py-4">
                <h2 class="font-semibold text-highlighted">Recent purchase rewards</h2>
                <p class="mt-1 text-sm text-muted">Latest conversions and the exact credit issued to each side.</p>
              </div>
              <div v-if="overview.data.value.recent_rewards.length" class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-sm">
                  <thead class="text-xs uppercase tracking-wide text-muted">
                    <tr>
                      <th class="px-5 py-3">Referrer</th>
                      <th class="px-5 py-3">Customer</th>
                      <th class="px-5 py-3">Order</th>
                      <th class="px-5 py-3">Order total</th>
                      <th class="px-5 py-3">Referrer credit</th>
                      <th class="px-5 py-3">Welcome bonus</th>
                      <th class="px-5 py-3">Status</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-default">
                    <tr v-for="reward in overview.data.value.recent_rewards" :key="reward.id">
                      <td class="px-5 py-3"><p class="font-medium text-highlighted">{{ reward.referrer?.name ?? '—' }}</p><p class="text-xs text-muted">{{ reward.referrer?.email }}</p></td>
                      <td class="px-5 py-3"><p class="font-medium text-highlighted">{{ reward.referred_user?.name ?? '—' }}</p><p class="text-xs text-muted">{{ reward.referred_user?.email }}</p></td>
                      <td class="px-5 py-3 font-mono text-xs">{{ reward.order_reference ?? '—' }}</td>
                      <td class="px-5 py-3">{{ formatMoney({ minor: reward.order_total_minor, currency: reward.currency, exponent: reward.currency_exponent }) }}</td>
                      <td class="px-5 py-3 font-medium text-success">+{{ formatMoney({ minor: reward.referrer_reward_minor, currency: reward.currency, exponent: reward.currency_exponent }) }}</td>
                      <td class="px-5 py-3 font-medium text-info">+{{ formatMoney({ minor: reward.referred_bonus_minor, currency: reward.currency, exponent: reward.currency_exponent }) }}</td>
                      <td class="px-5 py-3"><UBadge :color="reward.status === 'EARNED' ? 'success' : 'neutral'" variant="subtle">{{ reward.status }}</UBadge></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-else class="px-5 py-10 text-center text-sm text-muted">No referral rewards have been created yet.</div>
            </section>
          </template>
        </SpAsyncSection>
      </div>
    </template>
  </UDashboardPanel>
</template>
