<script setup lang="ts">
definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Referral invitation', robots: 'noindex, nofollow' })

const route = useRoute()
const api = useSpApi()
const auth = useAuthStore()
const referral = useReferralAttribution()
const error = ref<string | null>(null)

onMounted(async () => {
  await auth.initialize()
  const code = typeof route.params.code === 'string' ? route.params.code : ''
  try {
    const result = await api.referrals.resolve(code)
    if (!result.valid || !result.code) {
      error.value = 'This referral link is invalid or the referral program is paused.'
      return
    }
    referral.capture(result.code, result.cookie_days)
    if (auth.authenticated) {
      await referral.claimIfPossible()
      await navigateTo('/dashboard/referrals', { replace: true })
      return
    }
    await navigateTo({ path: '/register', query: { ref: result.code } }, { replace: true })
  } catch (cause) {
    error.value = toSpApiError(cause).message
  }
})
</script>

<template>
  <div class="mx-auto flex min-h-[50vh] max-w-xl items-center justify-center px-4">
    <UCard class="w-full sp-app-card">
      <div v-if="!error" class="space-y-3 py-8 text-center" aria-live="polite">
        <UIcon name="i-lucide-loader-circle" class="mx-auto size-7 animate-spin text-primary" />
        <h1 class="text-xl font-semibold text-highlighted">Opening your SP Cambo invitation</h1>
        <p class="text-sm text-muted">Checking the referral and taking you to sign up.</p>
      </div>
      <div v-else class="space-y-4 py-6 text-center">
        <UIcon name="i-lucide-link-2-off" class="mx-auto size-7 text-error" />
        <h1 class="text-xl font-semibold text-highlighted">Referral link unavailable</h1>
        <p class="text-sm text-muted">{{ error }}</p>
        <UButton to="/register">Create account without referral</UButton>
      </div>
    </UCard>
  </div>
</template>
