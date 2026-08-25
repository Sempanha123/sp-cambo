<script setup lang="ts">
const { publicLinks } = useSiteNavigation()
const auth = useAuthStore()
const year = new Date().getFullYear()
</script>

<template>
  <div class="sp-shell-aurora flex min-h-screen flex-col">
    <UHeader
      to="/"
      :ui="{ root: 'sp-site-header' }"
    >
      <template #title>
        <span class="inline-flex items-center gap-2.5">
          <SpLogo />
          <span class="sp-khmer-chip hidden xl:inline-flex">កម្ពុជា</span>
        </span>
      </template>

      <UNavigationMenu :items="publicLinks" variant="link" />

      <template #right>
        <UButton
          to="/public/key-checker"
          color="neutral"
          variant="subtle"
          icon="i-lucide-key-round"
          class="hidden lg:inline-flex"
        >
          Check API key
        </UButton>
        <UColorModeButton />
        <UButton
          v-if="auth.authenticated"
          to="/dashboard"
          trailing-icon="i-lucide-arrow-right"
          class="hidden sm:inline-flex"
        >
          Dashboard
        </UButton>
        <template v-else>
          <UButton to="/login" color="neutral" variant="ghost" class="hidden sm:inline-flex">Sign in</UButton>
          <UButton to="/register" class="hidden sm:inline-flex">Create account</UButton>
        </template>
      </template>

      <template #body>
        <div class="space-y-5">
          <UNavigationMenu :items="publicLinks" orientation="vertical" class="-mx-2.5" />
          <UButton to="/public/key-checker" block color="neutral" variant="subtle" icon="i-lucide-key-round">
            Check API key
          </UButton>
          <UButton v-if="auth.authenticated" to="/dashboard" block>Open dashboard</UButton>
          <template v-else>
            <UButton to="/register" block>Create account</UButton>
            <UButton to="/login" block color="neutral" variant="ghost">Sign in</UButton>
          </template>
        </div>
      </template>
    </UHeader>

    <main class="flex-1">
      <slot />
    </main>

    <footer class="mt-16 border-t border-default/80 bg-default/45">
      <UContainer class="flex flex-col gap-4 py-8 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
          <SpLogo />
          <span class="sp-khmer-chip">កម្ពុជា</span>
        </div>
        <div class="flex flex-wrap items-center gap-5">
          <NuxtLink to="/public/key-checker" class="transition-colors hover:text-default">API key checker</NuxtLink>
          <NuxtLink to="/docs" class="transition-colors hover:text-default">Docs</NuxtLink>
          <NuxtLink to="/status" class="transition-colors hover:text-default">Status</NuxtLink>
          <span>© {{ year }} SP Cambo</span>
        </div>
      </UContainer>
    </footer>
  </div>
</template>
