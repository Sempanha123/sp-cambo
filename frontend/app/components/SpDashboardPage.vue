<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  description?: string
  icon?: string
  eyebrow?: string
}>(), {
  description: undefined,
  icon: undefined,
  eyebrow: undefined
})
</script>

<template>
  <UDashboardPanel
    class="sp-dashboard-page sp-r9-dashboard-page sp-r10-dashboard-page sp-r12-dashboard-page min-w-0 max-w-full overflow-hidden"
    :ui="{
      body: 'p-0 overflow-y-auto'
    }"
  >
    <template #header>
      <UDashboardNavbar
        :title="title"
        :icon="icon"
        class="sp-dashboard-navbar sp-r9-dashboard-navbar sp-r12-dashboard-navbar shrink-0"
      >
        <template
          v-if="$slots.actions"
          #right
        >
          <div class="sp-r9-navbar-actions flex min-w-0 flex-wrap items-center justify-end gap-2">
            <slot name="actions" />
          </div>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!--
        R10 owns the padding explicitly so every Dashboard/Admin/Reseller page
        gets the same breathing room instead of relying on page-specific spacing.
      -->
      <div class="sp-dashboard-content sp-r9-dashboard-content sp-r10-dashboard-content sp-r12-dashboard-content mx-auto w-full min-w-0 max-w-[1440px]">
        <section
          v-if="description || eyebrow"
          class="sp-dashboard-intro sp-page-lead sp-r12-page-lead min-w-0 overflow-hidden rounded-xl p-4 sm:p-5"
        >
          <div class="flex min-w-0 items-start gap-3">
            <div class="sp-page-lead-icon flex size-9 shrink-0 items-center justify-center rounded-lg border text-primary">
              <UIcon
                :name="icon || 'i-lucide-sparkles'"
                class="size-4"
              />
            </div>

            <div class="min-w-0">
              <p
                v-if="eyebrow"
                class="sp-page-eyebrow text-[10px] font-semibold tracking-[0.16em] text-primary uppercase"
              >
                {{ eyebrow }}
              </p>

              <p
                v-if="description"
                class="max-w-4xl text-sm leading-6 text-muted"
                :class="{ 'mt-1': eyebrow }"
              >
                {{ description }}
              </p>
            </div>
          </div>
        </section>

        <!--
          The flow wrapper gives direct page sections a consistent gap.
          This fixes pages where metrics/alerts/forms visually touched each other.
        -->
        <div class="sp-r10-page-flow min-w-0 max-w-full">
          <slot />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>

<style>
.sp-r10-dashboard-page {
  min-width: 0 !important;
  max-width: 100% !important;
}

.sp-r10-dashboard-content {
  box-sizing: border-box;
  min-width: 0;
  max-width: 100%;
  padding:
    1rem
    1rem
    max(2rem, env(safe-area-inset-bottom));
}

.sp-r10-page-flow {
  margin-top: 1rem;
}

/* Give sibling sections breathing room even if an individual page forgot it. */
.sp-r10-page-flow > :not([hidden]) + :not([hidden]) {
  margin-top: 1.25rem;
}

@media (min-width: 640px) {
  .sp-r10-dashboard-content {
    padding:
      1.25rem
      1.25rem
      max(2.5rem, env(safe-area-inset-bottom));
  }

  .sp-r10-page-flow {
    margin-top: 1.25rem;
  }

  .sp-r10-page-flow > :not([hidden]) + :not([hidden]) {
    margin-top: 1.5rem;
  }
}

@media (min-width: 1024px) {
  .sp-r10-dashboard-content {
    padding:
      1.5rem
      1.5rem
      max(3rem, env(safe-area-inset-bottom));
  }
}

@media (max-width: 639px) {
  .sp-r9-navbar-actions {
    gap: .35rem;
  }
}
</style>
