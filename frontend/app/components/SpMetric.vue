<script setup lang="ts">
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

const toneAccent = {
  default: 'sp-r9-metric--default',
  primary: 'sp-r9-metric--primary',
  info: 'sp-r9-metric--info',
  warning: 'sp-r9-metric--warning',
  error: 'sp-r9-metric--error',
  success: 'sp-r9-metric--success'
}
</script>

<template>
  <div
    class="sp-metric-tile sp-r9-metric rounded-xl p-4"
    :class="toneAccent[tone]"
  >
    <div class="sp-r9-metric__glow" aria-hidden="true" />

    <div class="relative z-[1] flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs font-medium text-muted">
          {{ label }}
        </p>
        <p
          class="sp-numeric mt-1.5 break-words text-2xl font-semibold leading-tight tracking-tight sm:text-[1.625rem]"
          :class="toneClass[tone]"
        >
          {{ value }}
        </p>
      </div>

      <div
        v-if="icon"
        class="sp-r9-metric__icon flex size-9 shrink-0 items-center justify-center rounded-lg"
      >
        <UIcon
          :name="icon"
          class="size-4.5"
        />
      </div>
    </div>

    <div class="relative z-[1] mt-2 flex min-h-4 flex-wrap items-center gap-2">
      <UBadge
        v-if="estimated"
        color="warning"
        variant="subtle"
        size="xs"
      >
        Estimated
      </UBadge>

      <p
        v-if="hint"
        class="line-clamp-2 text-[11px] leading-4 text-muted"
      >
        {{ hint }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.sp-r9-metric {
  position: relative;
  isolation: isolate;
  overflow: hidden;
}

.sp-r9-metric__glow {
  position: absolute;
  right: -2.8rem;
  top: -3rem;
  width: 7rem;
  height: 7rem;
  border-radius: 9999px;
  opacity: .07;
  filter: blur(9px);
  background: var(--sp-r9-metric-accent, rgb(88 127 255));
}

.sp-r9-metric__icon {
  border: 1px solid rgb(255 255 255 / .04);
  background: color-mix(in oklab, var(--sp-r9-metric-accent, var(--ui-primary)) 8%, transparent);
  color: var(--sp-r9-metric-accent, var(--ui-primary));
}

.sp-r9-metric--default { --sp-r9-metric-accent: rgb(114 143 255); }
.sp-r9-metric--primary { --sp-r9-metric-accent: rgb(93 130 255); }
.sp-r9-metric--info { --sp-r9-metric-accent: rgb(57 184 255); }
.sp-r9-metric--warning { --sp-r9-metric-accent: rgb(245 173 63); }
.sp-r9-metric--error { --sp-r9-metric-accent: rgb(244 94 109); }
.sp-r9-metric--success { --sp-r9-metric-accent: rgb(46 201 153); }
</style>
