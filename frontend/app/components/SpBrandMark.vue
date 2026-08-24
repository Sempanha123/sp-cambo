<script setup lang="ts">
/**
 * SP Cambo brand mark: a terminal prompt glyph inside a precise rounded square.
 * The mark reads as developer infrastructure rather than a consumer app icon.
 */
withDefaults(defineProps<{
  size?: number
}>(), {
  size: 32
})
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    aria-hidden="true"
    focusable="false"
  >
    <rect
      x="0.75"
      y="0.75"
      width="30.5"
      height="30.5"
      rx="7.25"
      class="fill-primary/10 stroke-primary/35"
      stroke-width="1.5"
    />
    <path
      d="M10.5 11.5 L15 16 L10.5 20.5"
      class="stroke-primary"
      stroke-width="2.25"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
    <path
      d="M17.5 21 H22"
      style="stroke: var(--sp-khmer-gold)"
      stroke-width="2.25"
      stroke-linecap="round"
    />
  </svg>
</template>
