<script setup lang="ts">
import type { SpErrorCode } from '~/types/api'

/**
 * Standard async surface: loading -> unavailable / forbidden / failed / empty -> content.
 *
 * Every page that reads the control plane routes through this so the states look
 * and behave identically across the product.
 */
const props = withDefaults(defineProps<{
  loading?: boolean
  /** The endpoint does not exist yet or the control plane is unreachable. */
  unavailable?: boolean
  /** The endpoint answered 403. Never retryable. */
  forbidden?: boolean
  /** A real error the customer may be able to retry. */
  failed?: boolean
  empty?: boolean
  offline?: boolean
  errorTitle?: string
  errorMessage?: string
  unavailableTitle?: string
  unavailableDescription?: string
  forbiddenTitle?: string
  forbiddenDescription?: string
  /** Named on the forbidden surface so an operator knows what to grant. */
  forbiddenPermission?: string | null
  /** Distinguishes a missing permission from a suspended account. */
  forbiddenCode?: SpErrorCode | null
  emptyTitle?: string
  emptyDescription?: string
  emptyIcon?: string
  loadingVariant?: 'cards' | 'rows' | 'metrics' | 'text'
  loadingCount?: number
}>(), {
  loading: false,
  unavailable: false,
  forbidden: false,
  failed: false,
  empty: false,
  offline: false,
  errorTitle: 'This could not be loaded',
  errorMessage: undefined,
  unavailableTitle: undefined,
  unavailableDescription: undefined,
  forbiddenTitle: undefined,
  forbiddenDescription: undefined,
  forbiddenPermission: null,
  forbiddenCode: null,
  emptyTitle: 'Nothing here yet',
  emptyDescription: undefined,
  emptyIcon: 'i-lucide-package',
  loadingVariant: 'rows',
  loadingCount: 3
})

defineEmits<{
  retry: []
}>()

const state = computed(() => {
  if (props.loading) {
    return 'loading'
  }

  if (props.unavailable) {
    return 'unavailable'
  }

  // Ahead of `failed`: a 403 is an answer, not a fault.
  if (props.forbidden) {
    return 'forbidden'
  }

  if (props.failed) {
    return 'failed'
  }

  if (props.empty) {
    return 'empty'
  }

  return 'content'
})
</script>

<template>
  <SpStateLoading
    v-if="state === 'loading'"
    :variant="loadingVariant"
    :count="loadingCount"
  />

  <SpStateUnavailable
    v-else-if="state === 'unavailable'"
    :offline="offline"
    :title="unavailableTitle"
    :description="unavailableDescription"
    @retry="$emit('retry')"
  />

  <SpStateForbidden
    v-else-if="state === 'forbidden'"
    :title="forbiddenTitle"
    :description="forbiddenDescription"
    :permission="forbiddenPermission"
    :code="forbiddenCode"
  />

  <SpStateError
    v-else-if="state === 'failed'"
    :title="errorTitle"
    :description="errorMessage"
    @retry="$emit('retry')"
  />

  <slot
    v-else-if="state === 'empty'"
    name="empty"
  >
    <SpStateEmpty
      :title="emptyTitle"
      :description="emptyDescription"
      :icon="emptyIcon"
    />
  </slot>

  <slot v-else />
</template>
