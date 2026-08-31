<script setup lang="ts">
const props = withDefaults(defineProps<{
  alias?: string | null
  label?: string | null
  size?: 'xs' | 'sm' | 'md'
  animated?: boolean
}>(), {
  alias: null,
  label: null,
  size: 'sm',
  animated: true
})

const presentation = computed(() => modelPresentation(props.alias, props.label))

const identity = computed(() => [
  props.alias,
  props.label,
  presentation.value.label,
  presentation.value.provider,
  presentation.value.brand
].filter(Boolean).join(' ').toLowerCase())

type AliasArtwork = 'claude' | 'gemini' | 'codex' | null

const artwork = computed<AliasArtwork>(() => {
  const value = identity.value
  const brand = String(presentation.value.brand ?? '').toLowerCase()

  if (
    brand === 'anthropic'
    || value.includes('anthropic')
    || value.includes('claude')
    || value.includes('opus')
    || value.includes('sonnet')
    || value.includes('haiku')
  ) return 'claude'

  if (
    brand === 'gemini'
    || value.includes('gemini')
    || value.includes('google ai')
  ) return 'gemini'

  if (
    brand === 'openai'
    || value.includes('openai')
    || value.includes('chatgpt')
    || value.includes('codex')
    || value.includes('gpt-')
    || value.includes('gpt ')
  ) return 'codex'

  return null
})

const src = computed(() => {
  if (artwork.value === 'claude') return '/model-alias-icons/claude_small_icon.gif'
  if (artwork.value === 'gemini') return '/model-alias-icons/gemini_small_icon.gif'
  if (artwork.value === 'codex') return '/model-alias-icons/codex_small_icon.gif'
  return null
})

const outerSize = computed(() => ({
  xs: 'size-5 rounded-md',
  sm: 'size-6 rounded-lg',
  md: 'size-8 rounded-[0.7rem]'
}[props.size]))

const imageSize = computed(() => ({
  xs: 'size-[88%] rounded-[0.28rem]',
  sm: 'size-[88%] rounded-md',
  md: 'size-[88%] rounded-lg'
}[props.size]))

const fallbackSize = computed(() => ({
  xs: 'size-3',
  sm: 'size-3.5',
  md: 'size-4'
}[props.size]))

const brandClass = computed(() => {
  if (artwork.value === 'claude') return 'sp-alias-icon--claude'
  if (artwork.value === 'gemini') return 'sp-alias-icon--gemini'
  if (artwork.value === 'codex') return 'sp-alias-icon--codex'
  return 'sp-alias-icon--fallback'
})
</script>

<template>
  <span
    class="sp-alias-icon inline-flex shrink-0 items-center justify-center"
    :class="[
      outerSize,
      brandClass,
      { 'sp-alias-icon--animated': animated && src },
      !src ? [presentation.surfaceClass, presentation.ringClass, 'border'] : []
    ]"
    :title="presentation.label"
    aria-hidden="true"
  >
    <template v-if="src">
      <span class="sp-alias-icon__glow" />
      <span class="sp-alias-icon__frame">
        <img
          :src="src"
          alt=""
          :class="imageSize"
          class="sp-alias-icon__image"
          loading="lazy"
          decoding="async"
        >
      </span>
    </template>

    <UIcon
      v-else
      :name="presentation.icon"
      :class="[fallbackSize, presentation.iconClass]"
    />
  </span>
</template>

<style scoped>
.sp-alias-icon {
  position: relative;
  isolation: isolate;
  overflow: visible;
}

.sp-alias-icon__glow {
  position: absolute;
  inset: -22%;
  z-index: -2;
  border-radius: 40%;
  opacity: .34;
  filter: blur(5px);
  transition: opacity .2s ease, transform .2s ease;
}

.sp-alias-icon__frame {
  position: relative;
  display: grid;
  width: 100%;
  height: 100%;
  place-items: center;
  overflow: hidden;
  border-radius: inherit;
  border: 1px solid rgb(255 255 255 / .11);
  background: color-mix(in oklab, var(--ui-bg-elevated) 70%, transparent);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .10),
    0 5px 14px -8px rgb(0 0 0 / .45);
}

.sp-alias-icon__image {
  display: block;
  object-fit: cover;
  transform: translateZ(0);
}

.sp-alias-icon--claude .sp-alias-icon__glow {
  background: radial-gradient(circle, rgb(242 137 88 / .66), transparent 70%);
}

.sp-alias-icon--claude .sp-alias-icon__frame {
  border-color: rgb(244 145 93 / .22);
}

.sp-alias-icon--gemini .sp-alias-icon__glow {
  background: radial-gradient(circle, rgb(122 99 255 / .64), rgb(78 151 255 / .24) 48%, transparent 72%);
}

.sp-alias-icon--gemini .sp-alias-icon__frame {
  border-color: rgb(123 110 255 / .24);
}

.sp-alias-icon--codex .sp-alias-icon__glow {
  background: radial-gradient(circle, rgb(65 231 183 / .62), transparent 70%);
}

.sp-alias-icon--codex .sp-alias-icon__frame {
  border-color: rgb(65 231 183 / .22);
}

.sp-alias-icon--animated .sp-alias-icon__frame {
  animation: sp-alias-icon-float 4.8s ease-in-out infinite;
}

.sp-alias-icon--animated.sp-alias-icon--claude .sp-alias-icon__frame {
  animation-delay: -1.2s;
}

.sp-alias-icon--animated.sp-alias-icon--gemini .sp-alias-icon__frame {
  animation-delay: -2.4s;
}

.sp-alias-icon--animated.sp-alias-icon--codex .sp-alias-icon__frame {
  animation-delay: -3.6s;
}

.sp-alias-icon:hover .sp-alias-icon__glow {
  opacity: .72;
  transform: scale(1.12);
}

@keyframes sp-alias-icon-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-1.5px); }
}

@media (prefers-reduced-motion: reduce) {
  .sp-alias-icon--animated .sp-alias-icon__frame {
    animation: none !important;
  }
}
</style>
