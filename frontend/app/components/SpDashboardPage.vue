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
  <UDashboardPanel class="sp-dashboard-page sp-r6-dashboard-page min-w-0 max-w-full overflow-hidden">
    <template #header>
      <UDashboardNavbar
        :title="title"
        :icon="icon"
        class="sp-dashboard-navbar shrink-0 border-b border-default/60"
      >
        <template
          v-if="$slots.actions"
          #right
        >
          <slot name="actions" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!--
        min-w-0 is essential inside a flex dashboard. Without it a wide child
        can force the entire panel beyond a phone viewport.
      -->
      <div class="sp-dashboard-content sp-r6-dashboard-content mx-auto w-full min-w-0 max-w-[1440px] space-y-7 pb-10">
        <section
          v-if="description || eyebrow"
          class="sp-dashboard-intro sp-page-lead min-w-0 overflow-hidden rounded-2xl p-4 sm:p-5"
        >
          <div class="flex min-w-0 items-start gap-3 sm:gap-4">
            <div class="sp-page-lead-icon flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
              <UIcon
                :name="icon || 'i-lucide-sparkles'"
                class="size-4.5"
              />
            </div>

            <div class="min-w-0">
              <p
                v-if="eyebrow"
                class="sp-page-eyebrow text-[11px] font-semibold tracking-[0.16em] text-primary uppercase"
              >
                {{ eyebrow }}
              </p>

              <p
                v-if="description"
                class="mt-1 max-w-4xl text-sm leading-6 text-muted"
              >
                {{ description }}
              </p>
            </div>
          </div>
        </section>

        <div class="min-w-0 max-w-full">
          <slot />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>

<style>
.sp-r6-dashboard-page {
  min-width: 0 !important;
  max-width: 100% !important;
}

.sp-r6-dashboard-content {
  min-width: 0;
  max-width: 100%;
}
</style>
