<script setup lang="ts">
import { spCamboLocale } from '~/utils/uiLocale'

const auth = useAuthStore()
const route = useRoute()
const toast = useToast()
const config = useRuntimeConfig()

const siteName = 'SP Cambo'
const description = 'Prepaid, metered access to managed AI models for CLI, SDK and API workloads.'

useHead({
  titleTemplate: title => title ? `${title} · ${siteName}` : siteName,
  htmlAttrs: { lang: 'en' },
  meta: [
    {
      name: 'viewport',
      content: 'width=device-width, initial-scale=1, viewport-fit=cover'
    },
    { name: 'format-detection', content: 'telephone=no' }
  ],
  link: [
    {
      rel: 'icon',
      type: 'image/png',
      sizes: '512x512',
      href: '/brand/sp-cambo-logo.png'
    },
    {
      rel: 'apple-touch-icon',
      sizes: '192x192',
      href: '/brand/sp-cambo-logo-192.png'
    }
  ]
})

useSeoMeta({
  description,
  ogSiteName: siteName,
  ogType: 'website',
  ogDescription: description,
  twitterCard: 'summary_large_image'
})

/**
 * React to a rejected credential only while that exact expiry signal is still
 * current. A Google callback can replace an expired pre-OAuth session with a new
 * bearer token; a queued watcher from the old token must never clear the fresh one.
 */
watch(() => auth.sessionExpiredAt, (expiredAt) => {
  if (!expiredAt || !auth.initialized) {
    return
  }

  // applySession() clears the signal before publishing a fresh login. Ignore a
  // watcher callback that was queued for an older value.
  if (auth.sessionExpiredAt !== expiredAt) {
    return
  }

  // An expired pre-existing credential is normal while completing a full-page
  // OAuth round trip. The callback page owns success/failure presentation and will
  // install the newly-issued session, so do not race it or show a false warning.
  if (route.path === '/auth/google/callback') {
    return
  }

  auth.handleSessionExpired()

  toast.add({
    title: 'Your session ended',
    description: 'Sign in again to continue.',
    icon: 'i-lucide-log-out',
    color: 'warning'
  })

  const privatePrefixes = ['/dashboard', '/admin', '/reseller']

  if (privatePrefixes.some(prefix => route.path.startsWith(prefix))) {
    navigateTo({ path: '/login', query: { redirect: route.fullPath } })
  }
})

if (import.meta.server) {
  useHead({ link: [{ rel: 'canonical', href: new URL(route.path, config.public.siteUrl).toString() }] })
}

await auth.initialize()
</script>

<template>
  <UApp :locale="spCamboLocale">
    <NuxtLoadingIndicator color="var(--ui-primary)" />

    <NuxtLayout>
      <NuxtPage :keepalive="{ max: 8 }" />
    </NuxtLayout>
  </UApp>
</template>
