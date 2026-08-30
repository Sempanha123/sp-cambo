<script setup lang="ts">
import type { FormError, FormSubmitEvent } from '@nuxt/ui'
import type { AuthFormState } from '~/types/api'

const props = defineProps<{
  mode: 'login' | 'register'
}>()

const emit = defineEmits<{
  submit: [data: AuthFormState]
}>()

const auth = useAuthStore()

const form = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('form')
const state = reactive<AuthFormState>({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  verification_code: ''
})
const showPassword = ref(false)
const showPasswordConfirmation = ref(false)
const codeSent = ref(false)
const codeSentTo = ref('')
const resendSeconds = ref(0)
let resendTimer: ReturnType<typeof setInterval> | null = null

const isRegistration = computed(() => props.mode === 'register')
const emailLooksValid = computed(() => /^\S+@\S+\.\S+$/.test(state.email.trim()))
const verificationCodeValid = computed(() => /^\d{6}$/.test(state.verification_code.trim()))

const stopResendTimer = () => {
  if (resendTimer) {
    clearInterval(resendTimer)
    resendTimer = null
  }
}

const startResendTimer = (seconds: number) => {
  stopResendTimer()
  resendSeconds.value = Math.max(0, seconds)
  resendTimer = setInterval(() => {
    resendSeconds.value = Math.max(0, resendSeconds.value - 1)
    if (resendSeconds.value === 0) stopResendTimer()
  }, 1000)
}

const requestVerificationCode = async () => {
  if (!isRegistration.value || !emailLooksValid.value || resendSeconds.value > 0) return

  const response = await auth.sendRegistrationCode(state.email)
  if (!response) return

  codeSent.value = true
  codeSentTo.value = state.email.trim().toLowerCase()
  state.verification_code = ''
  startResendTimer(response.resend_after ?? 60)
}

watch(() => state.email, (next, previous) => {
  if (!isRegistration.value || next === previous || !codeSent.value) return
  if (next.trim().toLowerCase() !== codeSentTo.value) {
    codeSent.value = false
    codeSentTo.value = ''
    state.verification_code = ''
    resendSeconds.value = 0
    stopResendTimer()
  }
})

const copy = computed(() => isRegistration.value
  ? {
      eyebrow: 'Create account',
      title: 'Start metered AI access',
      description: 'One workspace for prepaid credits, API keys and usage.',
      submit: 'Create account',
      alternateText: 'Already have an account?',
      alternateLabel: 'Sign in',
      alternateTo: '/login'
    }
  : {
      eyebrow: 'Sign in',
      title: 'Welcome back',
      description: 'Manage your credits, keys and usage.',
      submit: 'Sign in',
      alternateText: 'New to SP Cambo?',
      alternateLabel: 'Create an account',
      alternateTo: '/register'
    })

/**
 * Field-scoped API errors are attached to their inputs; anything else (bad
 * credentials, rate limiting, an unreachable control plane) is shown as a banner.
 */
const bannerError = computed(() => {
  if (!auth.errorMessage) {
    return null
  }

  return Object.keys(auth.fieldErrors).length > 0 ? null : auth.errorMessage
})

/**
 * A suspended account is the one sign-in failure the customer cannot act on.
 *
 * Every other banner here has something to try: correct the password, wait out the
 * rate limit, check the connection. This one does not — and it is the first wall a
 * suspended customer meets, before any page that could tell them anything. So the
 * banner carries the support channel, when the deployment publishes one.
 */
const suspended = computed(() => auth.errorCode === 'account_suspended')
const support = useSupportChannel()

const validate = (values: AuthFormState): FormError[] => {
  const errors: FormError[] = []

  if (isRegistration.value && !values.name.trim()) {
    errors.push({ name: 'name', message: 'Enter your name.' })
  }

  if (!values.email.trim()) {
    errors.push({ name: 'email', message: 'Enter your email address.' })
  } else if (!/^\S+@\S+\.\S+$/.test(values.email)) {
    errors.push({ name: 'email', message: 'Enter a valid email address.' })
  }

  if (!values.password) {
    errors.push({ name: 'password', message: 'Enter your password.' })
  } else if (isRegistration.value && values.password.length < 12) {
    errors.push({ name: 'password', message: 'Use at least 12 characters.' })
  }

  if (isRegistration.value && values.password !== values.password_confirmation) {
    errors.push({ name: 'password_confirmation', message: 'Passwords do not match.' })
  }

  if (isRegistration.value && !/^\d{6}$/.test(values.verification_code.trim())) {
    errors.push({ name: 'verification_code', message: 'Enter the 6-digit code sent to your email.' })
  }

  return errors
}

const submit = (event: FormSubmitEvent<AuthFormState>) => {
  emit('submit', event.data)
}

// Mirror the server's validation payload onto the matching inputs.
watch(() => auth.fieldErrors, (fieldErrors) => {
  const errors: FormError[] = Object.entries(fieldErrors as Record<string, string[]>)
    .map(([name, messages]) => ({ name, message: messages[0] ?? 'This value is not valid.' }))

  if (errors.length > 0) {
    form.value?.setErrors(errors)
  }
}, { deep: true })

onBeforeUnmount(() => {
  stopResendTimer()
  auth.resetErrors()
})
</script>

<template>
  <div class="w-full max-w-md">
    <UCard
      class="sp-auth-card"
      :ui="{ root: 'ring-default/80' }"
    >
      <template #header>
        <div class="space-y-3">
          <div class="sp-khmer-rule !h-px !w-14" />
          <p class="text-xs font-medium tracking-[0.16em] text-primary uppercase">
            {{ copy.eyebrow }}
          </p>
          <h1 class="text-2xl font-semibold tracking-tight text-highlighted">
            {{ copy.title }}
          </h1>
          <p class="text-sm text-muted">
            {{ copy.description }}
          </p>
        </div>
      </template>

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
          :title="suspended ? 'This account is suspended' : `We couldn't complete that request`"
          :description="bannerError"
          close
          @update:open="auth.resetErrors()"
        >
          <template
            v-if="suspended && support"
            #actions
          >
            <SpSupportLink label="Ask SP Cambo to review this account" />
          </template>
        </UAlert>
      </div>

      <UForm
        ref="form"
        :state="state"
        :validate="validate"
        class="space-y-5"
        @submit="submit"
      >
        <UFormField
          v-if="isRegistration"
          label="Name"
          name="name"
          required
        >
          <UInput
            v-model="state.name"
            class="w-full"
            icon="i-lucide-user"
            autocomplete="name"
            placeholder="Your name"
          />
        </UFormField>

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
            :ui="isRegistration ? { trailing: 'pe-1' } : undefined"
          >
            <template
              v-if="isRegistration"
              #trailing
            >
              <UButton
                type="button"
                color="primary"
                variant="soft"
                size="xs"
                :loading="auth.verificationPending"
                :disabled="!emailLooksValid || auth.verificationPending || resendSeconds > 0"
                @click.prevent="requestVerificationCode"
              >
                {{ resendSeconds > 0 ? `Resend ${resendSeconds}s` : (codeSent ? 'Resend code' : 'Get code') }}
              </UButton>
            </template>
          </UInput>
        </UFormField>

        <UFormField
          v-if="isRegistration"
          label="Email verification code"
          name="verification_code"
          required
        >
          <UInput
            v-model="state.verification_code"
            class="w-full"
            icon="i-lucide-badge-check"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            placeholder="6-digit code"
            :disabled="!codeSent"
            @input="state.verification_code = state.verification_code.replace(/\D/g, '').slice(0, 6)"
          />
          <template #help>
            <span class="text-xs text-muted">
              {{ codeSent ? `Code sent to ${codeSentTo}. It expires in 10 minutes.` : 'Enter your email and click Get code first.' }}
            </span>
          </template>
        </UFormField>

        <UFormField
          label="Password"
          name="password"
          required
        >
          <template #hint>
            <span
              v-if="isRegistration"
              class="text-xs text-muted"
            >At least 12 characters</span>
            <NuxtLink
              v-else
              to="/forgot-password"
              class="text-xs font-medium text-primary hover:underline"
            >
              Forgot password?
            </NuxtLink>
          </template>

          <UInput
            v-model="state.password"
            class="w-full"
            icon="i-lucide-lock-keyhole"
            :type="showPassword ? 'text' : 'password'"
            :autocomplete="isRegistration ? 'new-password' : 'current-password'"
            placeholder="Enter your password"
            :ui="{ trailing: 'pe-1' }"
          >
            <template #trailing>
              <UButton
                color="neutral"
                variant="link"
                size="sm"
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                @click="showPassword = !showPassword"
              />
            </template>
          </UInput>

          <template
            v-if="isRegistration"
            #help
          >
            <SpPasswordPolicy
              :value="state.password"
              level="basic"
            />
          </template>
        </UFormField>

        <UFormField
          v-if="isRegistration"
          label="Confirm password"
          name="password_confirmation"
          required
        >
          <UInput
            v-model="state.password_confirmation"
            class="w-full"
            icon="i-lucide-shield-check"
            :type="showPasswordConfirmation ? 'text' : 'password'"
            autocomplete="new-password"
            placeholder="Re-enter your password"
            :ui="{ trailing: 'pe-1' }"
          >
            <template #trailing>
              <UButton
                color="neutral"
                variant="link"
                size="sm"
                :icon="showPasswordConfirmation ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="showPasswordConfirmation ? 'Hide confirmation password' : 'Show confirmation password'"
                @click="showPasswordConfirmation = !showPasswordConfirmation"
              />
            </template>
          </UInput>
        </UFormField>

        <UButton
          type="submit"
          block
          size="lg"
          :loading="auth.pending"
          :disabled="auth.pending || (isRegistration && (!codeSent || !verificationCodeValid))"
        >
          {{ copy.submit }}
        </UButton>

        <p
          v-if="isRegistration"
          class="text-xs text-muted"
        >
          By creating an account you agree to the
          <NuxtLink
            to="/legal/terms"
            class="text-default underline decoration-dotted underline-offset-2"
          >terms of service</NuxtLink>
          and
          <NuxtLink
            to="/legal/acceptable-use"
            class="text-default underline decoration-dotted underline-offset-2"
          >acceptable use policy</NuxtLink>.
        </p>
      </UForm>

      <template #footer>
        <p class="text-center text-sm text-muted">
          {{ copy.alternateText }}
          <NuxtLink
            :to="copy.alternateTo"
            class="font-medium text-primary hover:underline"
          >
            {{ copy.alternateLabel }}
          </NuxtLink>
        </p>
      </template>
    </UCard>
  </div>
</template>
