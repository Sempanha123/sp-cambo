<script setup lang="ts">
const route = useRoute()

const privatePrefixes = ['/dashboard', '/admin', '/reseller']
const isPrivateSurface = computed(() => privatePrefixes.some(prefix => route.path.startsWith(prefix)))
const isHome = computed(() => route.path === '/')

const stars = Array.from({ length: 22 }, (_, index) => index)
const links = [
  { x1: 4, y1: 14, x2: 18, y2: 23, delay: '-1s' },
  { x1: 18, y1: 23, x2: 34, y2: 11, delay: '-2.4s' },
  { x1: 34, y1: 11, x2: 49, y2: 27, delay: '-0.7s' },
  { x1: 49, y1: 27, x2: 67, y2: 15, delay: '-3.1s' },
  { x1: 67, y1: 15, x2: 86, y2: 25, delay: '-1.8s' },
  { x1: 9, y1: 52, x2: 25, y2: 43, delay: '-4.0s' },
  { x1: 25, y1: 43, x2: 43, y2: 58, delay: '-1.1s' },
  { x1: 43, y1: 58, x2: 62, y2: 44, delay: '-2.8s' },
  { x1: 62, y1: 44, x2: 82, y2: 58, delay: '-0.3s' },
  { x1: 12, y1: 82, x2: 31, y2: 70, delay: '-3.5s' },
  { x1: 31, y1: 70, x2: 51, y2: 85, delay: '-1.6s' },
  { x1: 51, y1: 85, x2: 72, y2: 72, delay: '-4.4s' },
  { x1: 72, y1: 72, x2: 92, y2: 84, delay: '-2.1s' }
]
</script>

<template>
  <div
    class="sp-global-motion"
    :class="{
      'sp-global-motion--home': isHome,
      'sp-global-motion--calm': isPrivateSurface
    }"
    aria-hidden="true"
  >
    <div class="sp-global-base" />
    <div class="sp-global-grid" />
    <div class="sp-global-vignette" />

    <div class="sp-global-aurora sp-global-aurora--one" />
    <div class="sp-global-aurora sp-global-aurora--two" />
    <div class="sp-global-aurora sp-global-aurora--three" />

    <div class="sp-global-beam sp-global-beam--one" />
    <div class="sp-global-beam sp-global-beam--two" />

    <svg
      class="sp-global-network"
      viewBox="0 0 100 100"
      preserveAspectRatio="none"
    >
      <line
        v-for="(link, index) in links"
        :key="index"
        :x1="link.x1"
        :y1="link.y1"
        :x2="link.x2"
        :y2="link.y2"
        :style="{ animationDelay: link.delay }"
      />
    </svg>

    <span
      v-for="star in stars"
      :key="star"
      class="sp-global-star"
      :style="{ '--star-index': star }"
    />

    <div class="sp-global-scan" />
  </div>
</template>

<style>
.sp-global-motion {
  position: fixed;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
  background: var(--ui-bg);
  --sp-global-strength: 0.55;
}

.sp-global-motion--home {
  --sp-global-strength: 0.82;
}

.sp-global-motion--calm {
  --sp-global-strength: 0.34;
}

.sp-global-base,
.sp-global-grid,
.sp-global-vignette,
.sp-global-scan,
.sp-global-network,
.sp-global-aurora,
.sp-global-beam {
  position: absolute;
}

.sp-global-base {
  inset: 0;
  background:
    radial-gradient(circle at 8% 8%, rgb(68 105 255 / calc(0.10 * var(--sp-global-strength))), transparent 28rem),
    radial-gradient(circle at 92% 16%, rgb(129 78 255 / calc(0.085 * var(--sp-global-strength))), transparent 30rem),
    radial-gradient(circle at 54% 78%, rgb(52 184 255 / calc(0.065 * var(--sp-global-strength))), transparent 34rem);
}

.sp-global-grid {
  inset: 0;
  opacity: calc(0.26 * var(--sp-global-strength));
  background-image:
    linear-gradient(rgb(107 132 255 / 0.055) 1px, transparent 1px),
    linear-gradient(90deg, rgb(107 132 255 / 0.055) 1px, transparent 1px);
  background-size: 58px 58px;
  mask-image: linear-gradient(to bottom, black, transparent 92%);
}

.sp-global-vignette {
  inset: 0;
  background: radial-gradient(ellipse at center, transparent 48%, rgb(0 0 0 / 0.16) 120%);
}

.sp-global-aurora {
  left: -18%;
  width: 138%;
  height: 15rem;
  border-radius: 50%;
  opacity: calc(0.12 * var(--sp-global-strength));
  filter: blur(42px);
  mix-blend-mode: screen;
  will-change: transform;
}

.sp-global-aurora--one {
  top: 4%;
  background: linear-gradient(90deg, transparent, rgb(63 105 255 / 0.9), rgb(123 77 255 / 0.75), transparent);
  transform: rotate(-8deg);
  animation: sp-global-aurora-one 18s ease-in-out infinite alternate;
}

.sp-global-aurora--two {
  top: 42%;
  background: linear-gradient(90deg, transparent, rgb(42 189 255 / 0.72), rgb(78 103 255 / 0.78), transparent);
  transform: rotate(6deg);
  animation: sp-global-aurora-two 23s ease-in-out infinite alternate-reverse;
}

.sp-global-aurora--three {
  top: 78%;
  background: linear-gradient(90deg, transparent, rgb(126 77 255 / 0.68), rgb(53 181 255 / 0.55), transparent);
  transform: rotate(-4deg);
  animation: sp-global-aurora-three 27s ease-in-out infinite alternate;
}

.sp-global-beam {
  width: 1px;
  height: 54vh;
  opacity: calc(0.18 * var(--sp-global-strength));
  background: linear-gradient(to bottom, transparent, rgb(116 146 255 / 0.75), rgb(67 196 255 / 0.58), transparent);
  box-shadow: 0 0 28px rgb(74 125 255 / 0.36);
}

.sp-global-beam--one {
  left: 20%;
  top: 8%;
  transform: rotate(24deg);
  animation: sp-global-beam-one 19s ease-in-out infinite alternate;
}

.sp-global-beam--two {
  right: 18%;
  top: 49%;
  transform: rotate(-28deg);
  animation: sp-global-beam-two 22s ease-in-out infinite alternate-reverse;
}

.sp-global-network {
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: calc(0.2 * var(--sp-global-strength));
}

.sp-global-network line {
  stroke: rgb(111 140 255 / 0.42);
  stroke-width: 0.07;
  stroke-dasharray: 1.2 2.4;
  vector-effect: non-scaling-stroke;
  animation: sp-global-network-flow 7s linear infinite;
}

.sp-global-star {
  --sx: calc((var(--star-index) * 67) % 97);
  --sy: calc((var(--star-index) * 43) % 91);
  position: absolute;
  left: calc(var(--sx) * 1%);
  top: calc(var(--sy) * 1%);
  width: 3px;
  height: 3px;
  border-radius: 9999px;
  opacity: calc(0.6 * var(--sp-global-strength));
  background: rgb(147 167 255 / 0.68);
  box-shadow: 0 0 16px rgb(98 129 255 / 0.58);
  animation: sp-global-star-float calc(8s + (var(--star-index) * 0.36s)) ease-in-out infinite alternate;
}

.sp-global-star:nth-of-type(3n) {
  width: 2px;
  height: 2px;
  background: rgb(90 200 255 / 0.62);
}

.sp-global-scan {
  inset: 0;
  opacity: calc(0.08 * var(--sp-global-strength));
  background: repeating-linear-gradient(
    to bottom,
    transparent 0,
    transparent 10px,
    rgb(117 141 255 / 0.12) 11px,
    transparent 12px
  );
  animation: sp-global-scan 15s linear infinite;
}

/*
 * Existing layouts used opaque shell backgrounds. The global backdrop becomes
 * the single atmosphere layer, while pages/cards keep their own semantic
 * elevated surfaces for readability.
 */
.sp-global-stage .sp-shell-aurora,
.sp-global-stage .sp-auth-shell,
.sp-global-stage .sp-dashboard-shell {
  background-color: transparent !important;
}

.sp-global-stage .sp-shell-aurora {
  background-image:
    radial-gradient(ellipse 78% 52% at 12% -16%, var(--sp-aurora-primary-wash), transparent 72%),
    radial-gradient(ellipse 62% 46% at 96% 8%, var(--sp-aurora-secondary-wash), transparent 74%) !important;
}

.sp-global-stage .sp-site-header {
  background-color: color-mix(in oklab, var(--ui-bg) 78%, transparent);
  backdrop-filter: blur(18px) saturate(120%);
}

.sp-global-stage .sp-public-footer {
  background-color: color-mix(in oklab, var(--ui-bg) 62%, transparent) !important;
  backdrop-filter: blur(14px);
}

/* Keep dense private pages calmer while still showing the global atmosphere. */
.sp-global-motion--calm .sp-global-network,
.sp-global-motion--calm .sp-global-scan {
  opacity: 0.025;
}

.sp-global-motion--calm .sp-global-beam {
  opacity: 0.035;
}

@keyframes sp-global-aurora-one {
  from { transform: translate3d(-5%, 0, 0) rotate(-8deg) scaleX(0.92); }
  to { transform: translate3d(8%, 4rem, 0) rotate(-3deg) scaleX(1.08); }
}

@keyframes sp-global-aurora-two {
  from { transform: translate3d(7%, 0, 0) rotate(6deg) scaleX(1.05); }
  to { transform: translate3d(-7%, -3rem, 0) rotate(1deg) scaleX(0.92); }
}

@keyframes sp-global-aurora-three {
  from { transform: translate3d(-4%, 0, 0) rotate(-4deg); }
  to { transform: translate3d(6%, -4rem, 0) rotate(2deg); }
}

@keyframes sp-global-beam-one {
  from { transform: translate3d(-8rem, 0, 0) rotate(24deg); }
  to { transform: translate3d(10rem, 4rem, 0) rotate(24deg); }
}

@keyframes sp-global-beam-two {
  from { transform: translate3d(8rem, 0, 0) rotate(-28deg); }
  to { transform: translate3d(-10rem, -4rem, 0) rotate(-28deg); }
}

@keyframes sp-global-network-flow {
  from { stroke-dashoffset: 0; }
  to { stroke-dashoffset: -14; }
}

@keyframes sp-global-star-float {
  from { transform: translate3d(0, 0, 0); opacity: 0.12; }
  to { transform: translate3d(18px, -28px, 0); opacity: 0.72; }
}

@keyframes sp-global-scan {
  from { background-position-y: 0; }
  to { background-position-y: 260px; }
}

@media (max-width: 767px) {
  .sp-global-motion {
    --sp-global-strength: 0.38;
  }

  .sp-global-motion--home {
    --sp-global-strength: 0.5;
  }

  .sp-global-network,
  .sp-global-beam,
  .sp-global-scan {
    opacity: 0.025;
  }

  .sp-global-star:nth-child(n + 17) {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sp-global-motion *,
  .sp-global-motion *::before,
  .sp-global-motion *::after {
    animation-duration: 0.001ms !important;
    animation-iteration-count: 1 !important;
  }
}
</style>
