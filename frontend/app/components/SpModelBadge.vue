<script setup lang="ts">
const props = withDefaults(defineProps<{
  model?: string | null
  label?: string | null
  showAlias?: boolean
  compact?: boolean
}>(), {
  model: null,
  label: null,
  showAlias: false,
  compact: false
})

const presentation = computed(() => modelPresentation(props.model, props.label))
</script>

<template>
  <span
    class="sp-r8-model-badge inline-flex min-w-0 items-center"
    :class="compact
      ? 'gap-1.5 rounded-lg px-2 py-1 text-xs'
      : 'gap-2 rounded-xl px-2.5 py-1.5 text-sm'"
    :title="showAlias && model ? `${presentation.label} · ${model}` : presentation.label"
  >
    <SpPublicAliasIcon
      :alias="model"
      :label="label"
      :size="compact ? 'xs' : 'sm'"
    />

    <span class="min-w-0">
      <span class="block truncate font-medium text-default">
        {{ presentation.label }}
      </span>
      <code
        v-if="showAlias && model"
        class="block truncate font-mono text-[10px] text-muted"
      >
        {{ model }}
      </code>
    </span>
  </span>
</template>

<style scoped>
.sp-r8-model-badge {
  border: 1px solid rgb(255 255 255 / .045);
  background: color-mix(in oklab, var(--ui-bg-elevated) 38%, transparent);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .02);
  backdrop-filter: blur(8px);
  transition: border-color .2s ease, background-color .2s ease, transform .2s ease;
}

.sp-r8-model-badge:hover {
  transform: translateY(-1px);
  border-color: color-mix(in oklab, var(--ui-primary) 18%, transparent);
  background-color: color-mix(in oklab, var(--ui-primary) 4%, transparent);
}
</style>
