<script setup lang="ts">
const props = withDefaults(defineProps<{
  model?: string | null
  label?: string | null
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
  animated?: boolean
}>(), {
  model: null,
  label: null,
  size: 'md',
  animated: true
})

const presentation = computed(() => modelPresentation(props.model, props.label))

const identity = computed(() => [
  props.model,
  props.label,
  presentation.value.label,
  presentation.value.provider,
  presentation.value.brand
].filter(Boolean).join(' ').toLowerCase())

type ModelArtwork = 'claude' | 'gemini' | 'codex' | 'deepseek' | null

const artwork = computed<ModelArtwork>(() => {
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
    brand === 'deepseek'
    || value.includes('deepseek')
    || value.includes('deepsek')
  ) return 'deepseek'

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

const imageSrc = computed(() => {
  if (artwork.value === 'claude') return '/model-icons/claude_icon.gif'
  if (artwork.value === 'gemini') return '/model-icons/gemini_icon.gif'
  if (artwork.value === 'codex') return '/model-icons/codex_icon.gif'
  if (artwork.value === 'deepseek') return '/model-icons/deepseek_icon.gif'
  return null
})

const sizeClass = computed(() => ({
  xs: 'size-6 rounded-lg',
  sm: 'size-8 rounded-xl',
  md: 'size-10 rounded-[0.85rem]',
  lg: 'size-12 rounded-2xl',
  xl: 'size-16 rounded-[1.35rem]'
}[props.size]))

const imageClass = computed(() => ({
  xs: 'size-[88%] rounded-md',
  sm: 'size-[88%] rounded-lg',
  md: 'size-[88%] rounded-xl',
  lg: 'size-[88%] rounded-[0.9rem]',
  xl: 'size-[88%] rounded-[1.1rem]'
}[props.size]))

const fallbackIconClass = computed(() => ({
  xs: 'size-3.5',
  sm: 'size-4',
  md: 'size-5',
  lg: 'size-6',
  xl: 'size-8'
}[props.size]))

const brandClass = computed(() => {
  if (artwork.value === 'claude') return 'sp-model-art--claude'
  if (artwork.value === 'gemini') return 'sp-model-art--gemini'
  if (artwork.value === 'codex') return 'sp-model-art--codex'
  if (artwork.value === 'deepseek') return 'sp-model-art--deepseek'
  return 'sp-model-art--fallback'
})
</script>

<template>
  <span
    class="sp-model-art inline-flex shrink-0 items-center justify-center"
    :class="[
      sizeClass,
      brandClass,
      { 'sp-model-art--animated': animated && imageSrc },
      !imageSrc ? [presentation.surfaceClass, presentation.ringClass, 'border shadow-sm shadow-black/5'] : []
    ]"
    :title="presentation.label"
    aria-hidden="true"
  >
    <template v-if="imageSrc">
      <span class="sp-model-art__glow" />
      <span class="sp-model-art__frame">
        <img
          :src="imageSrc"
          alt=""
          :class="imageClass"
          class="sp-model-art__image"
          loading="lazy"
          decoding="async"
        >
        <span class="sp-model-art__shine" />
      </span>
    </template>

    <UIcon
      v-else
      :name="presentation.icon"
      :class="[fallbackIconClass, presentation.iconClass]"
    />
  </span>
</template>

<style scoped>
.sp-model-art {
  position: relative;
  isolation: isolate;
  overflow: visible;
  vertical-align: middle;
  transform: translateZ(0);
}

.sp-model-art__glow {
  position: absolute;
  inset: -22%;
  z-index: -2;
  border-radius: 32%;
  opacity: .42;
  filter: blur(9px);
  transform: scale(.78);
  transition: opacity .25s ease, transform .25s ease;
}

.sp-model-art__frame {
  position: relative;
  display: grid;
  width: 100%;
  height: 100%;
  place-items: center;
  overflow: hidden;
  border: 1px solid color-mix(in oklab, white 13%, transparent);
  border-radius: inherit;
  background: color-mix(in oklab, var(--ui-bg-elevated) 62%, transparent);
  box-shadow:
    inset 0 1px 0 color-mix(in oklab, white 14%, transparent),
    inset 0 -1px 0 color-mix(in oklab, black 10%, transparent),
    0 8px 22px -11px color-mix(in oklab, var(--ui-primary) 36%, transparent);
  backdrop-filter: blur(10px);
}

.sp-model-art__frame::before {
  position: absolute;
  content: "";
  inset: -1px;
  z-index: 3;
  border-radius: inherit;
  pointer-events: none;
  padding: 1px;
  background: linear-gradient(
    130deg,
    color-mix(in oklab, white 26%, transparent),
    transparent 35%,
    transparent 70%,
    color-mix(in oklab, var(--ui-primary) 28%, transparent)
  );
  mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  mask-composite: exclude;
}

.sp-model-art__image {
  position: relative;
  z-index: 1;
  display: block;
  object-fit: cover;
  transform: scale(1);
  box-shadow: 0 9px 20px -12px rgb(0 0 0 / .42);
  transition: transform .32s cubic-bezier(.2,.8,.2,1), filter .32s ease;
}

.sp-model-art__shine {
  position: absolute;
  z-index: 4;
  top: -35%;
  bottom: -35%;
  left: -75%;
  width: 44%;
  pointer-events: none;
  opacity: 0;
  background: linear-gradient(90deg, transparent, rgb(255 255 255 / .25), transparent);
  transform: rotate(18deg);
}

/* User-provided DeepSeek artwork */
.sp-model-art--deepseek .sp-model-art__glow {
  background: radial-gradient(circle, rgb(56 189 248 / .55), transparent 68%);
}

.sp-model-art--deepseek .sp-model-art__frame {
  border-color: rgb(56 189 248 / .24);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .16),
    0 9px 24px -13px rgb(14 165 233 / .58);
}

/* User-provided Claude artwork */
.sp-model-art--claude .sp-model-art__glow {
  background: radial-gradient(circle, rgb(246 142 91 / .55), transparent 68%);
}

.sp-model-art--claude .sp-model-art__frame {
  border-color: rgb(244 145 93 / .24);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .17),
    0 9px 23px -11px rgb(237 126 69 / .60);
}

/* User-provided Gemini artwork */
.sp-model-art--gemini .sp-model-art__glow {
  background: radial-gradient(circle, rgb(91 132 255 / .58), rgb(145 105 255 / .23) 42%, transparent 70%);
}

.sp-model-art--gemini .sp-model-art__frame {
  border-color: rgb(112 139 255 / .27);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .17),
    0 9px 23px -11px rgb(84 114 255 / .62);
}

/* User-provided OpenAI/Codex artwork */
.sp-model-art--codex .sp-model-art__glow {
  background: radial-gradient(circle, rgb(27 205 162 / .52), transparent 69%);
}

.sp-model-art--codex .sp-model-art__frame {
  border-color: rgb(29 207 163 / .25);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / .17),
    0 9px 23px -11px rgb(20 187 144 / .58);
}

.sp-model-art:hover .sp-model-art__glow {
  opacity: .76;
  transform: scale(1.02);
}

.sp-model-art:hover .sp-model-art__image {
  transform: scale(1.06);
  filter: saturate(1.08) contrast(1.02);
}

.sp-model-art:hover .sp-model-art__shine {
  opacity: 1;
  animation: sp-model-art-shine 1.05s ease-out;
}

.sp-model-art--animated .sp-model-art__frame {
  animation: sp-model-art-float 4.7s ease-in-out infinite;
}

.sp-model-art--animated.sp-model-art--claude .sp-model-art__frame {
  animation-delay: -1.1s;
}

.sp-model-art--animated.sp-model-art--gemini .sp-model-art__frame {
  animation-delay: -2.25s;
}

.sp-model-art--animated.sp-model-art--codex .sp-model-art__frame {
  animation-delay: -3.4s;
}

@keyframes sp-model-art-float {
  0%, 100% {
    transform: translate3d(0, 0, 0) rotate(0deg);
  }
  50% {
    transform: translate3d(0, -2px, 0) rotate(.7deg);
  }
}

@keyframes sp-model-art-shine {
  from { left: -75%; }
  to { left: 135%; }
}

@media (max-width: 767px) {
  .sp-model-art--animated .sp-model-art__frame {
    animation-duration: 6.5s;
  }

  .sp-model-art__glow {
    opacity: .30;
    filter: blur(7px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .sp-model-art--animated .sp-model-art__frame,
  .sp-model-art__shine {
    animation: none !important;
  }
}
</style>
