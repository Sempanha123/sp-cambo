<script setup lang="ts">
const { publicLinks, footerColumns } = useSiteNavigation()
const auth = useAuthStore()
const year = new Date().getFullYear()
</script>

<template>
  <div class="sp-r4-layout sp-r4-public-layout sp-r12-layout sp-r12-public-layout sp-shell-aurora flex min-h-screen flex-col">
    <div
      class="sp-r4-layout-mesh"
      aria-hidden="true"
    />
    <div
      class="sp-r4-corner-orb sp-r4-corner-orb--a"
      aria-hidden="true"
    />
    <div
      class="sp-r4-corner-orb sp-r4-corner-orb--b"
      aria-hidden="true"
    />

    <UHeader
      to="/"
      :ui="{ root: 'sp-site-header sp-r4-header sp-r12-header' }"
    >
      <template #title>
        <span class="inline-flex items-center gap-2.5">
          <SpLogo />
          <span class="sp-khmer-chip hidden min-[1760px]:inline-flex">កម្ពុជា</span>
        </span>
      </template>

      <UNavigationMenu
        :items="publicLinks"
        variant="link"
      />

      <template #right>
        <!-- Compact desktop header actions: plain NuxtLink avoids global UButton
             sizing/wrapping while keeping the rest of the V4 button theme intact. -->
        <div class="flex shrink-0 items-center gap-1.5">
          <NuxtLink
            to="/public/key-checker"
            class="hidden h-8 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-cyan-500/20 bg-cyan-500/[0.07] px-2.5 text-xs font-semibold leading-none text-cyan-700 shadow-[0_0_16px_rgba(34,211,238,.05)] transition-all duration-200 hover:-translate-y-px hover:border-cyan-400/35 hover:bg-cyan-500/[0.12] hover:text-cyan-600 dark:text-cyan-200 dark:hover:text-cyan-100 min-[1480px]:inline-flex"
          >
            <UIcon name="i-lucide-key-round" class="size-3.5 shrink-0" />
            <span class="whitespace-nowrap">Check API key</span>
          </NuxtLink>

          <UColorModeButton
            size="xs"
            color="neutral"
            variant="ghost"
            class="size-8 shrink-0 p-0"
          />

          <NuxtLink
            v-if="auth.authenticated"
            to="/dashboard"
            class="hidden h-8 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-sky-400/25 bg-gradient-to-r from-sky-500 to-cyan-500 px-3 text-xs font-semibold leading-none text-white shadow-[0_7px_18px_-12px_rgba(14,165,233,.85),0_0_14px_rgba(34,211,238,.10)] transition-all duration-200 hover:-translate-y-px hover:from-sky-400 hover:to-cyan-400 hover:shadow-[0_9px_22px_-12px_rgba(14,165,233,.95),0_0_18px_rgba(34,211,238,.16)] sm:inline-flex"
          >
            <span class="whitespace-nowrap">Dashboard</span>
            <UIcon name="i-lucide-arrow-right" class="size-3.5 shrink-0" />
          </NuxtLink>

          <template v-else>
            <NuxtLink
              to="/login"
              class="hidden h-8 shrink-0 items-center whitespace-nowrap rounded-lg border border-violet-400/15 bg-violet-500/[0.06] px-2.5 text-xs font-semibold leading-none text-violet-700 transition-all duration-200 hover:-translate-y-px hover:border-violet-400/30 hover:bg-violet-500/[0.11] hover:text-violet-600 dark:text-violet-200 dark:hover:text-violet-100 sm:inline-flex"
            >
              <span class="whitespace-nowrap">Sign in</span>
            </NuxtLink>

            <NuxtLink
              to="/register"
              class="hidden h-8 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-sky-300/25 bg-gradient-to-r from-sky-500 via-blue-500 to-violet-500 px-3 text-xs font-semibold leading-none text-white shadow-[0_7px_18px_-12px_rgba(59,130,246,.85),0_0_16px_rgba(139,92,246,.10)] transition-all duration-200 hover:-translate-y-px hover:from-sky-400 hover:via-blue-400 hover:to-violet-400 hover:shadow-[0_9px_22px_-12px_rgba(59,130,246,.95),0_0_20px_rgba(139,92,246,.16)] sm:inline-flex"
            >
              <span class="whitespace-nowrap">Create account</span>
              <UIcon name="i-lucide-arrow-up-right" class="size-3.5 shrink-0" />
            </NuxtLink>
          </template>
        </div>
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

    <UFooter :ui="{ root: 'sp-r4-footer sp-r12-footer border-t border-default/80 mt-16 bg-default/45' }">
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
                style="font-family: 'Noto Sans Khmer Variable', 'Noto Sans Khmer', 'Khmer OS System', sans-serif;"
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
