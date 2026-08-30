<script setup lang="ts">
/** Smooth premium metric tile used across the SP Cambo dashboard. */
withDefaults(defineProps<{
  label: string
  value: string
  hint?: string
  icon?: string
  estimated?: boolean
  tone?: 'default' | 'primary' | 'info' | 'warning' | 'error' | 'success'
}>(), {
  hint: undefined,
  icon: undefined,
  estimated: false,
  tone: 'default'
})

const toneClass = {
  default: 'text-highlighted',
  primary: 'text-primary',
  info: 'text-info',
  warning: 'text-warning',
  error: 'text-error',
  success: 'text-success'
}
</script>

<template>
  <div class="sp-metric-tile rounded-2xl p-5">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <p class="text-xs font-medium text-muted">
          {{ label }}
        </p>
        <p
          class="sp-numeric mt-2 text-2xl font-semibold tracking-tight"
          :class="toneClass[tone]"
        >
          {{ value }}
        </p>
      </div>

      <div
        v-if="icon"
        class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary"
      >
        <UIcon
          :name="icon"
          class="size-5"
        />
      </div>
    </div>

    <div class="mt-2 flex min-h-5 flex-wrap items-center gap-2">
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
