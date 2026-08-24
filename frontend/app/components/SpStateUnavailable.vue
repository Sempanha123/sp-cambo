<script setup lang="ts">
/**
 * Honest "not available yet" surface.
 *
 * SP Cambo never renders invented models, prices, balances or usage. When a
 * control-plane endpoint has not shipped or cannot be reached, the page says so
 * and offers a retry instead of showing placeholder commercial data.
 */
const props = withDefaults(defineProps<{
  title?: string
  description?: string
  /** True when the failure is a connectivity problem rather than a missing route. */
  offline?: boolean
  retrying?: boolean
}>(), {
  title: undefined,
  description: undefined,
  offline: false,
  retrying: false
})

defineEmits<{
  retry: []
}>()

/**
 * Connectivity copy wins over a caller's own wording.
 *
 * A page's `title`/`description` is written about a *missing endpoint* — "the
 * control plane has not shipped the orders endpoint yet". When the real cause is
 * that the browser cannot reach anything, that sentence is both false and
 * misdirecting: it blames SP Cambo's API for an unpublished route and says
 * nothing about the connection the customer actually needs to check. So an
 * offline failure is described as offline regardless of what the caller passed.
 */
const resolvedTitle = computed(() => {
  if (props.offline) {
    return 'SP Cambo could not be reached'
  }

  return props.title ?? 'Not available yet'
})

const resolvedDescription = computed(() => {
  if (props.offline) {
    return 'Check your connection. This page will show live data as soon as the SP Cambo API responds.'
  }

  return props.description
    ?? 'This data comes from the SP Cambo control plane, which has not published this endpoint yet. Nothing is shown here until real data is available.'
})
</script>

<template>
  <!--
    Announced politely, for the same reason as `SpStateError`: this replaces the
    loading skeleton, so without a role the last thing a screen reader user heard
    is "Loading".

    `status` rather than `alert` on purpose. An unpublished endpoint or a dropped
    connection is not a fault the customer caused, and it is often the expected
    state on a page whose endpoint has not shipped — interrupting them for it
    would be wrong. It still announces, which is the point.
  -->
  <div
    role="status"
    class="flex flex-col items-center gap-4 rounded-lg border border-dashed border-default bg-elevated/40 px-6 py-12 text-center"
  >
    <div class="flex size-11 items-center justify-center rounded-full bg-elevated text-dimmed">
      <UIcon
        :name="offline ? 'i-lucide-wifi-off' : 'i-lucide-plug-zap'"
        class="size-5"
      />
    </div>

    <div class="max-w-md space-y-1.5">
      <p class="font-medium text-highlighted">
        {{ resolvedTitle }}
      </p>
      <p class="text-sm text-muted">
        {{ resolvedDescription }}
      </p>
    </div>

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
</template>
