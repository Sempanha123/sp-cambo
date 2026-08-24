<script setup lang="ts">
import type { PasswordPolicyLevel } from '~/utils/password'

/**
 * Shows progress towards the server's password rules.
 *
 * The rules listed are the exact ones the control plane enforces for this
 * surface, so a customer never meets every visible requirement and is then
 * rejected. Nothing is shown until they start typing.
 */
const props = withDefaults(defineProps<{
  value: string
  level?: PasswordPolicyLevel
}>(), {
  level: 'strong'
})

const strength = computed(() => passwordStrength(props.value, props.level))
const rules = computed(() => passwordChecklist(props.value, props.level))
</script>

<template>
  <div
    v-if="strength"
    class="space-y-2"
  >
    <div class="flex items-center gap-2">
      <UProgress
        :model-value="strength.value"
        :color="strength.color"
        size="sm"
        class="max-w-40"
      />
      <span class="text-xs text-muted">{{ strength.label }}</span>
    </div>

    <ul
      v-if="rules.length > 1"
      class="grid gap-1 sm:grid-cols-2"
    >
      <li
        v-for="rule in rules"
        :key="rule.label"
        class="flex items-center gap-1.5 text-xs"
        :class="rule.met ? 'text-success' : 'text-dimmed'"
      >
        <UIcon
          :name="rule.met ? 'i-lucide-circle-check' : 'i-lucide-circle-dashed'"
          class="size-3.5 shrink-0"
        />
        {{ rule.label }}
      </li>
    </ul>
  </div>
</template>
