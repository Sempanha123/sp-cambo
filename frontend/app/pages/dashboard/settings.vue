<script setup lang="ts">
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Profile settings',
  description: 'Update your SP Cambo profile.',
  robots: 'noindex'
})

const auth = useAuthStore()
const api = useSpApi()
const toast = useToast()

const name = ref(auth.user?.name ?? '')
const saving = ref(false)
const errorMessage = ref<string | null>(null)

watch(() => auth.user?.name, (value) => {
  if (typeof value === 'string') {
    name.value = value
  }
})

const saveProfile = async () => {
  const normalized = name.value.trim()
  errorMessage.value = null

  if (!normalized) {
    errorMessage.value = 'Enter your name.'
    return
  }

  saving.value = true
  try {
    const result = await api.account.updateProfile({ name: normalized })
    auth.setUser(result.user)
    name.value = result.user.name
    toast.add({ title: 'Profile updated', color: 'success', icon: 'i-lucide-circle-check' })
  } catch (error) {
    errorMessage.value = toSpApiError(error).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Profile settings"
    icon="i-lucide-settings"
    description="Keep your customer profile information up to date. Sign-in methods and active sessions live under Account & security."
  >
    <UAlert
      v-if="errorMessage"
      role="alert"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Could not update profile"
      :description="errorMessage"
      close
      @update:open="errorMessage = null"
    />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
      <UCard class="sp-app-card">
        <template #header>
          <div>
            <h2 class="font-semibold text-highlighted">
              Profile
            </h2>
            <p class="mt-1 text-sm text-muted">
              Your email is the sign-in identity and cannot be changed from this page.
            </p>
          </div>
        </template>

        <form
          class="space-y-5"
          @submit.prevent="saveProfile"
        >
          <UFormField
            label="Display name"
            required
          >
            <UInput
              v-model="name"
              autocomplete="name"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Email">
            <UInput
              :model-value="auth.user?.email ?? ''"
              type="email"
              disabled
              class="w-full"
            />
          </UFormField>

          <UButton
            type="submit"
            :loading="saving"
            icon="i-lucide-check"
          >
            Save profile
          </UButton>
        </form>
      </UCard>

      <UCard class="sp-app-card">
        <template #header>
          <h2 class="font-semibold text-highlighted">
            Security
          </h2>
        </template>

        <div class="space-y-3 text-sm text-muted">
          <p>
            Google linking, password changes and session management are grouped on one consistent dashboard page.
          </p>
          <UButton
            to="/dashboard/account"
            color="neutral"
            variant="subtle"
            icon="i-lucide-shield-check"
            block
          >
            Account & security
          </UButton>
        </div>
      </UCard>
    </div>
  </SpDashboardPage>
</template>
