<script setup lang="ts">
const backgroundRun = usePlaygroundBackgroundRun()
const route = useRoute()

const visible = computed(() => backgroundRun.state.value.running && route.path !== '/dashboard/playground')
const label = computed(() => backgroundRun.state.value.model_alias || 'Playground model')
const phase = computed(() => {
  switch (backgroundRun.state.value.status) {
    case 'streaming': return 'Writing response…'
    case 'saving': return 'Saving response…'
    default: return 'Generating…'
  }
})

const stop = () => backgroundRun.stop()
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-2 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="translate-y-2 opacity-0"
  >
    <div
      v-if="visible"
      class="fixed bottom-4 right-4 z-[90] w-[min(23rem,calc(100vw-2rem))] rounded-2xl border border-primary/25 bg-elevated/95 p-3 shadow-2xl backdrop-blur-xl"
      role="status"
      aria-live="polite"
    >
      <div class="flex items-start gap-3">
        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
        </span>
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <p class="truncate text-sm font-semibold text-highlighted">Playground is still working</p>
            <span class="inline-block size-1.5 shrink-0 animate-pulse rounded-full bg-success" />
          </div>
          <p class="mt-0.5 truncate font-mono text-[10px] text-muted">{{ label }}</p>
          <p class="mt-1 text-xs text-toned">{{ phase }} You can keep using this page.</p>
          <div class="mt-3 flex gap-2">
            <UButton to="/dashboard/playground" size="xs" color="primary" variant="soft" icon="i-lucide-arrow-up-right">Open Playground</UButton>
            <UButton size="xs" color="error" variant="ghost" icon="i-lucide-square" :loading="backgroundRun.state.value.stopping" @click="stop">Stop</UButton>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>
