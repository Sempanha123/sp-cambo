<script setup lang="ts">
withDefaults(defineProps<{
  /** Hide the wordmark on narrow surfaces. */
  markOnly?: boolean
  size?: number
}>(), {
  markOnly: false,
  size: 30
})
</script>

<template>
  <span class="inline-flex items-center gap-2.5">
    <SpBrandMark :size="size" />
    <span
      v-if="!markOnly"
      class="sp-r12-wordmark inline-flex flex-col"
    >
      <span class="sp-r12-wordmark__name text-[15px] font-semibold tracking-tight text-highlighted">SP Cambo</span>
      <span class="sp-r12-wordmark__meta">AI Gateway</span>
    </span>
  </span>
</template>
