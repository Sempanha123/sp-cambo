<script setup lang="ts">
const { publicLinks, footerColumns } = useSiteNavigation()
const auth = useAuthStore()
const year = new Date().getFullYear()
</script>

<template>
  <div class="sp-r4-layout sp-r4-public-layout sp-shell-aurora flex min-h-screen flex-col">
    <div class="sp-r4-layout-mesh" aria-hidden="true" />
    <div class="sp-r4-corner-orb sp-r4-corner-orb--a" aria-hidden="true" />
    <div class="sp-r4-corner-orb sp-r4-corner-orb--b" aria-hidden="true" />

    <UHeader
      to="/"
      :ui="{ root: 'sp-site-header sp-r4-header' }"
    >
      <template #title>
        <span class="inline-flex items-center gap-2.5">
          <SpLogo />
          <span class="sp-khmer-chip hidden xl:inline-flex">កម្ពុជា</span>
        </span>
      </template>

      <UNavigationMenu
        :items="publicLinks"
        variant="link"
      />

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
          color="neutral"
          variant="subtle"
          trailing-icon="i-lucide-arrow-right"
          class="hidden sm:inline-flex"
        >
          Dashboard
        </UButton>

        <template v-else>
          <UButton
            to="/login"
            color="neutral"
            variant="ghost"
            class="hidden sm:inline-flex"
          >
            Sign in
          </UButton>
          <UButton
            to="/register"
            class="hidden sm:inline-flex"
          >
            Create account
          </UButton>
        </template>
      </template>

      <template #body>
        <div class="sp-r4-mobile-menu space-y-6">
          <UNavigationMenu
            :items="publicLinks"
            orientation="vertical"
            class="-mx-2.5"
          />

          <div class="grid gap-2 sm:grid-cols-2">
            <UButton
              to="/public/key-checker"
              block
              color="neutral"
              variant="subtle"
              icon="i-lucide-key-round"
            >
              Check API key
            </UButton>
            <UButton
              v-if="auth.authenticated"
              to="/dashboard"
              block
              trailing-icon="i-lucide-arrow-right"
            >
              Open dashboard
            </UButton>
            <template v-else>
              <UButton
                to="/register"
                block
              >
                Create account
              </UButton>
              <UButton
                to="/login"
                block
                color="neutral"
                variant="ghost"
                class="sm:col-span-2"
              >
                Sign in
              </UButton>
            </template>
          </div>
        </div>
      </template>
    </UHeader>

    <UMain class="sp-r4-main relative z-[1] flex-1">
      <slot />
    </UMain>

    <UFooter :ui="{ root: 'sp-r4-footer border-t border-default/80 mt-16 bg-default/45' }">
      <template #top>
        <UContainer class="py-10 lg:py-14">
          <div class="grid gap-10 lg:grid-cols-5">
            <div class="space-y-4 lg:col-span-1">
              <div class="flex items-center gap-2.5">
                <SpLogo />
                <span class="sp-khmer-chip">កម្ពុជា</span>
              </div>
              <p class="max-w-xs text-sm text-muted">
                Prepaid, metered access to managed AI models for CLI, SDK and API workloads.
              </p>
              <p
                class="font-medium text-xs text-dimmed"
                style="font-family: 'Noto Sans Khmer', 'Khmer OS System', sans-serif;"
              >
                បច្ចេកវិទ្យា AI សម្រាប់កម្ពុជា
              </p>
            </div>

            <UFooterColumns
              :columns="footerColumns"
              class="lg:col-span-4"
            />
          </div>
        </UContainer>
      </template>

      <template #left>
        <p class="text-sm text-muted">
          © {{ year }} SP Cambo
        </p>
      </template>

      <template #right>
        <div class="flex flex-wrap items-center justify-end gap-4">
          <NuxtLink
            to="/public/key-checker"
            class="text-sm text-muted transition-colors hover:text-default"
          >
            API key checker
          </NuxtLink>
          <NuxtLink
            to="/status"
            class="text-sm text-muted transition-colors hover:text-default"
          >
            Service status
          </NuxtLink>
          <NuxtLink
            to="/docs"
            class="text-sm text-muted transition-colors hover:text-default"
          >
            Documentation
          </NuxtLink>
        </div>
      </template>
    </UFooter>
  </div>
</template>
