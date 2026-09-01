<script setup lang="ts">
const route = useRoute()

const privatePrefixes = ['/dashboard', '/admin', '/reseller']
const isPrivateSurface = computed(() => privatePrefixes.some(prefix => route.path.startsWith(prefix)))
const isHome = computed(() => route.path === '/')
const isShowcase = computed(() => route.path === '/models' || route.path === '/pricing')

const stars = Array.from({ length: 26 }, (_, index) => ({
  id: index,
  x: (index * 67) % 97,
  y: (index * 43) % 91,
  duration: 8 + (index * 0.31),
  delay: -(index * 0.19)
}))

const particles = Array.from({ length: 12 }, (_, index) => ({
  id: index,
  x: (index * 79) % 92,
  y: (index * 37) % 86,
  duration: 12 + (index * 0.6),
  delay: -(index * 0.37)
}))

const links = [
  { x1: 3, y1: 14, x2: 18, y2: 23, delay: '-1s' },
  { x1: 18, y1: 23, x2: 34, y2: 11, delay: '-2.4s' },
  { x1: 34, y1: 11, x2: 49, y2: 27, delay: '-0.7s' },
  { x1: 49, y1: 27, x2: 67, y2: 15, delay: '-3.1s' },
  { x1: 67, y1: 15, x2: 87, y2: 24, delay: '-1.8s' },
  { x1: 8, y1: 52, x2: 25, y2: 43, delay: '-4s' },
  { x1: 25, y1: 43, x2: 43, y2: 58, delay: '-1.1s' },
  { x1: 43, y1: 58, x2: 62, y2: 44, delay: '-2.8s' },
  { x1: 62, y1: 44, x2: 83, y2: 58, delay: '-0.3s' },
  { x1: 11, y1: 82, x2: 31, y2: 70, delay: '-3.5s' },
  { x1: 31, y1: 70, x2: 51, y2: 85, delay: '-1.6s' },
  { x1: 51, y1: 85, x2: 72, y2: 72, delay: '-4.4s' },
  { x1: 72, y1: 72, x2: 92, y2: 84, delay: '-2.1s' }
]
</script>

<template>
  <div
    class="sp-r8-bg sp-r12-bg"
    :class="{
      'sp-r8-bg--home': isHome,
      'sp-r8-bg--showcase': isShowcase,
      'sp-r8-bg--private': isPrivateSurface
    }"
    aria-hidden="true"
  >
    <div class="sp-r8-bg__base" />
    <div class="sp-r8-bg__mesh" />
    <div class="sp-r8-bg__vignette" />
    <div class="sp-r12-bg__micro-grid" />
    <div class="sp-r12-bg__beam" />
    <div class="sp-r12-bg__hud sp-r12-bg__hud--top" />
    <div class="sp-r12-bg__hud sp-r12-bg__hud--bottom" />

    <div class="sp-r8-bg__aurora sp-r8-bg__aurora--a" />
    <div class="sp-r8-bg__aurora sp-r8-bg__aurora--b" />
    <div class="sp-r8-bg__aurora sp-r8-bg__aurora--c" />

    <div class="sp-r8-bg__wave sp-r8-bg__wave--a" />
    <div class="sp-r8-bg__wave sp-r8-bg__wave--b" />
    <div class="sp-r8-bg__wave sp-r8-bg__wave--c" />

    <svg
      class="sp-r8-bg__network"
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
      :key="`star-${star.id}`"
      class="sp-r8-bg__star"
      :style="{
        left: `${star.x}%`,
        top: `${star.y}%`,
        animationDuration: `${star.duration}s`,
        animationDelay: `${star.delay}s`
      }"
    />

    <span
      v-for="particle in particles"
      :key="`particle-${particle.id}`"
      class="sp-r8-bg__particle"
      :style="{
        left: `${particle.x}%`,
        top: `${particle.y}%`,
        animationDuration: `${particle.duration}s`,
        animationDelay: `${particle.delay}s`
      }"
    />

    <div class="sp-r8-bg__scan" />
  </div>
</template>

<style>
.sp-r8-bg {
  --sp-r8-strength: .52;
  position: fixed;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
  background: var(--ui-bg);
}

.sp-r8-bg--home {
  --sp-r8-strength: .82;
}

.sp-r8-bg--showcase {
  --sp-r8-strength: .68;
}

.sp-r8-bg--private {
  --sp-r8-strength: .28;
}

.sp-r8-bg__base,
.sp-r8-bg__mesh,
.sp-r8-bg__vignette,
.sp-r8-bg__aurora,
.sp-r8-bg__wave,
.sp-r8-bg__network,
.sp-r8-bg__scan {
  position: absolute;
}

.sp-r8-bg__base {
  inset: 0;
  background:
    radial-gradient(circle at 8% 8%, rgb(45 102 255 / calc(.095 * var(--sp-r8-strength))), transparent 29rem),
    radial-gradient(circle at 92% 14%, rgb(114 63 255 / calc(.078 * var(--sp-r8-strength))), transparent 31rem),
    radial-gradient(circle at 50% 78%, rgb(18 171 255 / calc(.052 * var(--sp-r8-strength))), transparent 34rem),
    linear-gradient(to bottom, rgb(3 14 34 / .14), transparent 38rem);
}

.sp-r8-bg__mesh {
  inset: 0;
  opacity: calc(.19 * var(--sp-r8-strength));
  background-image:
    linear-gradient(rgb(92 125 255 / .05) 1px, transparent 1px),
    linear-gradient(90deg, rgb(92 125 255 / .05) 1px, transparent 1px);
  background-size: 64px 64px;
  mask-image: linear-gradient(to bottom, black 0%, black 48%, transparent 95%);
}

.sp-r8-bg__vignette {
  inset: 0;
  background:
    radial-gradient(ellipse at 50% 10%, transparent 0 36%, rgb(0 0 0 / .06) 100%),
    linear-gradient(to bottom, transparent 0 70%, rgb(0 0 0 / .07));
}

.sp-r8-bg__aurora {
  left: -18%;
  width: 136%;
  height: 13rem;
  border-radius: 50%;
  filter: blur(44px);
  mix-blend-mode: screen;
  opacity: calc(.09 * var(--sp-r8-strength));
  will-change: transform;
}

.sp-r8-bg__aurora--a {
  top: 4%;
  background: linear-gradient(90deg, transparent, rgb(48 105 255 / .78), rgb(121 70 255 / .64), transparent);
  transform: rotate(-8deg);
  animation: sp-r8-aurora-a 18s ease-in-out infinite alternate;
}

.sp-r8-bg__aurora--b {
  top: 39%;
  background: linear-gradient(90deg, transparent, rgb(27 179 255 / .58), rgb(67 95 255 / .68), transparent);
  transform: rotate(6deg);
  animation: sp-r8-aurora-b 23s ease-in-out infinite alternate-reverse;
}

.sp-r8-bg__aurora--c {
  top: 76%;
  background: linear-gradient(90deg, transparent, rgb(111 68 255 / .52), rgb(31 172 255 / .45), transparent);
  transform: rotate(-3deg);
  animation: sp-r8-aurora-c 28s ease-in-out infinite alternate;
}

/*
 * Lively curved light paths inspired by the index-page motion language.
 * They are CSS-only, intentionally dim so long pages remain comfortable.
 */
.sp-r8-bg__wave {
  width: 72rem;
  height: 18rem;
  border: 1px solid transparent;
  border-top-color: rgb(74 126 255 / calc(.22 * var(--sp-r8-strength)));
  border-radius: 50%;
  filter: drop-shadow(0 0 7px rgb(57 115 255 / .18));
}

.sp-r8-bg__wave::after {
  position: absolute;
  content: "";
  inset: 12px 4%;
  border: 1px solid transparent;
  border-top-color: rgb(100 72 255 / calc(.14 * var(--sp-r8-strength)));
  border-radius: 50%;
}

.sp-r8-bg__wave--a {
  left: -18rem;
  top: 19%;
  transform: rotate(-9deg);
  animation: sp-r8-wave-a 19s ease-in-out infinite alternate;
}

.sp-r8-bg__wave--b {
  right: -20rem;
  top: 46%;
  transform: rotate(8deg);
  animation: sp-r8-wave-b 24s ease-in-out infinite alternate-reverse;
}

.sp-r8-bg__wave--c {
  left: 16%;
  bottom: -7rem;
  transform: rotate(-4deg) scale(.9);
  animation: sp-r8-wave-c 27s ease-in-out infinite alternate;
}

.sp-r8-bg__network {
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: calc(.12 * var(--sp-r8-strength));
}

.sp-r8-bg__network line {
  stroke: rgb(100 138 255 / .34);
  stroke-width: .065;
  stroke-dasharray: 1.2 2.8;
  vector-effect: non-scaling-stroke;
  animation: sp-r8-network 8s linear infinite;
}

.sp-r8-bg__star {
  position: absolute;
  width: 2px;
  height: 2px;
  border-radius: 9999px;
  opacity: calc(.52 * var(--sp-r8-strength));
  background: rgb(151 177 255 / .76);
  box-shadow: 0 0 12px rgb(87 130 255 / .55);
  animation-name: sp-r8-star;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
  animation-direction: alternate;
}

.sp-r8-bg__star:nth-of-type(4n) {
  width: 3px;
  height: 3px;
  background: rgb(73 194 255 / .72);
}

.sp-r8-bg__particle {
  position: absolute;
  width: 5px;
  height: 5px;
  border: 1px solid rgb(115 151 255 / calc(.28 * var(--sp-r8-strength)));
  border-radius: 9999px;
  opacity: calc(.38 * var(--sp-r8-strength));
  animation-name: sp-r8-particle;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
  animation-direction: alternate;
}

.sp-r8-bg__scan {
  inset: 0;
  opacity: calc(.035 * var(--sp-r8-strength));
  background: repeating-linear-gradient(
    to bottom,
    transparent 0,
    transparent 12px,
    rgb(111 145 255 / .11) 13px,
    transparent 14px
  );
  animation: sp-r8-scan 18s linear infinite;
}

/* Existing page shells become transparent enough for the shared atmosphere. */
.sp-global-stage .sp-shell-aurora,
.sp-global-stage .sp-auth-shell,
.sp-global-stage .sp-dashboard-shell {
  background-color: transparent !important;
}

.sp-global-stage .sp-site-header {
  background-color: color-mix(in oklab, var(--ui-bg) 82%, transparent) !important;
  backdrop-filter: blur(18px) saturate(118%);
}

.sp-global-stage .sp-public-footer {
  background-color: color-mix(in oklab, var(--ui-bg) 70%, transparent) !important;
  backdrop-filter: blur(14px);
}

/* Dashboard stays calm and readable. */
.sp-r8-bg--private .sp-r8-bg__network,
.sp-r8-bg--private .sp-r8-bg__scan,
.sp-r8-bg--private .sp-r8-bg__particle {
  opacity: .018;
}

.sp-r8-bg--private .sp-r8-bg__wave {
  opacity: .30;
}

@keyframes sp-r8-aurora-a {
  from { transform: translate3d(-5%, 0, 0) rotate(-8deg) scaleX(.94); }
  to { transform: translate3d(7%, 3.5rem, 0) rotate(-4deg) scaleX(1.06); }
}

@keyframes sp-r8-aurora-b {
  from { transform: translate3d(7%, 0, 0) rotate(6deg) scaleX(1.04); }
  to { transform: translate3d(-7%, -3rem, 0) rotate(2deg) scaleX(.93); }
}

@keyframes sp-r8-aurora-c {
  from { transform: translate3d(-3%, 0, 0) rotate(-3deg); }
  to { transform: translate3d(5%, -4rem, 0) rotate(2deg); }
}

@keyframes sp-r8-wave-a {
  from { transform: translate3d(-4rem, 0, 0) rotate(-9deg) scaleX(.95); }
  to { transform: translate3d(7rem, 2rem, 0) rotate(-5deg) scaleX(1.06); }
}

@keyframes sp-r8-wave-b {
  from { transform: translate3d(5rem, 0, 0) rotate(8deg); }
  to { transform: translate3d(-8rem, -2rem, 0) rotate(4deg); }
}

@keyframes sp-r8-wave-c {
  from { transform: translate3d(-3rem, 0, 0) rotate(-4deg) scale(.88); }
  to { transform: translate3d(5rem, -2rem, 0) rotate(1deg) scale(.96); }
}

@keyframes sp-r8-network {
  from { stroke-dashoffset: 0; }
  to { stroke-dashoffset: -16; }
}

@keyframes sp-r8-star {
  from { transform: translate3d(0, 0, 0); opacity: .08; }
  to { transform: translate3d(14px, -20px, 0); opacity: .62; }
}

@keyframes sp-r8-particle {
  from { transform: translate3d(0, 0, 0) scale(.75); }
  to { transform: translate3d(22px, -34px, 0) scale(1.08); }
}

@keyframes sp-r8-scan {
  from { background-position-y: 0; }
  to { background-position-y: 280px; }
}

@media (max-width: 767px) {
  .sp-r8-bg {
    --sp-r8-strength: .34;
  }

  .sp-r8-bg--home,
  .sp-r8-bg--showcase {
    --sp-r8-strength: .45;
  }

  .sp-r8-bg__network,
  .sp-r8-bg__scan {
    opacity: .02;
  }

  .sp-r8-bg__wave {
    opacity: .35;
  }

  .sp-r8-bg__particle:nth-child(n + 7) {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sp-r8-bg *,
  .sp-r8-bg *::before,
  .sp-r8-bg *::after {
    animation-duration: .001ms !important;
    animation-iteration-count: 1 !important;
  }
}
</style>
