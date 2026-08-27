<script setup lang="ts">
import type { AuthFormState } from '~/types/api'

definePageMeta({
  layout: 'auth',
  middleware: ['guest']
})

useSeoMeta({
  title: 'Sign in',
  description: 'Sign in to your SP Cambo account to manage credits, API keys and usage.',
  robots: 'noindex'
})

const auth = useAuthStore()
const route = useRoute()
const googleError = computed(() => typeof route.query.google_error === 'string' ? route.query.google_error : null)

/** Only in-app absolute paths are followed, so `redirect` can't be abused. */
const destination = computed(() => {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null

  return redirect && /^\/(?!\/)/.test(redirect) ? redirect : '/dashboard'
})

const signIn = async (input: AuthFormState) => {
  const success = await auth.login({ email: input.email, password: input.password })

  if (success) {
    await navigateTo(destination.value)
  }
}
</script>

<template>
  <div class="w-full max-w-md space-y-4">
    <div class="mb-1 flex items-center justify-center gap-2 lg:hidden">
      <span class="sp-khmer-chip">កម្ពុជា</span>
      <span class="text-xs text-muted">Secure managed AI access</span>
    </div>
    <UAlert
      v-if="googleError"
      role="alert"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Google sign-in could not be completed"
      :description="googleError"
    />

    <AuthCard
      mode="login"
      @submit="signIn"
    />

    <div class="relative">
      <div class="absolute inset-0 flex items-center">
        <div class="w-full border-t border-default/30" />
      </div>
      <div class="relative flex justify-center text-sm">
        <span class="bg-default px-2 text-muted">
          or
        </span>
      </div>
    </div>

    <GoogleLoginButton
      mode="login"
      :redirect-to="destination"
    />
  </div>
</template>
