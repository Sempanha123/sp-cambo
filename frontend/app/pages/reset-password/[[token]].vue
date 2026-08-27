<script setup lang="ts">
import type { FormError } from '@nuxt/ui'

definePageMeta({
  layout: 'auth',
  middleware: ['guest']
})

useSeoMeta({
  title: 'Choose a new password',
  description: 'Set a new password for your SP Cambo account.',
  robots: 'noindex'
})

const api = useSpApi()
const route = useRoute()
const toast = useToast()

/**
 * The reset token can arrive either way.
 *
 * Laravel's default notification builds `/reset-password/{token}?email=`, so the
 * path parameter is supported; a query parameter is accepted too in case the
 * link is rewritten. Neither the token nor the address is ever logged or echoed
 * into a new URL.
 */
const token = computed(() => {
  const fromPath = route.params.token

  if (typeof fromPath === 'string' && fromPath) {
    return fromPath
  }

  return typeof route.query.token === 'string' ? route.query.token : ''
})

const emailFromLink = computed(() => typeof route.query.email === 'string' ? route.query.email : '')

const state = reactive({
  email: emailFromLink.value,
  password: '',
  password_confirmation: ''
})

const showPassword = ref(false)
const submitting = ref(false)
const bannerError = ref<string | null>(null)
const form = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('form')

const linkIncomplete = computed(() => token.value === '')

const validate = (values: typeof state): FormError[] => {
  const errors: FormError[] = []

  if (!values.email.trim()) {
    errors.push({ name: 'email', message: 'Enter the email address the link was sent to.' })
  }

  if (!meetsPasswordPolicy(values.password)) {
    errors.push({ name: 'password', message: 'This password does not meet every requirement below.' })
  }

  if (values.password !== values.password_confirmation) {
    errors.push({ name: 'password_confirmation', message: 'Passwords do not match.' })
  }

  return errors
}

const submit = async () => {
  submitting.value = true
  bannerError.value = null

  try {
    await api.auth.resetPassword({
      token: token.value,
      email: state.email.trim(),
      password: state.password,
      password_confirmation: state.password_confirmation
    })

    toast.add({
      title: 'Password reset',
      description: 'Every session was signed out. Sign in with your new password.',
      icon: 'i-lucide-shield-check',
      color: 'success'
    })

    await navigateTo('/login')
  } catch (cause) {
    const error = toSpApiError(cause)

    // An invalid or expired token is reported on `email` by the control plane.
    form.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    if (!error.isValidation) {
      bannerError.value = error.message
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="w-full max-w-md">
    <UCard :ui="{ root: 'ring-default/80 shadow-xl shadow-black/5 dark:shadow-black/40' }" class="sp-app-card">
      <template #header>
        <div class="space-y-2">
          <p class="text-xs font-medium tracking-wide text-primary uppercase">
            Password reset
          </p>
          <h1 class="text-2xl font-semibold tracking-tight text-highlighted">
            Choose a new password
          </h1>
          <p class="text-sm text-muted">
            Setting a new password signs out every device, including this one.
          </p>
        </div>
      </template>

      <UAlert
        v-if="linkIncomplete"
        color="warning"
        variant="subtle"
        icon="i-lucide-link-2-off"
        title="This reset link is incomplete"
        description="It is missing its reset token, so a new password cannot be set from here. Request a fresh link and open it directly from the email."
        :actions="[{ label: 'Request a new link', to: '/forgot-password', color: 'neutral', variant: 'subtle' }]"
      />

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
            title="Your password was not reset"
            :description="bannerError"
            close
            @update:open="bannerError = null"
          />
        </div>

        <UForm
          ref="form"
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
              :readonly="emailFromLink !== ''"
              placeholder="you@example.com"
            />
          </UFormField>

          <UFormField
            label="New password"
            name="password"
            required
          >
            <UInput
              v-model="state.password"
              class="w-full"
              icon="i-lucide-key-round"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              placeholder="Choose a new password"
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

            <template #help>
              <SpPasswordPolicy :value="state.password" />
            </template>
          </UFormField>

          <UFormField
            label="Confirm new password"
            name="password_confirmation"
            required
          >
            <UInput
              v-model="state.password_confirmation"
              class="w-full"
              icon="i-lucide-shield-check"
              type="password"
              autocomplete="new-password"
              placeholder="Re-enter your new password"
            />
          </UFormField>

          <UButton
            type="submit"
            block
            size="lg"
            :loading="submitting"
            :disabled="submitting"
          >
            Set new password
          </UButton>
        </UForm>
      </template>

      <template #footer>
        <p class="text-center text-sm text-muted">
          <NuxtLink
            to="/login"
            class="font-medium text-primary hover:underline"
          >
            Back to sign in
          </NuxtLink>
        </p>
      </template>
    </UCard>
  </div>
</template>
