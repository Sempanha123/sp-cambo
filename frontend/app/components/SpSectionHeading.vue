<script setup lang="ts">
/**
 * Section heading used inside dashboard and documentation pages so headings,
 * supporting copy and inline actions align everywhere.
 */
withDefaults(defineProps<{
  title: string
  description?: string
  /** Heading level, so pages keep a valid document outline. */
  level?: 2 | 3
  icon?: string
}>(), {
  description: undefined,
  level: 2,
  icon: undefined
})
</script>

<template>
  <div class="sp-r12-section-heading flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="space-y-1.5">
      <component
        :is="level === 2 ? 'h2' : 'h3'"
        class="sp-r12-section-heading__title flex items-center gap-2 font-semibold tracking-tight text-highlighted"
        :class="level === 2 ? 'text-lg' : 'text-base'"
      >
        <UIcon
          v-if="icon"
          :name="icon"
          class="sp-r12-section-heading__icon size-4 shrink-0 text-dimmed"
        />
        {{ title }}
      </component>

      <p
        v-if="description"
        class="max-w-2xl text-sm text-muted"
      >
        {{ description }}
      </p>
    </div>

    <div
      v-if="$slots.actions"
      class="flex shrink-0 flex-wrap items-center gap-2"
    >
      <slot name="actions" />
    </div>
  </div>
</template>
