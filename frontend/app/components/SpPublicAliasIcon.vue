<script setup lang="ts">
const props = withDefaults(defineProps<{
  alias?: string | null
  label?: string | null
  size?: 'xs' | 'sm' | 'md'
}>(), {
  alias: null,
  label: null,
  size: 'sm'
})

const presentation = computed(() => modelPresentation(props.alias, props.label))
const identity = computed(() => `${props.alias ?? ''} ${props.label ?? ''} ${presentation.value.brand ?? ''}`.toLowerCase())

const artwork = computed(() => {
  const value = identity.value
  if (value.includes('claude') || value.includes('anthropic') || value.includes('opus') || value.includes('sonnet') || value.includes('haiku')) return 'claude'
  if (value.includes('gemini') || value.includes('google ai')) return 'gemini'
  if (value.includes('gpt') || value.includes('openai') || value.includes('codex') || value.includes('chatgpt')) return 'codex'
  return null
})

const src = computed(() => {
  if (artwork.value === 'claude') return '/model-alias-icons/claude_small_icon.gif'
  if (artwork.value === 'gemini') return '/model-alias-icons/gemini_small_icon.gif'
  if (artwork.value === 'codex') return '/model-alias-icons/codex_small_icon.gif'
  return null
})

const outerClass = computed(() => ({
  xs: 'size-5 rounded-md',
  sm: 'size-6 rounded-lg',
  md: 'size-8 rounded-[0.65rem]'
}[props.size]))

const fallbackClass = computed(() => ({
  xs: 'size-3',
  sm: 'size-3.5',
  md: 'size-4'
}[props.size]))
</script>

<template>
  <span
    class="sp-r9-alias-icon"
    :class="[outerClass, `sp-r9-alias-icon--${artwork ?? 'fallback'}`]"
    aria-hidden="true"
  >
    <img
      v-if="src"
      :src="src"
      alt=""
      class="size-full object-cover"
      loading="lazy"
      decoding="async"
    >

    <UIcon
      v-else
      :name="presentation.icon"
      :class="[fallbackClass, presentation.iconClass]"
    />
  </span>
</template>

<style scoped>
.sp-r9-alias-icon {
  position: relative;
  display: inline-grid;
  flex: none;
  place-items: center;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / .06);
  background: color-mix(in oklab, var(--ui-bg-elevated) 62%, transparent);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .035);
}

.sp-r9-alias-icon--claude { box-shadow: inset 0 1px 0 rgb(255 255 255 / .04), 0 5px 12px -9px rgb(237 126 69 / .5); }
.sp-r9-alias-icon--gemini { box-shadow: inset 0 1px 0 rgb(255 255 255 / .04), 0 5px 12px -9px rgb(84 114 255 / .52); }
.sp-r9-alias-icon--codex { box-shadow: inset 0 1px 0 rgb(255 255 255 / .04), 0 5px 12px -9px rgb(20 187 144 / .48); }
</style>
