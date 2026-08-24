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
    { name: 'viewport', content: 'width=device-width, initial-scale=1' },
    { name: 'format-detection', content: 'telephone=no' }
  ],
  link: [
    { rel: 'icon', href: '/favicon.ico' }
  ]
})

useSeoMeta({
  description,
  ogSiteName: siteName,
  ogType: 'website',
  ogDescription: description,
  twitterCard: 'summary_large_image'
})

// The credential may be revoked or expire while the tab is open. The API layer
// records that, and the app reacts once, here, instead of in every page.
watch(() => auth.sessionExpiredAt, (expiredAt) => {
  if (!expiredAt || !auth.initialized) {
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
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
