<script setup lang="ts">
withDefaults(defineProps<{
  size?: number
}>(), {
  size: 32
})
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-[22%] border border-primary/20 bg-black shadow-sm"
    :style="{
      width: `${size}px`,
      height: `${size}px`
    }"
    aria-hidden="true"
  >
    <img
      src="/brand/sp-cambo-logo.png"
      alt=""
      class="size-full object-cover"
      width="512"
      height="512"
      decoding="async"
    >
  </span>
</template>
