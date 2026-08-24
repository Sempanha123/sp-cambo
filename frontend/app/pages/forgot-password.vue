<script setup lang="ts">
import type { FormError } from '@nuxt/ui'

definePageMeta({
  layout: 'auth',
  middleware: ['guest']
})

useSeoMeta({
  title: 'Reset your password',
  description: 'Request a password reset link for your SP Cambo account.',
  robots: 'noindex'
})

const api = useSpApi()

const state = reactive({ email: '' })
const submitting = ref(false)
const sent = ref(false)
const bannerError = ref<string | null>(null)

const validate = (values: { email: string }): FormError[] => {
  if (!values.email.trim()) {
    return [{ name: 'email', message: 'Enter your email address.' }]
  }

  if (!/^\S+@\S+\.\S+$/.test(values.email)) {
    return [{ name: 'email', message: 'Enter a valid email address.' }]
  }

  return []
}

/**
 * The control plane answers identically whether or not the address exists, and
 * this page must preserve that: the confirmation below is deliberately phrased
 * so it reveals nothing about whether an account was found. Only transport-level
 * failures (rate limiting, an unreachable control plane) are surfaced.
 */
const submit = async () => {
  submitting.value = true
  bannerError.value = null

  try {
    await api.auth.forgotPassword({ email: state.email.trim() })
    sent.value = true
  } catch (cause) {
    const error = toSpApiError(cause)

    if (error.isValidation) {
      bannerError.value = error.fieldError('email') ?? error.message
    } else {
      bannerError.value = error.message
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="w-full max-w-md">
    <UCard :ui="{ root: 'ring-default/80 shadow-xl shadow-black/5 dark:shadow-black/40' }">
      <template #header>
        <div class="space-y-2">
          <p class="text-xs font-medium tracking-wide text-primary uppercase">
            Password reset
          </p>
          <h1 class="text-2xl font-semibold tracking-tight text-highlighted">
            {{ sent ? 'Check your email' : 'Forgot your password?' }}
          </h1>
          <p class="text-sm text-muted">
            {{ sent
              ? 'If that address belongs to an SP Cambo account, reset instructions are on their way.'
              : 'Enter the address you signed up with and we will send a reset link.' }}
          </p>
        </div>
      </template>

      <div
        v-if="sent"
        class="space-y-4"
      >
        <UAlert
          color="info"
          variant="subtle"
          icon="i-lucide-mail-check"
          title="Request received"
          description="For your security, SP Cambo does not confirm whether an account exists for that address. The link expires after a short time — request another if it does."
        />

        <UButton
          to="/login"
          block
          color="neutral"
          variant="subtle"
          icon="i-lucide-arrow-left"
        >
          Back to sign in
        </UButton>
      </div>

      <template v-else>
        <div
          aria-live="polite"
          role="status"
        >
          <UAlert
            v-if="bannerError"
            role="alert"
            class="mb-6"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="We couldn't send that request"
            :description="bannerError"
            close
            @update:open="bannerError = null"
          />
        </div>

        <UForm
          :state="state"
          :validate="validate"
          class="space-y-5"
          @submit="submit"
        >
          <UFormField
            label="Email"
            name="email"
            required
          >
            <UInput
              v-model="state.email"
              class="w-full"
              icon="i-lucide-mail"
              type="email"
              inputmode="email"
              autocomplete="email"
              placeholder="you@example.com"
            />
          </UFormField>

          <UButton
            type="submit"
            block
            size="lg"
            :loading="submitting"
            :disabled="submitting"
          >
            Send reset link
          </UButton>
        </UForm>
      </template>

      <template #footer>
        <p class="text-center text-sm text-muted">
          Remembered it?
          <NuxtLink
            to="/login"
            class="font-medium text-primary hover:underline"
          >
            Sign in
          </NuxtLink>
        </p>
      </template>
    </UCard>
  </div>
</template>
