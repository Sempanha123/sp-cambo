<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  description?: string
  icon?: string
}>(), {
  description: undefined,
  icon: 'i-lucide-package'
})
</script>

<template>
  <!--
    Announced politely: "there is nothing here" is a real answer, and it replaces
    the loading skeleton. Without a role, a screen reader user waits on a "Loading"
    that has already resolved to an empty list.
  -->
  <div
    role="status"
    class="sp-r12-state flex flex-col items-center gap-4 rounded-lg border border-default bg-elevated/30 px-6 py-12 text-center"
  >
    <div class="flex size-11 items-center justify-center rounded-full bg-elevated text-dimmed">
      <UIcon
        :name="icon"
        class="size-5"
      />
    </div>

    <div class="max-w-md space-y-1.5">
      <p class="font-medium text-highlighted">
        {{ title }}
      </p>
      <p
        v-if="description"
        class="text-sm text-muted"
      >
        {{ description }}
      </p>
    </div>

    <div
      v-if="$slots.action"
      class="pt-1"
    >
      <slot name="action" />
    </div>
  </div>
</template>
