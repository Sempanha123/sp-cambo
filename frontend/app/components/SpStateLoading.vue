<script setup lang="ts">
/** Skeleton placeholder matching the shape of the content it replaces. */
withDefaults(defineProps<{
  variant?: 'cards' | 'rows' | 'metrics' | 'text'
  count?: number
}>(), {
  variant: 'rows',
  count: 3
})
</script>

<template>
  <div
    class="sp-r12-loading animate-pulse"
    role="status"
    aria-live="polite"
  >
    <span class="sr-only">Loading</span>

    <div
      v-if="variant === 'metrics'"
      class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
    >
      <div
        v-for="index in count"
        :key="index"
        class="space-y-3 rounded-lg border border-default bg-elevated/40 p-5"
      >
        <USkeleton class="h-3 w-24" />
        <USkeleton class="h-7 w-32" />
        <USkeleton class="h-3 w-20" />
      </div>
    </div>

    <div
      v-else-if="variant === 'cards'"
      class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"
    >
      <div
        v-for="index in count"
        :key="index"
        class="space-y-4 rounded-lg border border-default bg-elevated/40 p-6"
      >
        <USkeleton class="h-3 w-20" />
        <USkeleton class="h-8 w-28" />
        <div class="space-y-2">
          <USkeleton class="h-3 w-full" />
          <USkeleton class="h-3 w-4/5" />
          <USkeleton class="h-3 w-3/5" />
        </div>
        <USkeleton class="h-9 w-full" />
      </div>
    </div>

    <div
      v-else-if="variant === 'text'"
      class="space-y-2"
    >
      <USkeleton
        v-for="index in count"
        :key="index"
        class="h-3"
        :class="index === count ? 'w-2/3' : 'w-full'"
      />
    </div>

    <div
      v-else
      class="divide-y divide-default overflow-hidden rounded-lg border border-default"
    >
      <div
        v-for="index in count"
        :key="index"
        class="flex items-center gap-4 bg-elevated/40 px-4 py-4"
      >
        <USkeleton class="size-8 shrink-0 rounded-full" />
        <USkeleton class="h-3 w-1/4" />
        <USkeleton class="h-3 w-1/6" />
        <USkeleton class="ml-auto h-3 w-16" />
      </div>
    </div>
  </div>
</template>
