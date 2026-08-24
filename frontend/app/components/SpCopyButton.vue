<script setup lang="ts">
const props = withDefaults(defineProps<{
  value: string
  label?: string
  size?: 'sm' | 'md'
  variant?: 'ghost' | 'outline' | 'subtle'
}>(), {
  label: undefined,
  size: 'sm',
  variant: 'ghost'
})

const copied = ref(false)
const failed = ref(false)
let resetTimer: ReturnType<typeof setTimeout> | undefined

const copy = async () => {
  failed.value = false

  try {
    if (!import.meta.client || !navigator.clipboard) {
      throw new Error('Clipboard unavailable')
    }

    await navigator.clipboard.writeText(props.value)
    copied.value = true
  } catch {
    failed.value = true
  }

  clearTimeout(resetTimer)
  resetTimer = setTimeout(() => {
    copied.value = false
    failed.value = false
  }, 2000)
}

onBeforeUnmount(() => clearTimeout(resetTimer))

const icon = computed(() => {
  if (copied.value) {
    return 'i-lucide-check'
  }

  return failed.value ? 'i-lucide-circle-alert' : 'i-lucide-copy'
})

const ariaLabel = computed(() => {
  if (copied.value) {
    return 'Copied'
  }

  return failed.value ? 'Copy failed' : `Copy ${props.label ?? 'to clipboard'}`
})
</script>

<template>
  <UButton
    :color="copied ? 'success' : failed ? 'error' : 'neutral'"
    :variant="variant"
    :size="size"
    :icon="icon"
    :aria-label="ariaLabel"
    @click="copy"
  >
    <template v-if="label">
      {{ copied ? 'Copied' : failed ? 'Copy failed' : label }}
    </template>
  </UButton>
</template>
