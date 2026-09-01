<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  description?: string
  icon?: string
  retrying?: boolean
  /** Field-level messages when the failure came from validation. */
  errors?: Record<string, string[]>
}>(), {
  title: 'Something went wrong',
  description: undefined,
  icon: 'i-lucide-triangle-alert',
  retrying: false,
  errors: undefined
})

defineEmits<{
  retry: []
}>()
</script>

<template>
  <!--
    Announced, because this surface always replaces a loading skeleton.

    `SpStateLoading` says "Loading" in a live region and is then removed from the
    DOM when the request settles. Without a role here, a screen reader user's last
    announcement is "Loading" — they are never told the read failed, and never
    told a "Try again" button now exists. `alert` is assertive, which is right for
    a fault: it interrupts, and `aria-atomic` reads the title, the reason and the
    retry together.
  -->
  <div
    role="alert"
    class="sp-r12-state rounded-lg border border-error/30 bg-error/5 p-6"
  >
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
      <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-error/10 text-error">
        <UIcon
          :name="icon"
          class="size-5"
        />
      </div>

      <div class="min-w-0 flex-1 space-y-2">
        <p class="font-medium text-highlighted">
          {{ title }}
        </p>
        <p
          v-if="description"
          class="text-sm text-muted"
        >
          {{ description }}
        </p>

        <ul
          v-if="errors && Object.keys(errors).length"
          class="space-y-1 text-sm text-muted"
        >
          <li
            v-for="(messages, field) in errors"
            :key="field"
          >
            {{ messages[0] }}
          </li>
        </ul>

        <div class="pt-1">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="retrying"
            @click="$emit('retry')"
          >
            Try again
          </UButton>
        </div>
      </div>
    </div>
  </div>
</template>
