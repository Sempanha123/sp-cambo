<script setup lang="ts">
/**
 * Consistent dashboard page frame: navbar with title/actions plus a padded body.
 * Keeps every authenticated page structurally identical.
 */
withDefaults(defineProps<{
  title: string
  description?: string
  icon?: string
}>(), {
  description: undefined,
  icon: undefined
})
</script>

<template>
  <UDashboardPanel class="sp-dashboard-page">
    <template #header>
      <UDashboardNavbar
        :title="title"
        :icon="icon"
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
      <div class="mx-auto w-full max-w-7xl space-y-8 pb-10">
        <div
          v-if="description"
          class="sp-dashboard-intro"
        >
          <div class="relative z-10 flex items-start gap-3">
            <div class="mt-1 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <UIcon :name="icon || 'i-lucide-sparkles'" class="size-4" />
            </div>
            <div class="min-w-0">
              <div class="sp-khmer-rule mb-2 !h-px !w-12" />
              <p class="max-w-3xl text-sm leading-6 text-muted">
                {{ description }}
              </p>
            </div>
          </div>
        </div>

        <slot />
      </div>
    </template>
  </UDashboardPanel>
</template>
