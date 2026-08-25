<script setup lang="ts">
import type { AuthFormState } from '~/types/api'

definePageMeta({
  layout: 'auth',
  middleware: ['guest']
})

useSeoMeta({
  title: 'Create account',
  description: 'Create an SP Cambo account to buy prepaid AI credits and issue API keys.',
  robots: 'noindex'
})

const auth = useAuthStore()

const signUp = async (input: AuthFormState) => {
  const success = await auth.register({
    name: input.name,
    email: input.email,
    password: input.password,
    password_confirmation: input.password_confirmation
  })

  if (success) {
    await navigateTo('/dashboard')
  }
}
</script>

<template>
  <div class="w-full max-w-md space-y-4">
    <div class="mb-1 flex items-center justify-center gap-2 lg:hidden">
      <span class="sp-khmer-chip">កម្ពុជា</span>
      <span class="text-xs text-muted">Secure managed AI access</span>
    </div>
    <AuthCard
      mode="register"
      @submit="signUp"
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

    <GoogleLoginButton mode="register" redirect-to="/dashboard" />
  </div>
</template>
