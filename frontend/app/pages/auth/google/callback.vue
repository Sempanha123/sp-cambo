<script setup lang="ts">
definePageMeta({
  layout: 'auth'
})

useSeoMeta({
  title: 'Google sign-in',
  robots: 'noindex'
})

const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const api = useSpApi()
const router = useRouter()

const safeStoredRedirect = (): string => {
  const stored = sessionStorage.getItem('google_redirect_to')

  if (!stored) {
    return '/dashboard'
  }

  try {
    const parsed = JSON.parse(stored)
    if (typeof parsed === 'string' && /^\/(?!\/)/.test(parsed)) {
      return parsed
    }

    if (parsed && typeof parsed === 'object' && typeof parsed.path === 'string' && /^\/(?!\/)/.test(parsed.path)) {
      return parsed.path
    }
  } catch {
    // Ignore malformed stale browser state and use the safe default.
  }

  return '/dashboard'
}

onMounted(async () => {
  const intent = sessionStorage.getItem('google_auth_intent') === 'link' ? 'link' : 'login'
  const destination = safeStoredRedirect()

  try {
    if (route.query.error) {
      const providerError = typeof route.query.error_description === 'string'
        ? route.query.error_description
        : (typeof route.query.error === 'string' ? route.query.error : 'Google authentication failed')
      throw new Error(providerError)
    }

    const code = typeof route.query.code === 'string' ? route.query.code : ''
    const state = typeof route.query.state === 'string' ? route.query.state : ''

    if (!code || !state) {
      throw new Error('Google did not return the authorization details required to finish sign-in.')
    }

    if (intent === 'link') {
      if (!auth.authenticated) {
        throw new Error('Your SP Cambo session ended before Google account linking completed. Sign in and try again.')
      }

      await api.google.link({ code, state })
      toast.add({ title: 'Google account linked', color: 'success', icon: 'i-lucide-circle-check' })
      await router.replace(destination.startsWith('/dashboard') ? destination : '/dashboard/account')
      return
    }

    const response = await api.google.callback({ code, state })
    auth.applySession(response)

    toast.add({ title: 'Signed in with Google', color: 'success', icon: 'i-lucide-circle-check' })
    await router.replace(destination)
  } catch (error) {
    const message = toSpApiError(error).message
    toast.add({
      title: intent === 'link' ? 'Google account linking failed' : 'Google login failed',
      description: message,
      color: 'error',
      icon: 'i-lucide-circle-alert'
    })

    if (intent === 'link' && auth.authenticated) {
      await router.replace({ path: '/dashboard/account', query: { google_error: message } })
    } else {
      await router.replace({ path: '/login', query: { google_error: message } })
    }
  } finally {
    sessionStorage.removeItem('google_auth_intent')
    sessionStorage.removeItem('google_redirect_to')
  }
})
</script>

<template>
  <div class="w-full max-w-md">
    <UCard :ui="{ root: 'ring-default/80 shadow-xl shadow-black/5 dark:shadow-black/40' }">
      <template #header>
        <div class="space-y-2">
          <p class="text-xs font-medium tracking-wide text-primary uppercase">
            Google sign-in
          </p>
          <h1 class="text-2xl font-semibold tracking-tight text-highlighted">
            Completing sign-in
          </h1>
          <p class="text-sm text-muted">
            Verifying your Google account and returning you to SP Cambo.
          </p>
        </div>
      </template>

      <div aria-live="polite" role="status" class="space-y-3 py-6 text-center">
        <UIcon name="i-lucide-loader-circle" class="mx-auto size-6 animate-spin text-primary" />
        <p class="text-sm text-muted">
          Please wait…
        </p>
      </div>
    </UCard>
  </div>
</template>
