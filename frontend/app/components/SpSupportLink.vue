<script setup lang="ts">
/**
 * A link to the deployment's support channel — or nothing at all.
 *
 * Several surfaces end with an instruction to contact SP Cambo. This is what makes
 * that instruction actionable, and it renders **nothing** when no channel is
 * configured: no address is guessed, and a disabled or dead link would be worse than
 * the plain sentence, because it looks like a way out. See
 * `~/utils/supportChannel` for the reasoning and `NUXT_PUBLIC_SUPPORT_URL` for how a
 * deployment publishes one.
 *
 * The channel may be an inbox or a page, and the two behave differently: mail opens
 * the customer's client in place, a page opens in a new tab so a form they were part
 * way through is not thrown away.
 */
const props = withDefaults(defineProps<{
  /** Overrides the label. The address itself is appended in the button variant. */
  label?: string
  /** `button` sits beside other actions; `inline` sits inside a sentence. */
  variant?: 'button' | 'inline'
}>(), {
  label: undefined,
  variant: 'button'
})

const channel = useSupportChannel()

const icon = computed(() => channel.value?.kind === 'email' ? 'i-lucide-mail' : 'i-lucide-external-link')

/**
 * A new tab for a page, the same one for mail.
 *
 * `_blank` on a `mailto:` leaves an empty tab behind in some browsers once the mail
 * client takes over, which reads as a failed navigation.
 */
const target = computed(() => channel.value?.kind === 'link' ? '_blank' : undefined)

const text = computed(() => props.label ?? 'Contact SP Cambo support')
</script>

<template>
  <!--
    `external` is required: the href is an absolute URL or a mailto, and Nuxt would
    otherwise try to resolve it against the router and produce a 404 page.
  -->
  <UButton
    v-if="channel && variant === 'button'"
    :to="channel.href"
    :target="target"
    external
    color="neutral"
    variant="subtle"
    size="sm"
    :icon="icon"
  >
    {{ text }}
    <span class="text-dimmed">{{ channel.label }}</span>
  </UButton>

  <UButton
    v-else-if="channel"
    :to="channel.href"
    :target="target"
    external
    variant="link"
    size="sm"
    class="p-0"
    :icon="icon"
    :aria-label="`${text}: ${channel.label}`"
  >
    {{ channel.label }}
  </UButton>
</template>
