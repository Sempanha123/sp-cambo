<script setup lang="ts">
const props = withDefaults(defineProps<{
  model?: string | null
  label?: string | null
  size?: 'xs' | 'sm' | 'md' | 'lg'
}>(), {
  model: null,
  label: null,
  size: 'md'
})

const presentation = computed(() => modelPresentation(props.model, props.label))
const sizeClass = computed(() => ({
  xs: 'size-6 rounded-md',
  sm: 'size-8 rounded-lg',
  md: 'size-10 rounded-xl',
  lg: 'size-12 rounded-2xl'
}[props.size]))
const iconSizeClass = computed(() => ({
  xs: 'size-3.5',
  sm: 'size-4',
  md: 'size-5',
  lg: 'size-6'
}[props.size]))
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center justify-center border shadow-sm shadow-black/5"
    :class="[sizeClass, presentation.surfaceClass, presentation.ringClass]"
    :title="presentation.label"
    aria-hidden="true"
  >
    <UIcon
      :name="presentation.icon"
      :class="[iconSizeClass, presentation.iconClass]"
    />
  </span>
</template>
