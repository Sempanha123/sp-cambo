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
    class="sp-model-alias-badge inline-flex min-w-0 items-center border text-default"
    :class="compact
      ? 'gap-1.5 rounded-lg px-2 py-1 text-xs'
      : 'gap-2 rounded-xl px-2.5 py-1.5 text-sm'"
    :title="showAlias && model ? `${presentation.label} · ${model}` : presentation.label"
  >
    <!-- R7: small user-provided animated artwork is used for public aliases. -->
    <SpPublicAliasIcon
      :alias="model"
      :label="label"
      :size="compact ? 'xs' : 'sm'"
    />

    <span class="min-w-0">
      <span class="block truncate font-medium">
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
.sp-model-alias-badge {
  border-color: rgb(255 255 255 / .055);
  background:
    linear-gradient(145deg, rgb(255 255 255 / .022), transparent 62%),
    color-mix(in oklab, var(--ui-bg-elevated) 43%, transparent);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .025),
    0 8px 20px -16px color-mix(in oklab, var(--ui-primary) 25%, transparent);
  backdrop-filter: blur(10px);
  transition:
    transform .2s ease,
    border-color .2s ease,
    background-color .2s ease;
}

.sp-model-alias-badge:hover {
  transform: translateY(-1px);
  border-color: color-mix(in oklab, var(--ui-primary) 22%, transparent);
  background-color: color-mix(in oklab, var(--ui-primary) 4%, transparent);
}
</style>
