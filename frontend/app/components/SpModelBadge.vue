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
    class="inline-flex min-w-0 items-center border border-default bg-elevated/35 text-default shadow-sm shadow-black/5"
    :class="compact ? 'gap-1.5 rounded-lg px-2 py-1 text-xs' : 'gap-2 rounded-xl px-2.5 py-1.5 text-sm'"
    :title="showAlias && model ? `${presentation.label} · ${model}` : presentation.label"
  >
    <SpModelLogo :model="model" :label="label" :size="compact ? 'xs' : 'sm'" />
    <span class="min-w-0">
      <span class="block truncate font-medium">{{ presentation.label }}</span>
      <code v-if="showAlias && model" class="block truncate font-mono text-[10px] text-muted">{{ model }}</code>
    </span>
  </span>
</template>
