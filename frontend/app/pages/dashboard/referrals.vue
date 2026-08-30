<script setup lang="ts">
import type { ReferralDashboard } from '~/types/referrals'

definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Refer & earn', description: 'Invite customers to SP Cambo and earn signup rewards plus account credit from eligible purchases.', robots: 'noindex' })

const api = useSpApi()
const toast = useToast()
const referral = await useSpResource('account:referrals', () => api.referrals.dashboard(), { server: false, immediate: false })
const claimCode = ref('')
const claiming = ref(false)
const referralLoading = computed(() => referral.status.value === 'idle' || referral.loading.value)

onMounted(() => {
  void referral.refresh()
})

const data = computed(() => referral.data.value as ReferralDashboard | null)
const earnedText = computed(() => {
  const rows = data.value?.metrics.earned ?? []
  if (!rows.length) return '$0.00'
  return rows.map(formatMoney).join(' + ')
})

const joinRewardText = computed(() => {
  const current = data.value
  if (!current?.registration_reward_enabled) return 'Off'
  if (current.registration_reward_mode === 'TOKEN_QUOTA') return `${formatUnits(current.registration_token_units)} tokens / signup`
  return `${formatMoney({ minor: current.registration_credit_minor, currency: 'USD', exponent: 2 })} / signup`
})

const copy = async (value: string, label: string) => {
  try {
    await navigator.clipboard.writeText(value)
    toast.add({ title: `${label} copied`, color: 'success', icon: 'i-lucide-copy-check' })
  } catch {
    toast.add({ title: 'Copy failed', description: 'Select the text and copy it manually.', color: 'error' })
  }
}

const claim = async () => {
  const code = claimCode.value.trim()
  if (!code || claiming.value) return
  claiming.value = true
  try {
    await api.referrals.claim(code)
    claimCode.value = ''
    await referral.refresh()
    toast.add({ title: 'Referral attached', description: 'Referral attached. If instant signup rewards are enabled, your inviter is rewarded automatically.', color: 'success' })
  } catch (cause) {
    toast.add({ title: 'Referral not attached', description: toSpApiError(cause).message, color: 'error' })
  } finally {
    claiming.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="referrals">
    <template #header>
      <UDashboardNavbar title="Refer & earn" />
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-7xl space-y-6 pb-10">
        <SpAsyncSection
          :loading="referralLoading"
          :unavailable="referral.unavailable.value"
          :forbidden="referral.forbidden.value"
          :failed="referral.failed.value"
          :error-message="referral.error.value?.message"
          unavailable-title="Referral program unavailable"
          loading-variant="metrics"
          :loading-count="4"
          @retry="referral.refresh()"
        >
          <template v-if="data">
            <UAlert
              v-if="!data.enabled"
              color="warning"
              variant="subtle"
              icon="i-lucide-pause-circle"
              title="Referral program is paused"
              description="Your existing link is preserved, but new referral attribution and rewards are paused by the operator."
            />

            <section class="grid gap-4 xl:grid-cols-[1.35fr_.65fr]">
              <div class="sp-app-card rounded-2xl border border-default p-5 sm:p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                  <div class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Your referral link</p>
                    <h1 class="text-2xl font-semibold tracking-tight text-highlighted">Invite friends. Earn as soon as they join.</h1>
                    <p class="max-w-2xl text-sm leading-6 text-muted">
                      <template v-if="data.registration_reward_enabled">You receive <strong>{{ joinRewardText }}</strong> immediately after a valid invited account registers successfully. </template>You can also earn {{ formatBasisPoints(data.commission_bps) }} of eligible referred purchases{{ data.reward_expiry_days > 0 ? `, with referral rewards valid for ${data.reward_expiry_days} days` : '' }}.
                    </p>
                  </div>
                  <div class="rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm">
                    <p class="text-xs uppercase tracking-wide text-muted">Referral code</p>
                    <button type="button" class="mt-1 font-mono font-semibold text-primary" @click="copy(data.code, 'Code')">{{ data.code }}</button>
                  </div>
                </div>

                <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                  <UInput :model-value="data.share_url" readonly class="min-w-0 flex-1" icon="i-lucide-link-2" />
                  <UButton icon="i-lucide-copy" @click="copy(data.share_url, 'Referral link')">Copy link</UButton>
                </div>
                <p class="mt-2 text-xs text-muted">Attribution is remembered for up to {{ data.cookie_days }} days. Self-referrals and post-purchase claims are blocked.</p>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <SpMetric label="Invited" icon="i-lucide-user-plus" :value="String(data.metrics.invited)" />
                <SpMetric label="Converted" icon="i-lucide-user-check" :value="String(data.metrics.converted)" />
                <SpMetric label="Signup rewards" icon="i-lucide-user-round-check" :value="String(data.metrics.rewarded_registrations)" />
                <SpMetric label="Earned credit" icon="i-lucide-wallet-cards" :value="earnedText" />
              </div>
            </section>

            <section v-if="!data.referred_by" class="rounded-2xl border border-default bg-elevated/20 p-5">
              <SpSectionHeading title="Have an invitation code?" description="Attach it before your first completed purchase. A customer account can have only one referrer." />
              <form class="mt-4 flex max-w-xl flex-col gap-2 sm:flex-row" @submit.prevent="claim">
                <UInput v-model="claimCode" class="flex-1" placeholder="SP…" autocomplete="off" />
                <UButton type="submit" :loading="claiming" :disabled="!claimCode.trim()">Apply referral</UButton>
              </form>
            </section>
            <UAlert
              v-else
              color="success"
              variant="subtle"
              icon="i-lucide-badge-check"
              title="Referral attached"
              :description="`You joined through ${data.referred_by.name}. Referral attribution is locked to protect reward integrity.`"
            />

            <section class="grid gap-5 xl:grid-cols-2">
              <div class="overflow-hidden rounded-2xl border border-default bg-elevated/20">
                <div class="border-b border-default px-5 py-4">
                  <h2 class="font-semibold text-highlighted">Recent invites</h2>
                  <p class="mt-1 text-sm text-muted">Latest customers who joined through your link.</p>
                </div>
                <div v-if="data.invites.length" class="divide-y divide-default">
                  <div v-for="invite in data.invites" :key="invite.id" class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-medium text-highlighted">{{ invite.name }}</p>
                      <p class="text-xs text-muted">Joined {{ invite.joined_at ? formatDateTime(invite.joined_at) : '—' }}</p>
                    </div>
                    <UBadge :color="invite.converted ? 'success' : 'neutral'" variant="subtle">{{ invite.registration_rewarded ? 'Rewarded' : (invite.converted ? 'Purchased' : 'Joined') }}</UBadge>
                  </div>
                </div>
                <div v-else class="px-5 py-10 text-center text-sm text-muted">No referrals yet. Share your link to get started.</div>
              </div>

              <div class="overflow-hidden rounded-2xl border border-default bg-elevated/20">
                <div class="border-b border-default px-5 py-4">
                  <h2 class="font-semibold text-highlighted">Signup rewards</h2>
                  <p class="mt-1 text-sm text-muted">Immediate rewards earned when invited customers register successfully.</p>
                </div>
                <div v-if="data.registration_rewards.length" class="divide-y divide-default">
                  <div v-for="reward in data.registration_rewards" :key="reward.id" class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0"><p class="truncate text-sm font-medium text-highlighted">{{ reward.referred_user?.name ?? 'Invited customer' }}</p><p class="text-xs text-muted">{{ reward.awarded_at ? formatDateTime(reward.awarded_at) : 'Pending' }}</p></div>
                    <UBadge color="success" variant="subtle">+{{ reward.reward_mode === 'TOKEN_QUOTA' ? `${formatUnits(reward.reward_units)} tokens` : formatMoney({ minor: reward.reward_units, currency: reward.currency ?? 'USD', exponent: reward.currency_exponent ?? 2 }) }}</UBadge>
                  </div>
                </div>
                <div v-else class="px-5 py-10 text-center text-sm text-muted">No signup rewards yet.</div>
              </div>

              <div class="overflow-hidden rounded-2xl border border-default bg-elevated/20">
                <div class="border-b border-default px-5 py-4">
                  <h2 class="font-semibold text-highlighted">Recent purchase rewards</h2>
                  <p class="mt-1 text-sm text-muted">Credits are granted only after a referred order is fulfilled.</p>
                </div>
                <div v-if="data.rewards.length" class="divide-y divide-default">
                  <div v-for="reward in data.rewards" :key="reward.id" class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-medium text-highlighted">{{ reward.referred_user?.name ?? 'Referred customer' }}</p>
                      <p class="text-xs text-muted">{{ reward.order_reference ?? 'Order' }} · {{ reward.awarded_at ? formatDateTime(reward.awarded_at) : 'Pending' }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-sm font-semibold text-success">+{{ formatMoney(reward.reward) }}</p>
                      <UBadge :color="reward.status === 'EARNED' ? 'success' : 'neutral'" variant="subtle">{{ reward.status }}</UBadge>
                    </div>
                  </div>
                </div>
                <div v-else class="px-5 py-10 text-center text-sm text-muted">No referral rewards yet.</div>
              </div>
            </section>

            <section class="grid gap-3 md:grid-cols-3">
              <div class="rounded-xl border border-default bg-elevated/20 p-4">
                <UIcon name="i-lucide-link" class="size-5 text-primary" />
                <h3 class="mt-3 font-medium text-highlighted">1. Share</h3>
                <p class="mt-1 text-sm text-muted">Send your unique SP Cambo referral link.</p>
              </div>
              <div class="rounded-xl border border-default bg-elevated/20 p-4">
                <UIcon name="i-lucide-shopping-bag" class="size-5 text-info" />
                <h3 class="mt-3 font-medium text-highlighted">2. They purchase</h3>
                <p class="mt-1 text-sm text-muted">The referral must be attached before their first fulfilled purchase.</p>
              </div>
              <div class="rounded-xl border border-default bg-elevated/20 p-4">
                <UIcon name="i-lucide-coins" class="size-5 text-success" />
                <h3 class="mt-3 font-medium text-highlighted">3. Credit arrives</h3>
                <p class="mt-1 text-sm text-muted">Rewards become normal account credit usable by compatible API keys and purchased-only Playground access.</p>
              </div>
            </section>
          </template>
        </SpAsyncSection>
      </div>
    </template>
  </UDashboardPanel>
</template>
