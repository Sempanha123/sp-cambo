<script setup lang="ts">
import type { ExternalIdentity } from '~/types/commerce'
import type { SessionSummary } from '~/types/api'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Account & security',
  description: 'Manage sign-in methods, password and active SP Cambo sessions.',
  robots: 'noindex'
})

const api = useSpApi()
const auth = useAuthStore()
const cookieMode = useCookieSessionMode()
const route = useRoute()
const toast = useToast()

const identities = ref<ExternalIdentity[]>([])
const sessions = ref<SessionSummary[]>([])
const identitiesLoading = ref(true)
const sessionsLoading = ref(true)
const actionBusy = ref(false)
const passwordBusy = ref(false)
const pageError = ref<string | null>(typeof route.query.google_error === 'string' ? route.query.google_error : null)
const identitiesError = ref<string | null>(null)
const sessionsError = ref<string | null>(null)

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: ''
})

const googleIdentity = computed(() => identities.value.find(identity => identity.provider === 'google') ?? null)

const fetchIdentities = async () => {
  identitiesLoading.value = true
  identitiesError.value = null
  try {
    identities.value = await api.account.identities()
  } catch (err) {
    identitiesError.value = toSpApiError(err).message
  } finally {
    identitiesLoading.value = false
  }
}

const fetchSessions = async () => {
  sessionsLoading.value = true
  sessionsError.value = null
  try {
    sessions.value = await api.account.sessions()
  } catch (err) {
    sessionsError.value = toSpApiError(err).message
  } finally {
    sessionsLoading.value = false
  }
}

const unlinkIdentity = async (identity: ExternalIdentity) => {
  const label = identity.email ?? identity.name ?? identity.provider
  if (!confirm(`Unlink ${identity.provider} account (${label})? Make sure you can sign in with your email/password before continuing.`)) {
    return
  }

  actionBusy.value = true
  try {
    await api.account.unlinkIdentity(identity.id)
    await fetchIdentities()
    toast.add({ title: 'Google account unlinked', color: 'success', icon: 'i-lucide-circle-check' })
  } catch (err) {
    toast.add({
      title: 'Could not unlink account',
      description: toSpApiError(err).message,
      color: 'error',
      icon: 'i-lucide-circle-alert'
    })
  } finally {
    actionBusy.value = false
  }
}

const changePassword = async () => {
  pageError.value = null

  if (passwordForm.password.length < 12) {
    pageError.value = 'New password must contain at least 12 characters.'
    return
  }

  if (passwordForm.password !== passwordForm.password_confirmation) {
    pageError.value = 'New password and confirmation do not match.'
    return
  }

  passwordBusy.value = true
  try {
    await api.account.changePassword({ ...passwordForm })
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    await fetchSessions()
    toast.add({
      title: 'Password updated',
      description: 'Other bearer sessions were revoked for your security.',
      color: 'success',
      icon: 'i-lucide-circle-check'
    })
  } catch (err) {
    pageError.value = toSpApiError(err).message
  } finally {
    passwordBusy.value = false
  }
}

const revokeSession = async (session: SessionSummary) => {
  if (session.current || !confirm(`Revoke session “${session.name}”?`)) {
    return
  }

  actionBusy.value = true
  try {
    await api.account.revokeSession(session.id)
    await fetchSessions()
    toast.add({ title: 'Session revoked', color: 'success', icon: 'i-lucide-circle-check' })
  } catch (err) {
    toast.add({
      title: 'Could not revoke session',
      description: toSpApiError(err).message,
      color: 'error',
      icon: 'i-lucide-circle-alert'
    })
  } finally {
    actionBusy.value = false
  }
}

onMounted(async () => {
  await Promise.all([fetchIdentities(), fetchSessions()])
})
</script>

<template>
  <SpDashboardPage
    title="Account & security"
    icon="i-lucide-shield-check"
    description="Manage your sign-in methods and active sessions without leaving the dashboard shell."
  >
    <UAlert
      v-if="pageError"
      role="alert"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Account action could not be completed"
      :description="pageError"
      close
      @update:open="pageError = null"
    />

    <div class="grid gap-6 xl:grid-cols-2">
      <UCard class="sp-app-card">
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 class="font-semibold text-highlighted">
                Sign-in methods
              </h2>
              <p class="mt-1 text-sm text-muted">
                Connect Google for faster sign-in to the same SP Cambo account.
              </p>
            </div>
            <GoogleLoginButton
              v-if="!googleIdentity && !identitiesLoading"
              intent="link"
              redirect-to="/dashboard/account"
            />
          </div>
        </template>

        <div
          v-if="identitiesLoading"
          class="space-y-3 py-2"
        >
          <USkeleton class="h-16 w-full" />
        </div>

        <UAlert
          v-else-if="identitiesError"
          role="alert"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Sign-in methods are temporarily unavailable"
          :description="identitiesError"
          :actions="[{ label: 'Try again', color: 'neutral', variant: 'subtle', onClick: fetchIdentities }]"
        />

        <div
          v-else-if="googleIdentity"
          class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="flex min-w-0 items-center gap-3">
            <img
              v-if="googleIdentity.avatar_url"
              :src="googleIdentity.avatar_url"
              alt=""
              class="size-11 shrink-0 rounded-full object-cover"
              referrerpolicy="no-referrer"
            >
            <div
              v-else
              class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary/10"
            >
              <UIcon
                name="i-lucide-user"
                class="size-5 text-primary"
              />
            </div>
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <p class="font-medium text-highlighted">
                  Google
                </p>
                <UBadge
                  color="success"
                  variant="subtle"
                  size="sm"
                >
                  Linked
                </UBadge>
              </div>
              <p class="truncate text-sm text-muted">
                {{ googleIdentity.email ?? googleIdentity.name ?? 'Google account' }}
              </p>
              <p class="text-xs text-dimmed">
                Linked {{ formatDate(googleIdentity.created_at) }}
              </p>
            </div>
          </div>

          <UButton
            color="error"
            variant="subtle"
            size="sm"
            icon="i-lucide-unlink"
            :loading="actionBusy"
            @click="unlinkIdentity(googleIdentity)"
          >
            Unlink
          </UButton>
        </div>

        <div
          v-else
          class="rounded-lg border border-dashed border-default p-5 text-center"
        >
          <UIcon
            name="i-lucide-link"
            class="mx-auto mb-2 size-5 text-muted"
          />
          <p class="text-sm font-medium text-highlighted">
            No Google account linked
          </p>
          <p class="mt-1 text-xs text-muted">
            Your email/password sign-in continues to work normally.
          </p>
        </div>
      </UCard>

      <UCard class="sp-app-card">
        <template #header>
          <div>
            <h2 class="font-semibold text-highlighted">
              Account identity
            </h2>
            <p class="mt-1 text-sm text-muted">
              This is the account currently authenticated in the dashboard.
            </p>
          </div>
        </template>

        <dl class="grid gap-4 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted">
              Name
            </dt>
            <dd class="mt-1 font-medium text-highlighted">
              {{ auth.user?.name ?? '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Email
            </dt>
            <dd class="mt-1 break-all font-medium text-highlighted">
              {{ auth.user?.email ?? '—' }}
            </dd>
          </div>
        </dl>

        <template #footer>
          <UButton
            to="/dashboard/settings"
            color="neutral"
            variant="subtle"
            icon="i-lucide-settings"
          >
            Edit profile settings
          </UButton>
        </template>
      </UCard>
    </div>

    <UCard class="sp-app-card">
      <template #header>
        <div>
          <h2 class="font-semibold text-highlighted">
            Change password
          </h2>
          <p class="mt-1 text-sm text-muted">
            Google-only accounts can use “Forgot password” from the sign-in page to establish a password first.
          </p>
        </div>
      </template>

      <form
        class="grid gap-4 lg:grid-cols-3"
        @submit.prevent="changePassword"
      >
        <UFormField
          label="Current password"
          required
        >
          <UInput
            v-model="passwordForm.current_password"
            type="password"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="New password"
          hint="12+ chars, mixed case, number, symbol"
          required
        >
          <UInput
            v-model="passwordForm.password"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Confirm new password"
          required
        >
          <UInput
            v-model="passwordForm.password_confirmation"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <div class="lg:col-span-3">
          <UButton
            type="submit"
            :loading="passwordBusy"
            icon="i-lucide-key-round"
          >
            Update password
          </UButton>
        </div>
      </form>
    </UCard>

    <UCard class="sp-app-card">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold text-highlighted">
              Active sessions
            </h2>
            <p class="mt-1 text-sm text-muted">
              Revoke bearer sessions you no longer recognize.
            </p>
          </div>
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="sessionsLoading"
            @click="fetchSessions"
          >
            Refresh
          </UButton>
        </div>
      </template>

      <div
        v-if="sessionsLoading"
        class="space-y-2"
      >
        <USkeleton class="h-14 w-full" />
        <USkeleton class="h-14 w-full" />
      </div>

      <UAlert
        v-else-if="sessionsError"
        role="alert"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        title="Active sessions are temporarily unavailable"
        :description="sessionsError"
        :actions="[{ label: 'Try again', color: 'neutral', variant: 'subtle', onClick: fetchSessions }]"
      />

      <div
        v-else-if="sessions.length === 0"
        class="py-4 text-sm text-muted"
      >
        {{ cookieMode
          ? 'This browser uses a secure cookie session. No additional API sessions are active.'
          : 'No additional bearer sessions are active for this account.' }}
      </div>

      <ul
        v-else
        class="divide-y divide-default"
      >
        <li
          v-for="session in sessions"
          :key="session.id"
          class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <p class="truncate text-sm font-medium text-highlighted">
                {{ session.name }}
              </p>
              <UBadge
                v-if="session.current"
                color="success"
                variant="subtle"
                size="sm"
              >
                Current
              </UBadge>
            </div>
            <p class="mt-1 text-xs text-muted">
              Created {{ formatDateTime(session.created_at) }}
              <span v-if="session.last_used_at"> · Last used {{ formatDateTime(session.last_used_at) }}</span>
            </p>
          </div>
          <UButton
            color="error"
            variant="subtle"
            size="sm"
            icon="i-lucide-log-out"
            :disabled="session.current"
            :loading="actionBusy"
            @click="revokeSession(session)"
          >
            {{ session.current ? 'Current session' : 'Revoke' }}
          </UButton>
        </li>
      </ul>
    </UCard>
  </SpDashboardPage>
</template>
