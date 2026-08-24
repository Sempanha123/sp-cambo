<script setup lang="ts">
/**
 * One-time API key reveal.
 *
 * The full secret exists only in this component's props for as long as the modal
 * is open. It is never written to storage, never put in a URL, never logged and
 * never re-fetchable — no list endpoint returns secrets. The dialog is
 * intentionally not dismissible so the value cannot be lost by a stray click.
 *
 * `audience` selects the handling guidance, because the three secrets SP Cambo
 * issues are used in genuinely different places. Telling a reseller to put an
 * `sk-spm-*` management key in a CLI config, or to keep a key that belongs to
 * their customer, would be wrong advice about a credential.
 */
const props = withDefaults(defineProps<{
  open: boolean
  secret: string | null
  keyLabel: string
  /** Whether this secret came from a creation or a rotation. */
  context: 'created' | 'rotated'
  /**
   * `own`        -> the signed-in account's own inference key
   * `managed`    -> an inference key belonging to a reseller's customer
   * `management` -> the reseller's own `sk-spm-*` automation credential
   */
  audience?: 'own' | 'managed' | 'management'
  /** Managed keys only: who the credential actually belongs to. */
  ownerLabel?: string | null
}>(), {
  audience: 'own',
  ownerLabel: null
})

const emit = defineEmits<{
  'update:open': [value: boolean]
  'close': []
}>()

const acknowledged = ref(false)
const visible = ref(true)

const heading = computed(() => {
  if (props.context === 'rotated') {
    return 'New secret for this key'
  }

  return {
    own: 'Your new API key',
    managed: 'New key for this customer',
    management: 'Your new management key'
  }[props.audience]
})

const intro = computed(() => {
  if (props.context === 'rotated') {
    return 'The previous secret for this key stopped working the moment this one was issued. Update anywhere that used it.'
  }

  return {
    own: 'Copy this now and store it in a secret manager or an environment variable.',
    managed: 'Copy this now and hand it to the customer over a channel they control. You cannot retrieve it again.',
    management: 'Copy this now and store it in your automation\'s secret manager.'
  }[props.audience]
})

/** Handling rules, differing only where the credential genuinely differs. */
const guidance = computed(() => {
  if (props.audience === 'management') {
    return [
      'This key manages customers, keys and allocations. It cannot make inference requests.',
      'Store it in the secret manager your automation reads from, never in source or a chat.',
      'Its scopes are fixed at creation. To change them, create a new key and revoke this one.'
    ]
  }

  if (props.audience === 'managed') {
    return [
      `This secret belongs to ${props.ownerLabel ?? 'the customer'}, not to your own account. Deliver it to them and keep no copy.`,
      'Send it over a channel the customer controls — not a shared inbox, ticket note or group chat.',
      'If it is lost or exposed, revoke it here and issue a new one. It cannot be recovered.'
    ]
  }

  return [
    'Read it from an environment variable or a secret manager, never from source.',
    'Do not commit it, paste it into a chat, or ship it in client-side code.'
  ]
})

/** Masked rendering keeps the value off a screen being shared or recorded. */
const masked = computed(() => props.secret ? '•'.repeat(Math.min(props.secret.length, 48)) : '')

/**
 * What to do if the secret is lost. Only the account's own inference keys can be
 * rotated in place; reseller-issued keys have no rotate route, so the remedy is a
 * revoke plus a fresh key.
 */
const lossRemedy = computed(() => props.audience === 'own'
  ? 'SP Cambo stores only a hash of this secret. If you lose it, the only remedy is to rotate the key and update your configuration.'
  : 'SP Cambo stores only a hash of this secret. If you lose it, the only remedy is to revoke this key and issue a new one.')

const acknowledgement = computed(() => props.audience === 'managed'
  ? 'I have delivered this secret to the customer and kept no copy'
  : 'I have stored this secret somewhere safe')

const done = () => {
  emit('update:open', false)
  emit('close')
}

watch(() => props.open, (open) => {
  if (open) {
    acknowledged.value = false
    visible.value = true
  }
})
</script>

<template>
  <UModal
    :open="open"
    :dismissible="false"
    :close="false"
    :title="heading"
    :description="intro"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="space-y-5">
        <UAlert
          icon="i-lucide-triangle-alert"
          color="warning"
          variant="subtle"
          title="Shown once and never again"
          :description="lossRemedy"
        />

        <div class="space-y-2">
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-medium text-highlighted">
              {{ keyLabel }}
            </p>
            <UButton
              :icon="visible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
              color="neutral"
              variant="ghost"
              size="sm"
              :aria-label="visible ? 'Hide secret' : 'Show secret'"
              @click="visible = !visible"
            >
              {{ visible ? 'Hide' : 'Show' }}
            </UButton>
          </div>

          <div class="flex items-start gap-2 rounded-lg border border-default bg-elevated/60 p-3">
            <code class="min-w-0 flex-1 font-mono text-sm break-all text-toned">
              {{ visible ? secret : masked }}
            </code>
            <SpCopyButton
              v-if="secret"
              :value="secret"
              variant="subtle"
              label="Copy"
            />
          </div>
        </div>

        <ul class="space-y-1.5 text-sm text-muted">
          <li
            v-for="line in guidance"
            :key="line"
          >
            {{ line }}
          </li>
          <li v-if="audience === 'own'">
            Setup commands for each tool are in
            <NuxtLink
              to="/dashboard/cli-setup"
              class="text-primary underline decoration-dotted underline-offset-4"
            >
              CLI setup
            </NuxtLink>.
          </li>
        </ul>

        <UCheckbox
          v-model="acknowledged"
          :label="acknowledgement"
        />
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end">
        <UButton
          :disabled="!acknowledged"
          @click="done"
        >
          Done
        </UButton>
      </div>
    </template>
  </UModal>
</template>
