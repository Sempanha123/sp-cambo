<script setup lang="ts">
/** Compact metric tile for dashboards. Never renders a fabricated value. */
withDefaults(defineProps<{
  label: string
  value: string
  hint?: string
  icon?: string
  /** Marks interim numbers that settlement will replace. */
  estimated?: boolean
  tone?: 'default' | 'warning' | 'error' | 'success'
}>(), {
  hint: undefined,
  icon: undefined,
  estimated: false,
  tone: 'default'
})

const toneClass = {
  default: 'text-highlighted',
  warning: 'text-warning',
  error: 'text-error',
  success: 'text-success'
}
</script>

<template>
  <div class="sp-metric-tile rounded-lg border border-default bg-elevated/40 p-5">
    <div class="flex items-start justify-between gap-3">
      <p class="text-xs font-medium tracking-wide text-muted uppercase">
        {{ label }}
      </p>
      <UIcon
        v-if="icon"
        :name="icon"
        class="size-4 shrink-0 text-dimmed"
      />
    </div>

    <p
      class="sp-numeric mt-2.5 text-2xl font-semibold tracking-tight"
      :class="toneClass[tone]"
    >
      {{ value }}
    </p>

    <div class="mt-1.5 flex flex-wrap items-center gap-2">
      <UBadge
        v-if="estimated"
        color="warning"
        variant="subtle"
        size="sm"
      >
        Estimated
      </UBadge>
      <p
        v-if="hint"
        class="text-xs text-muted"
      >
        {{ hint }}
      </p>
    </div>
  </div>
</template>
