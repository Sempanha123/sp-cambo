<script setup lang="ts">
import QRCode from 'qrcode'

/**
 * Renders a Bakong KHQR payment code.
 *
 * The payload always comes from the backend payment attempt. When the backend
 * supplies a rendered image it is used as-is; otherwise the payload string is
 * encoded in the browser. Nothing about the amount or the merchant is derived
 * here — this component only draws what the server issued.
 */
const props = withDefaults(defineProps<{
  payload: string
  imageUrl?: string | null
  size?: number
}>(), {
  imageUrl: null,
  size: 260
})

const generated = ref<string | null>(null)
const failed = ref(false)

const render = async () => {
  if (props.imageUrl) {
    generated.value = null
    failed.value = false
    return
  }

  if (!props.payload) {
    generated.value = null
    failed.value = true
    return
  }

  try {
    generated.value = await QRCode.toDataURL(props.payload, {
      errorCorrectionLevel: 'M',
      margin: 1,
      width: props.size,
      color: { dark: '#000000', light: '#ffffff' }
    })
    failed.value = false
  } catch {
    generated.value = null
    failed.value = true
  }
}

watch(() => [props.payload, props.imageUrl, props.size], render, { immediate: true })

const source = computed(() => props.imageUrl || generated.value)
</script>

<template>
  <div class="flex flex-col items-center gap-3">
    <!-- White plate: a KHQR must stay high-contrast in dark mode to remain scannable. -->
    <div
      class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-default"
      :style="{ width: `${size + 24}px`, height: `${size + 24}px` }"
    >
      <img
        v-if="source"
        :src="source"
        :width="size"
        :height="size"
        alt="Bakong KHQR payment code"
        class="size-full object-contain"
      >
      <div
        v-else-if="failed"
        class="flex size-full flex-col items-center justify-center gap-2 p-4 text-center"
      >
        <UIcon
          name="i-lucide-qr-code"
          class="size-8 text-neutral-400"
        />
        <p class="text-xs text-neutral-600">
          The code could not be drawn. Copy the payment string below into your banking app instead.
        </p>
      </div>
      <div
        v-else
        class="flex size-full items-center justify-center"
      >
        <UIcon
          name="i-lucide-loader-circle"
          class="size-6 animate-spin text-neutral-400"
        />
      </div>
    </div>

    <div class="flex w-full max-w-xs items-center gap-2">
      <code class="min-w-0 flex-1 truncate font-mono text-xs text-dimmed">{{ payload }}</code>
      <SpCopyButton
        :value="payload"
        label="Copy"
        variant="subtle"
      />
    </div>
  </div>
</template>
