<script setup lang="ts">
const props = defineProps<{
  family: string
  description?: string | null
}>()

const presentation = computed(() => modelPresentation(props.family, props.family))
</script>

<template>
  <div class="flex items-center gap-3">
    <SpModelLogo :model="family" :label="family" size="md" />
    <div class="min-w-0">
      <h2 class="truncate text-xl font-semibold tracking-tight text-highlighted">
        {{ family }}
      </h2>
      <p v-if="description" class="mt-0.5 text-sm text-muted">
        {{ description }}
      </p>
      <p v-else class="mt-0.5 text-xs font-medium text-muted">
        {{ presentation.provider }} models
      </p>
    </div>
  </div>
</template>
