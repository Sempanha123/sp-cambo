<script setup lang="ts">
/** Focused shell for sign-in, registration and recovery flows. */
</script>

<template>
  <div class="sp-auth-shell flex min-h-svh flex-col">
    <header class="relative z-10 flex items-center justify-between px-4 py-5 sm:px-8 lg:px-10">
      <NuxtLink
        to="/"
        class="inline-flex items-center gap-2.5"
        aria-label="SP Cambo home"
      >
        <SpLogo />
        <span class="sp-khmer-chip hidden sm:inline-flex">កម្ពុជា</span>
      </NuxtLink>

      <div class="flex items-center gap-1">
        <UColorModeButton />
        <UButton
          to="/docs"
          color="neutral"
          variant="ghost"
          size="sm"
          class="hidden sm:inline-flex"
          icon="i-lucide-book-open"
        >
          Documentation
        </UButton>
      </div>
    </header>

    <div class="mx-auto grid w-full max-w-7xl flex-1 items-stretch gap-8 px-4 pb-8 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-10 lg:pb-10">
      <aside class="sp-auth-showcase relative hidden min-h-[38rem] rounded-2xl p-8 lg:flex lg:flex-col lg:justify-between xl:p-10">
        <div class="sp-khmer-motif pointer-events-none absolute inset-0 opacity-[0.18]" aria-hidden="true" />
        <div class="sp-ambient-glow pointer-events-none absolute inset-0" aria-hidden="true" />

        <div class="relative z-10 max-w-xl space-y-6">
          <div class="flex items-center gap-3">
            <UBadge color="neutral" variant="subtle" size="lg" class="rounded-full">
              SP Cambo · Cambodia
            </UBadge>
            <span class="sp-khmer-chip">AI សម្រាប់កម្ពុជា</span>
          </div>

          <div class="sp-khmer-rule" />

          <div class="space-y-4">
            <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance xl:text-5xl">
              One secure gateway for the AI tools you already use.
            </h1>
            <p class="max-w-lg text-base leading-7 text-muted">
              Buy timed packages, receive your own SP Cambo API key, and connect Claude Code,
              Codex or compatible apps without exposing upstream provider credentials.
            </p>
            <p class="text-sm font-medium text-default" style="font-family: 'Noto Sans Khmer', 'Khmer OS System', sans-serif;">
              បច្ចេកវិទ្យា AI សម្រាប់កម្ពុជា · សាមញ្ញ សុវត្ថិភាព និងងាយគ្រប់គ្រង
            </p>
          </div>
        </div>

        <div class="relative z-10 grid gap-3 sm:grid-cols-3">
          <div class="rounded-xl border border-default/80 bg-default/55 p-4 backdrop-blur-sm">
            <UIcon name="i-lucide-wallet" class="mb-3 size-5 text-primary" />
            <p class="text-sm font-medium text-highlighted">Prepaid control</p>
            <p class="mt-1 text-xs text-muted">Know the package price and expiry before you use it.</p>
          </div>
          <div class="rounded-xl border border-default/80 bg-default/55 p-4 backdrop-blur-sm">
            <UIcon name="i-lucide-shield-check" class="mb-3 size-5 text-primary" />
            <p class="text-sm font-medium text-highlighted">Private routing</p>
            <p class="mt-1 text-xs text-muted">Provider secrets and private model routes stay server-side.</p>
          </div>
          <div class="rounded-xl border border-default/80 bg-default/55 p-4 backdrop-blur-sm">
            <UIcon name="i-lucide-chart-line" class="mb-3 size-5 text-primary" />
            <p class="text-sm font-medium text-highlighted">Live usage</p>
            <p class="mt-1 text-xs text-muted">Track tokens, expiry and spend from one dashboard.</p>
          </div>
        </div>
      </aside>

      <main class="flex items-center justify-center py-4 lg:py-8">
        <slot />
      </main>
    </div>

    <footer class="px-4 py-6 sm:px-8 lg:px-10">
      <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 text-xs text-muted sm:flex-row">
        <p>Managed AI access with prepaid, metered billing.</p>
        <div class="flex items-center gap-4">
          <NuxtLink to="/legal/terms" class="transition-colors hover:text-default">Terms</NuxtLink>
          <NuxtLink to="/legal/privacy" class="transition-colors hover:text-default">Privacy</NuxtLink>
          <NuxtLink to="/status" class="transition-colors hover:text-default">Status</NuxtLink>
        </div>
      </div>
    </footer>
  </div>
</template>
