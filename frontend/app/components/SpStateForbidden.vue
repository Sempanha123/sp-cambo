<script setup lang="ts">
import type { SpErrorCode } from '~/types/api'

/**
 * The 403 surface: the endpoint exists and answered, and the answer is "no".
 *
 * Deliberately distinct from `SpStateError`: this is not a fault the customer can
 * retry away, so no retry is offered and nothing suggests something went wrong.
 * It is also distinct from `SpStateUnavailable`, which means the endpoint does not
 * exist yet.
 *
 * The control plane returns two different 403 codes and they mean opposite things
 * about the account, so copy keys off `code` rather than off the message text:
 *   `forbidden`         -> signed in and in good standing, but lacks a permission
 *   `account_suspended` -> holds the permission or not; the account itself is barred
 */
const props = withDefaults(defineProps<{
  title?: string
  description?: string
  /** Control-plane permission the route requires, e.g. `admin.view`. */
  permission?: string | null
  /** Machine code from the rejected response. */
  code?: SpErrorCode | null
}>(), {
  title: undefined,
  description: undefined,
  permission: null,
  code: null
})

const suspended = computed(() => props.code === 'account_suspended')

const copy = computed(() => {
  if (suspended.value) {
    return {
      icon: 'i-lucide-user-x',
      title: props.title ?? 'This account is suspended',
      description: props.description
        ?? 'SP Cambo has suspended this account, so none of its areas can be opened — including ones it would normally have access to. Contact SP Cambo support to have it reviewed.'
    }
  }

  return {
    icon: 'i-lucide-lock',
    title: props.title ?? 'You do not have access to this area',
    description: props.description
      ?? 'This account is signed in, but it does not hold the permission this area requires. Nothing here is hidden from you by mistake — ask an SP Cambo operator to grant it.'
  }
})
</script>

<template>
  <!--
    `status`, not `alert`, and deliberately so.

    A 403 is an answer rather than a fault — the same reasoning that keeps a retry
    button off this surface keeps it from interrupting. But it still has to be
    announced: it replaces the loading skeleton, and a user who is told nothing
    would be left waiting on a "Loading" that has already finished.
  -->
  <div
    role="status"
    class="flex flex-col items-center gap-4 rounded-lg border border-dashed border-default bg-elevated/40 px-6 py-12 text-center"
  >
    <div class="flex size-11 items-center justify-center rounded-full bg-elevated text-dimmed">
      <UIcon
        :name="copy.icon"
        class="size-5"
      />
    </div>

    <div class="max-w-md space-y-1.5">
      <p class="font-medium text-highlighted">
        {{ copy.title }}
      </p>
      <p class="text-sm text-muted">
        {{ copy.description }}
      </p>
      <!-- Named so the operator granting it knows what to grant. Permission names are not secrets. -->
      <p
        v-if="permission && !suspended"
        class="pt-1 text-xs text-dimmed"
      >
        Required permission
        <code class="rounded bg-elevated px-1.5 py-0.5 font-mono text-default">{{ permission }}</code>
      </p>
    </div>

    <!--
      Both branches of the copy above end by telling the reader to ask somebody:
      support for a suspended account, an operator for a missing permission. The
      link is what makes that possible, and it is absent unless the deployment has
      published a channel — a suspended customer cannot open any other page, so a
      link that went nowhere would be the only thing left to try.
    -->
    <div class="flex flex-wrap items-center justify-center gap-2">
      <SpSupportLink />

      <UButton
        to="/dashboard"
        color="neutral"
        variant="outline"
        size="sm"
        icon="i-lucide-arrow-left"
      >
        Back to your dashboard
      </UButton>
    </div>
  </div>
</template>
