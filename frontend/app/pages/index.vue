<script setup lang="ts">
useSeoMeta({
  title: 'Managed AI access with prepaid, metered billing',
  description: 'SP Cambo gives your team one endpoint for managed AI models: prepaid token and credit packages, scoped API keys, and exact per-request metering for Claude Code, Codex CLI and your own SDK code.',
  ogTitle: 'SP Cambo — managed AI access, prepaid and metered'
})

const auth = useAuthStore()
const { claudeCodeShell } = useCliSnippets()

const heroNodes = [
  {
    className: 'sp-orbit-node--api',
    icon: 'i-lucide-braces',
    label: 'API'
  },
  {
    className: 'sp-orbit-node--code',
    icon: 'i-lucide-code-xml',
    label: 'Code'
  },
  {
    className: 'sp-orbit-node--chart',
    icon: 'i-lucide-chart-no-axes-combined',
    label: 'Usage'
  },
  {
    className: 'sp-orbit-node--shield',
    icon: 'i-lucide-shield-check',
    label: 'Secure'
  }
]

const liveSignals = [
  { icon: 'i-lucide-zap', label: 'Fast routing' },
  { icon: 'i-lucide-shield-check', label: 'Scoped keys' },
  { icon: 'i-lucide-wallet-cards', label: 'Prepaid access' }
]

const pillars = [
  {
    icon: 'i-lucide-key-round',
    title: 'Scoped API keys',
    description: 'Issue keys per project or package. Lists stay masked, while the signed-in owner can securely re-copy the current encrypted secret. Rotate or revoke instantly.',
    tone: 'violet'
  },
  {
    icon: 'i-lucide-gauge',
    title: 'Exact metering',
    description: 'Every request reserves quota, then settles against the usage the provider actually reported. Interim numbers are labelled as estimates until settlement.',
    tone: 'cyan'
  },
  {
    icon: 'i-lucide-wallet',
    title: 'Prepaid, no surprises',
    description: 'Buy a token or credit package up front. When a package is spent or expires, requests stop — there is no overage bill to discover later.',
    tone: 'blue'
  },
  {
    icon: 'i-lucide-shield-check',
    title: 'Provider isolation',
    description: 'Your key never reaches an upstream provider, and upstream credentials never reach your machine. SP Cambo terminates auth at its own gateway.',
    tone: 'emerald'
  },
  {
    icon: 'i-lucide-clock',
    title: 'Time-boxed access',
    description: 'Packages carry an exact lifetime in seconds from activation. A one-day package means 24 hours, not “until the end of tomorrow”.',
    tone: 'amber'
  },
  {
    icon: 'i-lucide-scroll-text',
    title: 'Auditable ledger',
    description: 'Purchases, reservations, settlements and expiries are recorded as immutable ledger entries you can reconcile against your own records.',
    tone: 'rose'
  }
]

const steps = [
  {
    title: 'Create your account',
    description: 'Email and password. No provider account, no credit card on file.',
    icon: 'i-lucide-user-plus'
  },
  {
    title: 'Buy a package',
    description: 'Pay with Bakong KHQR. Access activates as soon as the payment is verified by our backend.',
    icon: 'i-lucide-qr-code'
  },
  {
    title: 'Issue an API key',
    description: 'Scope it to the models you need and copy the secret once.',
    icon: 'i-lucide-key-round'
  },
  {
    title: 'Connect your tools',
    description: 'Set two environment variables for Claude Code, or one provider block for Codex CLI.',
    icon: 'i-lucide-terminal'
  }
]

const audiences = [
  {
    title: 'Developers',
    description: 'Drop-in compatible endpoints for the Anthropic Messages API and the OpenAI Responses API, with streaming and tool calls.',
    to: '/developers',
    label: 'Developer overview',
    icon: 'i-lucide-code-xml'
  },
  {
    title: 'Teams',
    description: 'One prepaid balance, per-key scoping, and usage visibility per model and per key.',
    to: '/models',
    label: 'Browse models',
    icon: 'i-lucide-users'
  },
  {
    title: 'Resellers',
    description: 'Allocate entitlements to your own customers under verified commercial-use terms.',
    to: '/resellers',
    label: 'Reseller programme',
    icon: 'i-lucide-handshake'
  }
]


const marqueeItems = [
  { icon: 'i-lucide-zap', label: 'Streaming responses' },
  { icon: 'i-lucide-key-round', label: 'Scoped API keys' },
  { icon: 'i-lucide-terminal', label: 'CLI ready' },
  { icon: 'i-lucide-braces', label: 'SDK compatible' },
  { icon: 'i-lucide-gauge', label: 'Exact metering' },
  { icon: 'i-lucide-shield-check', label: 'Protected credentials' },
  { icon: 'i-lucide-qr-code', label: 'Bakong KHQR' },
  { icon: 'i-lucide-clock', label: 'Time-boxed access' }
]


const gatewayHighlights = [
  {
    value: '1',
    label: 'Base URL',
    note: 'One SP Cambo endpoint'
  },
  {
    value: 'SSE',
    label: 'Streaming',
    note: 'Live incremental output'
  },
  {
    value: 'KEY',
    label: 'Scoped access',
    note: 'Control model permissions'
  },
  {
    value: 'LEDGER',
    label: 'Metering',
    note: 'Reserve, settle, reconcile'
  }
]

const gatewayFamilies = [
  { label: 'GPT', icon: 'i-simple-icons-openai' },
  { label: 'Claude', icon: 'i-simple-icons-anthropic' },
  { label: 'Gemini', icon: 'i-simple-icons-googlegemini' },
  { label: 'Codex', icon: 'i-lucide-terminal' }
]

const heroPointerStyle = ref<Record<string, string>>({
  '--hero-mx': '68%',
  '--hero-my': '40%',
  '--hero-rx': '0deg',
  '--hero-ry': '0deg'
})

const handleHeroPointerMove = (event: PointerEvent) => {
  const element = event.currentTarget as HTMLElement | null
  if (!element) return

  const rect = element.getBoundingClientRect()
  const x = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width))
  const y = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height))

  heroPointerStyle.value = {
    '--hero-mx': `${(x * 100).toFixed(1)}%`,
    '--hero-my': `${(y * 100).toFixed(1)}%`,
    '--hero-rx': `${((0.5 - y) * 5).toFixed(2)}deg`,
    '--hero-ry': `${((x - 0.5) * 7).toFixed(2)}deg`
  }
}

const resetHeroPointer = () => {
  heroPointerStyle.value = {
    '--hero-mx': '68%',
    '--hero-my': '40%',
    '--hero-rx': '0deg',
    '--hero-ry': '0deg'
  }
}

</script>

<template>
  <div class="sp-motion-page">
    <!-- Global animated atmosphere is rendered once by SpGlobalMotionBackground in app.vue. -->

    <!-- HERO -->
    <section class="sp-public-hero relative overflow-hidden">
      <UContainer class="py-10 sm:py-14 lg:py-16">
        <div
          class="sp-live-hero relative isolate overflow-hidden rounded-[2rem] border border-default/60 px-6 py-10 shadow-2xl shadow-primary/5 sm:px-9 lg:grid lg:grid-cols-[1.04fr_0.96fr] lg:items-center lg:gap-12 lg:px-12 lg:py-14"
          :style="heroPointerStyle"
          @pointermove="handleHeroPointerMove"
          @pointerleave="resetHeroPointer"
        >
          <div class="sp-hero-sheen pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
          <div class="sp-hero-spotlight pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
          <div class="sp-hero-noise pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
          <div class="sp-hero-laser sp-hero-laser--one pointer-events-none" aria-hidden="true" />
          <div class="sp-hero-laser sp-hero-laser--two pointer-events-none" aria-hidden="true" />

          <div class="relative z-10 space-y-7">
            <div class="flex flex-wrap items-center gap-3">
              <UBadge
                color="neutral"
                variant="subtle"
                size="lg"
                class="sp-live-badge rounded-full"
              >
                <span class="flex items-center gap-2">
                  <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60" />
                    <span class="relative inline-flex size-2 rounded-full bg-success" />
                  </span>
                  AI POWERED SOLUTIONS
                </span>
              </UBadge>

              <span class="sp-mini-signal hidden items-center gap-2 rounded-full border border-default/70 bg-default/65 px-3 py-1.5 text-xs text-muted backdrop-blur sm:inline-flex">
                <UIcon name="i-lucide-radio-tower" class="size-3.5 text-primary" />
                Live managed access
              </span>
            </div>

            <div class="space-y-4">
              <h1 class="max-w-3xl text-4xl font-semibold tracking-tight text-highlighted text-balance sm:text-5xl lg:text-6xl xl:text-[4.25rem] xl:leading-[1.03]">
                Premium
                <span class="sp-live-gradient-text">AI packages</span>
                <br>
                & API access
              </h1>

              <p class="max-w-xl text-base leading-7 text-muted text-pretty sm:text-lg sm:leading-8">
                High-performance managed AI models with simple prepaid pricing.
                Buy access, issue a scoped API key and connect your apps or CLI
                tools to one endpoint.
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
              <UButton
                v-if="auth.authenticated"
                to="/dashboard"
                size="xl"
                trailing-icon="i-lucide-arrow-right"
                class="sp-primary-cta"
              >
                Open dashboard
              </UButton>

              <template v-else>
                <UButton
                  to="/register"
                  size="xl"
                  trailing-icon="i-lucide-arrow-right"
                  class="sp-primary-cta"
                >
                  Create account
                </UButton>

                <UButton
                  to="/pricing"
                  size="xl"
                  color="neutral"
                  variant="subtle"
                  class="sp-secondary-cta"
                >
                  View pricing
                </UButton>

                <UButton
                  to="/public/key-checker"
                  size="xl"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-key-round"
                >
                  Check API key
                </UButton>
              </template>
            </div>

            <div class="grid max-w-xl gap-2.5 sm:grid-cols-3">
              <div
                v-for="signal in liveSignals"
                :key="signal.label"
                class="sp-signal-card flex items-center gap-2.5 rounded-xl border border-default/60 bg-default/45 px-3 py-2.5 text-xs text-muted backdrop-blur"
              >
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-primary/15 bg-primary/10 text-primary">
                  <UIcon :name="signal.icon" class="size-4" />
                </span>
                <span class="font-medium text-toned">{{ signal.label }}</span>
              </div>
            </div>

            <ul class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
              <li class="flex items-center gap-2">
                <UIcon name="i-lucide-check" class="size-4 text-primary" />
                No subscription
              </li>
              <li class="flex items-center gap-2">
                <UIcon name="i-lucide-check" class="size-4 text-primary" />
                No overage billing
              </li>
              <li class="flex items-center gap-2">
                <UIcon name="i-lucide-check" class="size-4 text-primary" />
                Bakong KHQR payment
              </li>
            </ul>
          </div>

          <!-- No external image: this whole visual is CSS + icons + current SpBrandMark. -->
          <div class="relative mt-10 lg:mt-0">
            <div class="sp-live-visual mx-auto hidden min-h-[420px] w-full max-w-[520px] lg:block" aria-hidden="true">
              <div class="sp-visual-halo sp-visual-halo--one" />
              <div class="sp-visual-halo sp-visual-halo--two" />
              <div class="sp-orbit sp-orbit--one" />
              <div class="sp-orbit sp-orbit--two" />
              <div class="sp-orbit sp-orbit--three" />

              <div class="sp-core-shadow" />
              <div class="sp-live-core">
                <div class="sp-live-core-layer sp-live-core-layer--back" />
                <div class="sp-live-core-layer sp-live-core-layer--middle" />
                <div class="sp-live-core-face">
                  <div class="sp-brand-glow">
                    <SpBrandMark :size="62" />
                  </div>
                </div>
              </div>

              <div
                v-for="node in heroNodes"
                :key="node.label"
                class="sp-orbit-node"
                :class="node.className"
              >
                <span class="sp-orbit-node-icon">
                  <UIcon :name="node.icon" class="size-5" />
                </span>
                <span class="sp-orbit-node-label">{{ node.label }}</span>
              </div>

              <div class="sp-data-pulse sp-data-pulse--one">
                <span />
                <span />
                <span />
                <span />
              </div>

              <div class="sp-data-pulse sp-data-pulse--two">
                <span />
                <span />
                <span />
              </div>

              <div class="sp-telemetry-card sp-telemetry-card--stream">
                <span class="sp-telemetry-icon">
                  <UIcon name="i-lucide-radio-tower" class="size-3.5" />
                </span>
                <span>
                  <strong>Streaming</strong>
                  <small>Live response</small>
                </span>
                <i />
              </div>

              <div class="sp-telemetry-card sp-telemetry-card--meter">
                <span class="sp-telemetry-icon">
                  <UIcon name="i-lucide-gauge" class="size-3.5" />
                </span>
                <span>
                  <strong>Metered</strong>
                  <small>Per request</small>
                </span>
                <b>✓</b>
              </div>

              <div class="sp-telemetry-card sp-telemetry-card--secure">
                <span class="sp-telemetry-icon">
                  <UIcon name="i-lucide-lock-keyhole" class="size-3.5" />
                </span>
                <span>
                  <strong>Protected</strong>
                  <small>Scoped access</small>
                </span>
              </div>

              <div class="sp-visual-status">
                <span class="relative flex size-2">
                  <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60" />
                  <span class="relative inline-flex size-2 rounded-full bg-success" />
                </span>
                <span>Gateway ready</span>
              </div>
            </div>

            <div class="lg:hidden">
              <SpCodeBlock
                filename="Claude Code — bash"
                :code="claudeCodeShell"
              />
            </div>
          </div>
        </div>
      </UContainer>
    </section>

    <!-- LIVE CAPABILITY RIBBON -->
    <section class="sp-capability-ribbon relative overflow-hidden border-y border-default/50">
      <div class="sp-ribbon-fade sp-ribbon-fade--left" aria-hidden="true" />
      <div class="sp-ribbon-fade sp-ribbon-fade--right" aria-hidden="true" />

      <div class="sp-ribbon-track">
        <div
          v-for="copy in 2"
          :key="copy"
          class="sp-ribbon-group"
          aria-hidden="true"
        >
          <span
            v-for="item in marqueeItems"
            :key="`${copy}-${item.label}`"
            class="sp-ribbon-item"
          >
            <span class="sp-ribbon-icon">
              <UIcon :name="item.icon" class="size-3.5" />
            </span>
            {{ item.label }}
            <span class="sp-ribbon-separator" />
          </span>
        </div>
      </div>
    </section>


    <!-- DEVELOPER GATEWAY PREVIEW
         Conceptually inspired by developer-gateway landing pages: show the product
         working, not just decorative art. Layout and wording are original to SP Cambo. -->
    <UContainer class="relative py-14 sm:py-18">
      <section class="sp-gateway-showcase relative isolate overflow-hidden rounded-[1.75rem] border border-default/60">
        <div class="sp-gateway-showcase-glow" aria-hidden="true" />
        <div class="sp-gateway-showcase-grid" aria-hidden="true" />

        <div class="relative z-10 grid gap-8 p-5 sm:p-7 lg:grid-cols-[0.82fr_1.18fr] lg:items-center lg:p-9">
          <div class="space-y-6">
            <div class="space-y-3">
              <p class="sp-section-kicker">
                ONE GATEWAY · MANY WORKFLOWS
              </p>

              <h2 class="max-w-xl text-3xl font-semibold tracking-tight text-highlighted text-balance sm:text-4xl">
                See the gateway
                <span class="sp-live-gradient-text">working</span>
                <span class="sp-gateway-heading-tail">— not just described.</span>
              </h2>

              <p class="max-w-xl text-sm leading-7 text-muted sm:text-base">
                Your app talks to SP Cambo with one public endpoint. The platform
                handles scoped model access, streaming, metering and the private
                provider route behind the scenes.
              </p>
            </div>

            <div class="flex flex-wrap gap-2">
              <span
                v-for="family in gatewayFamilies"
                :key="family.label"
                class="sp-family-chip"
              >
                <UIcon :name="family.icon" class="size-3.5" />
                {{ family.label }}
              </span>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div
                v-for="item in gatewayHighlights"
                :key="item.label"
                class="sp-gateway-stat"
              >
                <strong>{{ item.value }}</strong>
                <div>
                  <span>{{ item.label }}</span>
                  <small>{{ item.note }}</small>
                </div>
              </div>
            </div>
          </div>

          <div class="sp-live-request-console">
            <div class="sp-console-titlebar">
              <div class="flex items-center gap-2">
                <span class="sp-console-dot sp-console-dot--red" />
                <span class="sp-console-dot sp-console-dot--yellow" />
                <span class="sp-console-dot sp-console-dot--green" />
              </div>

              <div class="flex items-center gap-2 text-[10px] font-medium text-muted">
                <span class="relative flex size-1.5">
                  <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-55" />
                  <span class="relative inline-flex size-1.5 rounded-full bg-success" />
                </span>
                gateway preview
              </div>
            </div>

            <div class="sp-console-body">
              <div class="sp-console-request-line">
                <span class="sp-http-method">POST</span>
                <code>/v1/chat/completions</code>
                <span class="sp-console-live">stream</span>
              </div>

              <div class="sp-console-json">
                <p><span class="sp-json-key">"model"</span>: <span class="sp-json-string">"5.6-sol"</span>,</p>
                <p><span class="sp-json-key">"messages"</span>: <span class="sp-json-dim">[ ... ]</span>,</p>
                <p><span class="sp-json-key">"stream"</span>: <span class="sp-json-bool">true</span></p>
              </div>

              <div class="sp-console-route">
                <div class="sp-console-route-head">
                  <span>SP Cambo request flow</span>
                  <span>ready</span>
                </div>

                <div class="sp-route-rail">
                  <span class="sp-route-point sp-route-point--key">
                    <UIcon name="i-lucide-key-round" class="size-3.5" />
                  </span>
                  <i />
                  <span class="sp-route-point sp-route-point--gateway">
                    <SpBrandMark :size="24" />
                  </span>
                  <i />
                  <span class="sp-route-point sp-route-point--model">
                    <UIcon name="i-lucide-brain-circuit" class="size-3.5" />
                  </span>
                </div>

                <div class="sp-route-labels">
                  <span>Scoped key</span>
                  <span>Gateway</span>
                  <span>Model</span>
                </div>
              </div>

              <div class="sp-console-response">
                <div class="flex items-center gap-2">
                  <span class="sp-status-200">200</span>
                  <span class="text-xs font-medium text-highlighted">Streaming response</span>
                </div>

                <div class="sp-response-bars" aria-hidden="true">
                  <span />
                  <span />
                  <span />
                  <span />
                  <span />
                  <span />
                  <span />
                  <span />
                </div>
              </div>

              <div class="sp-console-footer">
                <span>
                  <UIcon name="i-lucide-shield-check" class="size-3.5" />
                  upstream credentials hidden
                </span>
                <span>
                  <UIcon name="i-lucide-gauge" class="size-3.5" />
                  usage settles after response
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </UContainer>

    <!-- FEATURE CARDS -->
    <UContainer class="relative py-16 sm:py-20">
      <div class="sp-feature-backdrop pointer-events-none absolute inset-x-0 top-0 -z-10 h-[34rem]" aria-hidden="true" />
      <div class="grid items-end gap-6 lg:grid-cols-[1fr_auto]">
        <div class="max-w-2xl space-y-3">
          <p class="sp-section-kicker">
            CONTROL YOUR AI SPEND
          </p>
          <h2 class="text-3xl font-semibold tracking-tight text-highlighted sm:text-4xl">
            Built for predictable AI spend
          </h2>
          <p class="text-muted text-pretty">
            SP Cambo sits between your tools and the model providers, so access,
            cost and limits are decided by your prepaid balance rather than an
            invoice at the end of the month.
          </p>
        </div>

        <div class="hidden items-center gap-2 rounded-full border border-default/60 bg-elevated/35 px-4 py-2 text-xs text-muted backdrop-blur lg:flex">
          <UIcon name="i-lucide-sparkles" class="size-4 text-primary" />
          Hover the cards
        </div>
      </div>

      <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <NuxtLink
          to="/public/key-checker"
          class="sp-motion-card sp-motion-card--key group relative isolate min-h-[230px] overflow-hidden rounded-2xl border border-default/60 p-6"
        >
          <div class="sp-card-glow" />
          <div class="relative z-10 flex h-full flex-col">
            <div class="sp-card-icon sp-card-icon--key">
              <UIcon name="i-lucide-key" class="size-5" />
            </div>

            <h3 class="mt-4 font-medium text-highlighted">
              API Key Checker
            </h3>

            <p class="mt-2 text-sm leading-6 text-muted text-pretty">
              Check your API key status without logging in. Verify expiry,
              remaining usage, and associated model.
            </p>

            <div class="mt-auto flex items-center gap-2 pt-5 text-sm font-medium text-primary">
              Open Key Checker
              <UIcon
                name="i-lucide-arrow-right"
                class="size-4 transition-transform duration-300 group-hover:translate-x-1"
              />
            </div>
          </div>
        </NuxtLink>

        <article
          v-for="pillar in pillars"
          :key="pillar.title"
          class="sp-motion-card group relative isolate min-h-[230px] overflow-hidden rounded-2xl border border-default/60 p-6"
          :data-tone="pillar.tone"
        >
          <div class="sp-card-glow" />

          <div class="relative z-10">
            <div class="sp-card-icon">
              <UIcon :name="pillar.icon" class="size-5" />
            </div>

            <h3 class="mt-4 font-medium text-highlighted">
              {{ pillar.title }}
            </h3>

            <p class="mt-2 text-sm leading-6 text-muted text-pretty">
              {{ pillar.description }}
            </p>
          </div>
        </article>
      </div>
    </UContainer>

    <!-- FLOW -->
    <section class="relative overflow-hidden border-y border-default/60 bg-elevated/10">
      <div class="sp-section-orb sp-section-orb--left" aria-hidden="true" />
      <div class="sp-section-orb sp-section-orb--right" aria-hidden="true" />
      <div class="sp-section-radar" aria-hidden="true">
        <span />
        <span />
        <span />
        <i />
      </div>

      <UContainer class="relative py-16 sm:py-20">
        <div class="max-w-2xl space-y-3">
          <p class="sp-section-kicker">
            QUICK START
          </p>
          <h2 class="text-3xl font-semibold tracking-tight text-highlighted sm:text-4xl">
            Four steps to your first request
          </h2>
          <p class="text-muted">
            Most customers are running against SP Cambo within a few minutes of
            paying.
          </p>
        </div>

        <ol class="relative mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <div class="sp-step-line pointer-events-none absolute left-[12.5%] right-[12.5%] top-6 hidden h-px lg:block" aria-hidden="true" />

          <li
            v-for="(step, index) in steps"
            :key="step.title"
            class="sp-step-card group relative rounded-2xl border border-default/60 bg-default/45 p-5 backdrop-blur"
          >
            <div class="flex items-center justify-between">
              <span class="sp-step-number">
                {{ String(index + 1).padStart(2, '0') }}
              </span>

              <span class="sp-step-icon">
                <UIcon :name="step.icon" class="size-5" />
              </span>
            </div>

            <h3 class="mt-5 font-medium text-highlighted">
              {{ step.title }}
            </h3>

            <p class="mt-2 text-sm leading-6 text-muted text-pretty">
              {{ step.description }}
            </p>
          </li>
        </ol>

        <div class="mt-10 flex flex-wrap gap-3">
          <UButton to="/docs/quick-start" trailing-icon="i-lucide-arrow-right">
            Read the quick start
          </UButton>
          <UButton to="/docs/claude-code" color="neutral" variant="subtle">
            Claude Code setup
          </UButton>
          <UButton to="/docs/codex-cli" color="neutral" variant="subtle">
            Codex CLI setup
          </UButton>
        </div>
      </UContainer>
    </section>

    <div class="sp-energy-divider" aria-hidden="true">
      <span />
      <i />
    </div>

    <!-- AUDIENCES -->
    <UContainer class="relative py-16 sm:py-20">
      <div class="sp-audience-background pointer-events-none absolute inset-x-0 top-1/2 -z-10 h-[28rem] -translate-y-1/2" aria-hidden="true" />
      <div class="grid gap-5 md:grid-cols-3">
        <UCard
          v-for="(audience, index) in audiences"
          :key="audience.to"
          :ui="{ root: 'sp-audience-card group relative h-full overflow-hidden', body: 'relative z-10 flex h-full flex-col gap-4' }"
          :style="{ '--audience-index': index }"
        >
          <div class="sp-audience-orb" aria-hidden="true" />

          <div class="flex items-center justify-between">
            <div class="sp-audience-icon">
              <UIcon :name="audience.icon" class="size-5" />
            </div>

            <span class="sp-audience-index">
              0{{ index + 1 }}
            </span>
          </div>

          <div class="space-y-2">
            <h3 class="text-lg font-medium text-highlighted">
              {{ audience.title }}
            </h3>

            <p class="text-sm leading-6 text-muted text-pretty">
              {{ audience.description }}
            </p>
          </div>

          <UButton
            :to="audience.to"
            color="neutral"
            variant="subtle"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
            class="mt-auto self-start"
          >
            {{ audience.label }}
          </UButton>
        </UCard>
      </div>
    </UContainer>

    <!-- FINAL CTA -->
    <UContainer class="pb-20">
      <div class="sp-final-cta relative isolate overflow-hidden rounded-3xl border border-default/60 px-6 py-12 text-center shadow-xl shadow-primary/5 sm:px-12 sm:py-14">
        <div class="sp-final-orbit sp-final-orbit--one" aria-hidden="true" />
        <div class="sp-final-orbit sp-final-orbit--two" aria-hidden="true" />
        <div class="sp-final-glow" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-2xl space-y-5">
          <div class="mx-auto flex size-12 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary shadow-lg shadow-primary/10">
            <UIcon name="i-lucide-rocket" class="size-5" />
          </div>

          <h2 class="text-3xl font-semibold tracking-tight text-highlighted text-balance">
            Start with one package, scale when you need to
          </h2>

          <p class="text-muted text-pretty">
            Live packages, prices and model availability are published in your
            dashboard and on the pricing page. Nothing is pre-committed and
            nothing renews automatically.
          </p>

          <div class="flex flex-wrap justify-center gap-3">
            <UButton
              :to="auth.authenticated ? '/dashboard/buy' : '/register'"
              size="lg"
              trailing-icon="i-lucide-arrow-right"
              class="sp-primary-cta"
            >
              {{ auth.authenticated ? 'Buy tokens' : 'Create your account' }}
            </UButton>

            <UButton
              to="/status"
              size="lg"
              color="neutral"
              variant="subtle"
            >
              Check service status
            </UButton>
          </div>
        </div>
      </div>
    </UContainer>
  </div>
</template>

<style scoped>
.sp-motion-page {
  position: relative;
  isolation: isolate;
}

.sp-live-hero {
  background:
    linear-gradient(135deg, rgb(255 255 255 / 0.035), transparent 45%),
    color-mix(in srgb, var(--ui-bg) 90%, transparent);
  backdrop-filter: blur(18px);
}

.sp-hero-sheen {
  background:
    radial-gradient(circle at 75% 50%, rgb(73 105 255 / 0.14), transparent 29rem),
    radial-gradient(circle at 88% 20%, rgb(145 82 255 / 0.10), transparent 20rem);
}

.sp-live-badge {
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.08),
    0 8px 30px rgb(74 101 255 / 0.08);
}

.sp-mini-signal {
  box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.04);
}

.sp-live-gradient-text {
  display: inline-block;
  color: transparent;
  background-image: linear-gradient(
    100deg,
    rgb(88 112 255),
    rgb(135 91 255),
    rgb(74 184 255),
    rgb(88 112 255)
  );
  background-size: 250% 100%;
  background-clip: text;
  -webkit-background-clip: text;
  animation: sp-gradient-flow 6s linear infinite;
}

.sp-primary-cta {
  box-shadow: 0 14px 34px rgb(65 98 255 / 0.22);
  transition:
    transform 200ms ease,
    box-shadow 200ms ease;
}

.sp-primary-cta:hover {
  transform: translateY(-2px);
  box-shadow: 0 18px 42px rgb(65 98 255 / 0.3);
}

.sp-secondary-cta {
  backdrop-filter: blur(12px);
}

.sp-signal-card {
  transition:
    transform 250ms ease,
    border-color 250ms ease,
    background 250ms ease;
}

.sp-signal-card:hover {
  transform: translateY(-3px);
  border-color: rgb(102 128 255 / 0.28);
  background: rgb(103 123 255 / 0.055);
}

.sp-live-visual {
  position: relative;
  perspective: 1100px;
}

.sp-visual-halo {
  position: absolute;
  border-radius: 9999px;
  filter: blur(5px);
}

.sp-visual-halo--one {
  inset: 9% 9%;
  background: radial-gradient(circle, rgb(77 105 255 / 0.17), transparent 64%);
  animation: sp-halo-breathe 5.5s ease-in-out infinite;
}

.sp-visual-halo--two {
  inset: 18% 16%;
  border: 1px solid rgb(95 124 255 / 0.11);
  animation: sp-halo-breathe 7s 0.8s ease-in-out infinite reverse;
}

.sp-orbit {
  position: absolute;
  left: 50%;
  top: 50%;
  border-radius: 9999px;
  border: 1px solid rgb(105 132 255 / 0.23);
  transform: translate(-50%, -50%);
}

.sp-orbit::after {
  position: absolute;
  content: "";
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background: rgb(111 137 255);
  box-shadow:
    0 0 0 5px rgb(95 125 255 / 0.08),
    0 0 18px rgb(91 117 255 / 0.72);
}

.sp-orbit--one {
  width: 360px;
  height: 220px;
  transform: translate(-50%, -50%) rotate(-14deg);
  animation: sp-orbit-spin-one 14s linear infinite;
}

.sp-orbit--one::after {
  right: 13%;
  top: 7%;
}

.sp-orbit--two {
  width: 300px;
  height: 300px;
  border-color: rgb(142 103 255 / 0.15);
  animation: sp-orbit-spin-two 18s linear infinite reverse;
}

.sp-orbit--two::after {
  left: 4%;
  bottom: 29%;
  background: rgb(149 103 255);
}

.sp-orbit--three {
  width: 420px;
  height: 150px;
  border-style: dashed;
  border-color: rgb(85 187 255 / 0.15);
  transform: translate(-50%, -50%) rotate(28deg);
  animation: sp-orbit-spin-three 24s linear infinite;
}

.sp-orbit--three::after {
  right: 30%;
  bottom: -4px;
  background: rgb(58 190 255);
}

.sp-core-shadow {
  position: absolute;
  left: 50%;
  top: 67%;
  width: 230px;
  height: 55px;
  border-radius: 9999px;
  background: rgb(48 71 172 / 0.24);
  filter: blur(24px);
  transform: translateX(-50%);
  animation: sp-shadow-breathe 4.5s ease-in-out infinite;
}

.sp-live-core {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 220px;
  height: 220px;
  transform: translate(-50%, -50%) rotate(-5deg);
  transform-style: preserve-3d;
  animation: sp-core-float 5.2s ease-in-out infinite;
}

.sp-live-core-layer,
.sp-live-core-face {
  position: absolute;
  inset: 0;
  border-radius: 27%;
}

.sp-live-core-layer--back {
  transform: translate3d(24px, 24px, -30px) rotate(8deg);
  background: linear-gradient(145deg, rgb(77 92 205 / 0.23), rgb(105 91 210 / 0.32));
  border: 1px solid rgb(125 142 255 / 0.18);
}

.sp-live-core-layer--middle {
  transform: translate3d(12px, 13px, -12px) rotate(4deg);
  background: linear-gradient(145deg, rgb(73 102 235 / 0.44), rgb(92 85 203 / 0.5));
  border: 1px solid rgb(120 140 255 / 0.2);
  box-shadow: 0 24px 55px rgb(70 76 190 / 0.18);
}

.sp-live-core-face {
  display: grid;
  place-items: center;
  background:
    radial-gradient(circle at 25% 18%, rgb(255 255 255 / 0.13), transparent 34%),
    linear-gradient(150deg, rgb(71 111 255), rgb(69 73 198) 68%, rgb(82 64 184));
  border: 1px solid rgb(173 184 255 / 0.3);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.22),
    0 30px 65px rgb(54 71 184 / 0.32),
    0 0 80px rgb(71 102 255 / 0.13);
}

.sp-brand-glow {
  display: grid;
  place-items: center;
  padding: 11px;
  border-radius: 18px;
  background: rgb(6 10 25 / 0.82);
  border: 1px solid rgb(255 255 255 / 0.08);
  box-shadow:
    0 16px 35px rgb(2 6 23 / 0.36),
    0 0 35px rgb(250 191 60 / 0.08);
}

.sp-orbit-node {
  position: absolute;
  z-index: 4;
  display: flex;
  min-width: 82px;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  animation: sp-node-float 4.8s ease-in-out infinite;
}

.sp-orbit-node-icon {
  display: grid;
  width: 58px;
  height: 58px;
  place-items: center;
  border-radius: 17px;
  color: rgb(215 223 255);
  background:
    linear-gradient(145deg, rgb(38 53 94 / 0.97), rgb(34 41 76 / 0.92));
  border: 1px solid rgb(143 160 224 / 0.16);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.09),
    0 18px 35px rgb(11 17 37 / 0.24);
  backdrop-filter: blur(12px);
  transition:
    transform 220ms ease,
    border-color 220ms ease;
}

.sp-orbit-node-label {
  font-size: 10px;
  font-weight: 600;
  color: color-mix(in srgb, var(--ui-text-muted) 80%, transparent);
  opacity: 0;
  transform: translateY(-4px);
  transition:
    opacity 220ms ease,
    transform 220ms ease;
}

.sp-orbit-node:hover .sp-orbit-node-icon {
  transform: translateY(-4px) scale(1.05);
  border-color: rgb(123 145 255 / 0.3);
}

.sp-orbit-node:hover .sp-orbit-node-label {
  opacity: 1;
  transform: translateY(0);
}

.sp-orbit-node--api {
  right: 6%;
  top: 12%;
  animation-delay: -0.6s;
}

.sp-orbit-node--code {
  right: -1%;
  top: 46%;
  animation-delay: -1.4s;
}

.sp-orbit-node--chart {
  left: 2%;
  bottom: 17%;
  animation-delay: -2.2s;
}

.sp-orbit-node--shield {
  left: 5%;
  top: 14%;
  animation-delay: -3s;
}

.sp-data-pulse {
  position: absolute;
  z-index: 1;
  display: flex;
  align-items: end;
  gap: 5px;
}

.sp-data-pulse span {
  width: 4px;
  border-radius: 9999px;
  background: linear-gradient(to top, rgb(71 115 255 / 0.25), rgb(83 191 255 / 0.75));
  transform-origin: bottom;
  animation: sp-data-bar 1.9s ease-in-out infinite alternate;
}

.sp-data-pulse span:nth-child(1) { height: 12px; animation-delay: -0.2s; }
.sp-data-pulse span:nth-child(2) { height: 22px; animation-delay: -0.8s; }
.sp-data-pulse span:nth-child(3) { height: 32px; animation-delay: -1.3s; }
.sp-data-pulse span:nth-child(4) { height: 18px; animation-delay: -0.5s; }

.sp-data-pulse--one {
  right: 16%;
  bottom: 17%;
}

.sp-data-pulse--two {
  left: 18%;
  top: 49%;
  transform: scale(0.75);
  opacity: 0.58;
}

.sp-visual-status {
  position: absolute;
  left: 50%;
  bottom: 2%;
  z-index: 5;
  display: flex;
  transform: translateX(-50%);
  align-items: center;
  gap: 8px;
  border: 1px solid rgb(120 140 210 / 0.15);
  border-radius: 9999px;
  background: rgb(17 24 48 / 0.58);
  padding: 7px 11px;
  font-size: 10px;
  font-weight: 600;
  color: rgb(185 198 229);
  backdrop-filter: blur(12px);
}

.sp-section-kicker {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.18em;
  color: color-mix(in srgb, var(--ui-primary) 82%, white);
}

.sp-motion-card {
  background:
    linear-gradient(145deg, rgb(255 255 255 / 0.025), transparent 50%),
    color-mix(in srgb, var(--ui-bg-elevated) 48%, transparent);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.03);
  transition:
    transform 280ms cubic-bezier(.2,.75,.25,1),
    border-color 280ms ease,
    box-shadow 280ms ease;
}

.sp-motion-card:hover {
  transform: translateY(-7px);
  border-color: rgb(105 131 255 / 0.25);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.06),
    0 22px 45px rgb(26 38 89 / 0.10);
}

.sp-card-glow {
  position: absolute;
  right: -3rem;
  top: -3rem;
  width: 9rem;
  height: 9rem;
  border-radius: 9999px;
  opacity: 0.16;
  filter: blur(8px);
  background: radial-gradient(circle, rgb(88 117 255), transparent 67%);
  transform: scale(0.7);
  transition:
    opacity 300ms ease,
    transform 300ms ease;
}

.sp-motion-card:hover .sp-card-glow {
  opacity: 0.30;
  transform: scale(1.2);
}

.sp-card-icon {
  position: relative;
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border-radius: 12px;
  color: rgb(127 149 255);
  background: rgb(98 119 255 / 0.09);
  border: 1px solid rgb(114 137 255 / 0.14);
  transition:
    transform 280ms ease,
    box-shadow 280ms ease;
}

.sp-motion-card:hover .sp-card-icon {
  transform: rotate(-5deg) scale(1.06);
  box-shadow: 0 12px 28px rgb(76 105 255 / 0.14);
}

.sp-motion-card[data-tone="cyan"] .sp-card-icon {
  color: rgb(67 190 239);
  background: rgb(56 189 248 / 0.08);
  border-color: rgb(56 189 248 / 0.14);
}

.sp-motion-card[data-tone="emerald"] .sp-card-icon {
  color: rgb(52 211 153);
  background: rgb(52 211 153 / 0.08);
  border-color: rgb(52 211 153 / 0.14);
}

.sp-motion-card[data-tone="amber"] .sp-card-icon {
  color: rgb(251 191 36);
  background: rgb(251 191 36 / 0.08);
  border-color: rgb(251 191 36 / 0.14);
}

.sp-motion-card[data-tone="rose"] .sp-card-icon {
  color: rgb(251 113 133);
  background: rgb(251 113 133 / 0.08);
  border-color: rgb(251 113 133 / 0.14);
}

.sp-motion-card[data-tone="violet"] .sp-card-icon {
  color: rgb(167 139 250);
  background: rgb(139 92 246 / 0.08);
  border-color: rgb(139 92 246 / 0.14);
}

.sp-section-orb {
  position: absolute;
  border-radius: 9999px;
  filter: blur(50px);
  opacity: 0.16;
}

.sp-section-orb--left {
  width: 22rem;
  height: 22rem;
  left: -12rem;
  top: 1rem;
  background: rgb(80 106 255);
  animation: sp-section-orb 11s ease-in-out infinite alternate;
}

.sp-section-orb--right {
  width: 20rem;
  height: 20rem;
  right: -10rem;
  bottom: -4rem;
  background: rgb(133 88 255);
  animation: sp-section-orb 13s 1s ease-in-out infinite alternate-reverse;
}

.sp-step-line {
  overflow: hidden;
  background: linear-gradient(
    90deg,
    transparent,
    rgb(105 128 255 / 0.28),
    rgb(97 176 255 / 0.32),
    rgb(105 128 255 / 0.28),
    transparent
  );
}

.sp-step-line::after {
  position: absolute;
  content: "";
  top: -1px;
  width: 60px;
  height: 3px;
  border-radius: 9999px;
  background: rgb(105 132 255 / 0.75);
  filter: blur(1px);
  animation: sp-line-pulse 4s linear infinite;
}

.sp-step-card {
  transition:
    transform 260ms ease,
    border-color 260ms ease,
    background 260ms ease;
}

.sp-step-card:hover {
  transform: translateY(-5px);
  border-color: rgb(105 132 255 / 0.23);
  background: rgb(97 120 255 / 0.04);
}

.sp-step-number {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  color: color-mix(in srgb, var(--ui-primary) 75%, white);
}

.sp-step-icon {
  display: grid;
  width: 38px;
  height: 38px;
  place-items: center;
  border-radius: 12px;
  background: rgb(91 115 255 / 0.08);
  border: 1px solid rgb(102 126 255 / 0.14);
  color: rgb(123 145 255);
  transition:
    transform 260ms ease,
    box-shadow 260ms ease;
}

.sp-step-card:hover .sp-step-icon {
  transform: rotate(6deg) scale(1.07);
  box-shadow: 0 12px 26px rgb(73 101 255 / 0.14);
}

.sp-audience-card {
  background:
    linear-gradient(145deg, rgb(255 255 255 / 0.02), transparent 50%),
    color-mix(in srgb, var(--ui-bg-elevated) 52%, transparent);
  border-color: color-mix(in srgb, var(--ui-border) 70%, transparent);
  transition:
    transform 280ms cubic-bezier(.2,.75,.25,1),
    border-color 280ms ease;
}

.sp-audience-card:hover {
  transform: translateY(-7px);
  border-color: rgb(105 132 255 / 0.24);
}

.sp-audience-orb {
  position: absolute;
  right: -50px;
  top: -55px;
  width: 135px;
  height: 135px;
  border-radius: 9999px;
  background: radial-gradient(circle, rgb(92 116 255 / 0.15), transparent 70%);
  transform: translate3d(0, 0, 0);
  transition:
    transform 350ms ease,
    opacity 350ms ease;
}

.sp-audience-card:hover .sp-audience-orb {
  transform: translate3d(-10px, 9px, 0) scale(1.2);
}

.sp-audience-icon {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border-radius: 13px;
  color: rgb(126 149 255);
  border: 1px solid rgb(112 137 255 / 0.14);
  background: rgb(93 118 255 / 0.07);
}

.sp-audience-index {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.14em;
  color: var(--ui-text-dimmed);
}

.sp-final-cta {
  background:
    radial-gradient(circle at 50% 0%, rgb(84 110 255 / 0.11), transparent 24rem),
    color-mix(in srgb, var(--ui-bg-elevated) 48%, transparent);
  backdrop-filter: blur(12px);
}

.sp-final-glow {
  position: absolute;
  left: 50%;
  top: -9rem;
  width: 34rem;
  height: 22rem;
  transform: translateX(-50%);
  border-radius: 9999px;
  background: radial-gradient(circle, rgb(78 108 255 / 0.18), transparent 68%);
  filter: blur(8px);
  animation: sp-halo-breathe 6s ease-in-out infinite;
}

.sp-final-orbit {
  position: absolute;
  border: 1px solid rgb(108 133 255 / 0.09);
  border-radius: 9999px;
}

.sp-final-orbit--one {
  width: 430px;
  height: 430px;
  left: -260px;
  top: -150px;
  animation: sp-final-spin 20s linear infinite;
}

.sp-final-orbit--two {
  width: 340px;
  height: 340px;
  right: -190px;
  bottom: -210px;
  border-style: dashed;
  animation: sp-final-spin 26s linear infinite reverse;
}

@keyframes sp-gradient-flow {
  from { background-position: 0% 50%; }
  to { background-position: 250% 50%; }
}

@keyframes sp-halo-breathe {
  0%, 100% { transform: scale(0.92); opacity: 0.48; }
  50% { transform: scale(1.08); opacity: 0.85; }
}

@keyframes sp-shadow-breathe {
  0%, 100% { transform: translateX(-50%) scale(0.88); opacity: 0.45; }
  50% { transform: translateX(-50%) scale(1.08); opacity: 0.72; }
}

@keyframes sp-core-float {
  0%, 100% { transform: translate(-50%, -50%) rotate(-5deg) translateY(0); }
  50% { transform: translate(-50%, -50%) rotate(-2deg) translateY(-13px); }
}

@keyframes sp-orbit-spin-one {
  from { transform: translate(-50%, -50%) rotate(-14deg); }
  to { transform: translate(-50%, -50%) rotate(346deg); }
}

@keyframes sp-orbit-spin-two {
  from { transform: translate(-50%, -50%) rotate(0deg); }
  to { transform: translate(-50%, -50%) rotate(360deg); }
}

@keyframes sp-orbit-spin-three {
  from { transform: translate(-50%, -50%) rotate(28deg); }
  to { transform: translate(-50%, -50%) rotate(388deg); }
}

@keyframes sp-node-float {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-10px) rotate(2deg); }
}

@keyframes sp-data-bar {
  from { transform: scaleY(0.45); opacity: 0.45; }
  to { transform: scaleY(1); opacity: 0.95; }
}

@keyframes sp-section-orb {
  from { transform: translate3d(0, 0, 0) scale(0.9); }
  to { transform: translate3d(5rem, 2rem, 0) scale(1.15); }
}

@keyframes sp-line-pulse {
  from { left: -70px; }
  to { left: calc(100% + 70px); }
}

@keyframes sp-final-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}


.sp-live-hero {
  --hero-mx: 68%;
  --hero-my: 40%;
  --hero-rx: 0deg;
  --hero-ry: 0deg;
}

.sp-hero-spotlight {
  background: radial-gradient(circle 18rem at var(--hero-mx) var(--hero-my), rgb(103 128 255 / 0.13), transparent 70%);
  transition: background 110ms linear;
}

.sp-hero-noise {
  opacity: 0.22;
  background-image:
    radial-gradient(circle at 20% 30%, rgb(255 255 255 / 0.18) 0 0.5px, transparent 0.7px),
    radial-gradient(circle at 70% 65%, rgb(255 255 255 / 0.11) 0 0.6px, transparent 0.8px);
  background-size: 18px 18px, 23px 23px;
  mask-image: linear-gradient(110deg, transparent 5%, black 45%, transparent 92%);
}

.sp-hero-laser {
  position: absolute;
  z-index: -1;
  width: 48%;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgb(95 142 255 / 0.58), transparent);
  box-shadow: 0 0 18px rgb(76 128 255 / 0.28);
  opacity: 0.28;
}

.sp-hero-laser--one {
  right: -4%;
  top: 25%;
  transform: rotate(-18deg);
  animation: sp-laser-one 8s ease-in-out infinite alternate;
}

.sp-hero-laser--two {
  right: 5%;
  bottom: 23%;
  transform: rotate(13deg);
  animation: sp-laser-two 10s 1.2s ease-in-out infinite alternate-reverse;
}

.sp-live-visual {
  transform: perspective(1100px) rotateX(var(--hero-rx)) rotateY(var(--hero-ry));
  transform-style: preserve-3d;
  transition: transform 160ms ease-out;
}

.sp-telemetry-card {
  position: absolute;
  z-index: 6;
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 118px;
  padding: 8px 10px;
  border: 1px solid rgb(126 147 219 / 0.14);
  border-radius: 13px;
  background: linear-gradient(145deg, rgb(20 28 55 / 0.74), rgb(29 34 68 / 0.56));
  box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.06), 0 16px 34px rgb(5 11 31 / 0.17);
  color: rgb(201 212 243);
  backdrop-filter: blur(16px);
  animation: sp-telemetry-float 5.4s ease-in-out infinite;
}

.sp-telemetry-card strong {
  display: block;
  font-size: 10px;
  line-height: 1.2;
}

.sp-telemetry-card small {
  display: block;
  margin-top: 2px;
  font-size: 8px;
  color: rgb(145 161 199);
}

.sp-telemetry-icon {
  display: grid;
  width: 27px;
  height: 27px;
  place-items: center;
  border-radius: 8px;
  background: rgb(92 118 255 / 0.1);
  color: rgb(130 153 255);
  border: 1px solid rgb(114 138 255 / 0.12);
}

.sp-telemetry-card--stream {
  left: -2%;
  top: 38%;
  animation-delay: -1.1s;
}

.sp-telemetry-card--stream i {
  margin-left: auto;
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: rgb(52 211 153);
  box-shadow: 0 0 10px rgb(52 211 153 / 0.72);
}

.sp-telemetry-card--meter {
  right: -3%;
  bottom: 28%;
  animation-delay: -2.7s;
}

.sp-telemetry-card--meter b {
  margin-left: auto;
  font-size: 10px;
  color: rgb(52 211 153);
}

.sp-telemetry-card--secure {
  right: 18%;
  top: 1%;
  min-width: 112px;
  animation-delay: -3.9s;
}

.sp-capability-ribbon {
  background: linear-gradient(90deg, transparent, rgb(94 116 255 / 0.035), transparent), color-mix(in srgb, var(--ui-bg-elevated) 28%, transparent);
  backdrop-filter: blur(12px);
}

.sp-ribbon-track {
  display: flex;
  width: max-content;
  min-width: 200%;
  padding-block: 10px;
  animation: sp-ribbon-scroll 34s linear infinite;
}

.sp-ribbon-group {
  display: flex;
  flex-shrink: 0;
  align-items: center;
}

.sp-ribbon-item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  padding-inline: 16px;
  font-size: 11px;
  font-weight: 600;
  color: var(--ui-text-muted);
}

.sp-ribbon-icon {
  display: grid;
  width: 25px;
  height: 25px;
  place-items: center;
  border-radius: 8px;
  color: color-mix(in srgb, var(--ui-primary) 78%, white);
  background: rgb(95 119 255 / 0.08);
  border: 1px solid rgb(105 130 255 / 0.1);
}

.sp-ribbon-separator {
  width: 3px;
  height: 3px;
  margin-left: 8px;
  border-radius: 9999px;
  background: rgb(112 137 255 / 0.45);
  box-shadow: 0 0 8px rgb(90 124 255 / 0.5);
}

.sp-ribbon-fade {
  position: absolute;
  z-index: 2;
  top: 0;
  bottom: 0;
  width: 8rem;
  pointer-events: none;
}

.sp-ribbon-fade--left {
  left: 0;
  background: linear-gradient(90deg, var(--ui-bg), transparent);
}

.sp-ribbon-fade--right {
  right: 0;
  background: linear-gradient(-90deg, var(--ui-bg), transparent);
}

.sp-feature-backdrop {
  background:
    radial-gradient(circle at 18% 28%, rgb(78 113 255 / 0.08), transparent 17rem),
    radial-gradient(circle at 76% 36%, rgb(127 82 255 / 0.07), transparent 19rem),
    linear-gradient(to bottom, rgb(89 112 255 / 0.025), transparent 70%);
  filter: blur(2px);
}

.sp-motion-card::before {
  position: absolute;
  content: "";
  inset: -1px;
  z-index: -1;
  border-radius: inherit;
  padding: 1px;
  opacity: 0;
  background: linear-gradient(110deg, transparent 20%, rgb(112 139 255 / 0.38), rgb(69 190 255 / 0.22), transparent 70%);
  background-size: 220% 100%;
  mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  mask-composite: exclude;
  transition: opacity 260ms ease;
  animation: sp-card-border-flow 5s linear infinite;
}

.sp-motion-card:hover::before {
  opacity: 1;
}

.sp-section-radar {
  position: absolute;
  right: -6rem;
  top: 50%;
  width: 24rem;
  height: 24rem;
  transform: translateY(-50%);
  opacity: 0.15;
  pointer-events: none;
}

.sp-section-radar span {
  position: absolute;
  inset: 50%;
  border: 1px solid rgb(102 132 255 / 0.28);
  border-radius: 9999px;
  transform: translate(-50%, -50%);
}

.sp-section-radar span:nth-child(1) { width: 32%; height: 32%; }
.sp-section-radar span:nth-child(2) { width: 57%; height: 57%; }
.sp-section-radar span:nth-child(3) { width: 82%; height: 82%; }

.sp-section-radar i {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 42%;
  height: 1px;
  transform-origin: left center;
  background: linear-gradient(90deg, rgb(95 132 255 / 0.6), transparent);
  box-shadow: 0 0 12px rgb(95 132 255 / 0.35);
  animation: sp-radar-spin 8s linear infinite;
}

.sp-energy-divider {
  position: relative;
  height: 38px;
  overflow: hidden;
}

.sp-energy-divider::before {
  position: absolute;
  content: "";
  left: 8%;
  right: 8%;
  top: 50%;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgb(102 129 255 / 0.18), rgb(65 190 255 / 0.22), rgb(102 129 255 / 0.18), transparent);
}

.sp-energy-divider span {
  position: absolute;
  left: 8%;
  top: calc(50% - 1px);
  width: 7rem;
  height: 3px;
  border-radius: 9999px;
  background: linear-gradient(90deg, transparent, rgb(110 137 255 / 0.72), transparent);
  filter: blur(0.6px);
  animation: sp-divider-run 7s linear infinite;
}

.sp-energy-divider i {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 6px;
  height: 6px;
  transform: translate(-50%, -50%);
  border: 1px solid rgb(115 142 255 / 0.45);
  border-radius: 9999px;
  box-shadow: 0 0 16px rgb(95 126 255 / 0.45);
}

.sp-audience-background {
  background:
    radial-gradient(circle at 50% 50%, rgb(92 116 255 / 0.06), transparent 31rem),
    repeating-radial-gradient(circle at 50% 50%, rgb(103 128 255 / 0.035) 0 1px, transparent 1px 34px);
  mask-image: radial-gradient(circle, black 0%, transparent 68%);
}

@keyframes sp-laser-one {
  from { transform: translateX(-3rem) rotate(-18deg); opacity: 0.12; }
  to { transform: translateX(4rem) rotate(-18deg); opacity: 0.38; }
}

@keyframes sp-laser-two {
  from { transform: translateX(3rem) rotate(13deg); opacity: 0.1; }
  to { transform: translateX(-5rem) rotate(13deg); opacity: 0.32; }
}

@keyframes sp-telemetry-float {
  0%, 100% { transform: translate3d(0, 0, 18px); }
  50% { transform: translate3d(0, -8px, 24px); }
}

@keyframes sp-ribbon-scroll {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

@keyframes sp-card-border-flow {
  from { background-position: 200% 50%; }
  to { background-position: -20% 50%; }
}

@keyframes sp-radar-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

@keyframes sp-divider-run {
  from { left: 4%; opacity: 0; }
  12% { opacity: 1; }
  88% { opacity: 1; }
  to { left: calc(96% - 7rem); opacity: 0; }
}


.sp-gateway-showcase {
  background:
    linear-gradient(135deg, rgb(255 255 255 / 0.025), transparent 42%),
    color-mix(in srgb, var(--ui-bg-elevated) 48%, transparent);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.04),
    0 28px 70px rgb(23 34 85 / 0.08);
  backdrop-filter: blur(16px);
}

.sp-gateway-showcase-glow {
  position: absolute;
  right: -8rem;
  top: -11rem;
  width: 34rem;
  height: 34rem;
  border-radius: 9999px;
  background:
    radial-gradient(circle, rgb(73 109 255 / 0.18), rgb(104 78 255 / 0.08) 36%, transparent 68%);
  filter: blur(10px);
  animation: sp-gateway-glow 8s ease-in-out infinite alternate;
}

.sp-gateway-showcase-grid {
  position: absolute;
  inset: 0;
  opacity: 0.23;
  background-image:
    linear-gradient(rgb(102 126 255 / 0.045) 1px, transparent 1px),
    linear-gradient(90deg, rgb(102 126 255 / 0.045) 1px, transparent 1px);
  background-size: 38px 38px;
  mask-image: linear-gradient(90deg, transparent 2%, black 48%, black 100%);
}

.sp-family-chip {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  border: 1px solid rgb(110 133 214 / 0.13);
  border-radius: 9999px;
  background: rgb(255 255 255 / 0.025);
  padding: 7px 10px;
  font-size: 11px;
  font-weight: 600;
  color: var(--ui-text-muted);
  backdrop-filter: blur(10px);
  transition:
    transform 220ms ease,
    border-color 220ms ease,
    color 220ms ease;
}

.sp-family-chip:hover {
  transform: translateY(-2px);
  border-color: rgb(110 137 255 / 0.26);
  color: var(--ui-text-highlighted);
}

.sp-gateway-stat {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 10px;
  border: 1px solid rgb(111 132 204 / 0.11);
  border-radius: 13px;
  background: rgb(255 255 255 / 0.018);
  padding: 10px 11px;
}

.sp-gateway-stat strong {
  min-width: 44px;
  font-family: var(--font-mono);
  font-size: 12px;
  letter-spacing: -0.02em;
  color: color-mix(in srgb, var(--ui-primary) 78%, white);
}

.sp-gateway-stat span {
  display: block;
  font-size: 10px;
  font-weight: 700;
  color: var(--ui-text-highlighted);
}

.sp-gateway-stat small {
  display: block;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 9px;
  color: var(--ui-text-dimmed);
}

.sp-live-request-console {
  position: relative;
  overflow: hidden;
  border: 1px solid rgb(110 134 214 / 0.16);
  border-radius: 18px;
  background:
    radial-gradient(circle at 78% 20%, rgb(75 102 255 / 0.10), transparent 17rem),
    color-mix(in srgb, var(--ui-bg) 88%, rgb(20 29 65));
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.045),
    0 26px 70px rgb(9 16 46 / 0.22);
  transform: perspective(1100px) rotateY(-2.5deg) rotateX(1deg);
  transition:
    transform 320ms cubic-bezier(.2,.8,.2,1),
    border-color 320ms ease;
}

.sp-live-request-console:hover {
  transform: perspective(1100px) rotateY(0) rotateX(0) translateY(-3px);
  border-color: rgb(113 140 255 / 0.28);
}

.sp-console-titlebar {
  display: flex;
  min-height: 42px;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid rgb(108 129 199 / 0.10);
  padding: 0 14px;
  background: rgb(255 255 255 / 0.018);
}

.sp-console-dot {
  width: 7px;
  height: 7px;
  border-radius: 9999px;
}

.sp-console-dot--red { background: rgb(248 113 113 / 0.72); }
.sp-console-dot--yellow { background: rgb(251 191 36 / 0.72); }
.sp-console-dot--green { background: rgb(52 211 153 / 0.72); }

.sp-console-body {
  padding: 15px;
}

.sp-console-request-line {
  display: flex;
  align-items: center;
  gap: 9px;
  border-bottom: 1px solid rgb(109 129 196 / 0.08);
  padding-bottom: 12px;
  font-size: 11px;
}

.sp-console-request-line code {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--ui-text-muted);
}

.sp-http-method {
  border-radius: 6px;
  background: rgb(52 211 153 / 0.10);
  padding: 3px 6px;
  font-family: var(--font-mono);
  font-size: 9px;
  font-weight: 800;
  color: rgb(52 211 153);
}

.sp-console-live {
  border-radius: 9999px;
  background: rgb(93 118 255 / 0.08);
  padding: 3px 7px;
  font-size: 9px;
  color: rgb(130 151 255);
}

.sp-console-json {
  margin-top: 12px;
  border: 1px solid rgb(107 128 194 / 0.08);
  border-radius: 11px;
  background: rgb(0 0 0 / 0.08);
  padding: 10px 12px;
  font-family: var(--font-mono);
  font-size: 10px;
  line-height: 1.8;
}

.sp-json-key { color: rgb(139 162 255); }
.sp-json-string { color: rgb(94 211 184); }
.sp-json-bool { color: rgb(251 191 36); }
.sp-json-dim { color: var(--ui-text-dimmed); }

.sp-console-route {
  margin-top: 13px;
  border: 1px solid rgb(105 130 208 / 0.09);
  border-radius: 12px;
  padding: 11px;
  background: rgb(91 111 255 / 0.025);
}

.sp-console-route-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 9px;
  font-weight: 600;
  color: var(--ui-text-dimmed);
}

.sp-console-route-head span:last-child {
  color: rgb(52 211 153);
}

.sp-route-rail {
  display: grid;
  grid-template-columns: auto 1fr auto 1fr auto;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
}

.sp-route-rail i {
  position: relative;
  height: 1px;
  overflow: hidden;
  background: linear-gradient(90deg, rgb(103 130 255 / 0.12), rgb(103 130 255 / 0.28), rgb(103 130 255 / 0.12));
}

.sp-route-rail i::after {
  position: absolute;
  content: "";
  top: -1px;
  width: 32px;
  height: 3px;
  border-radius: 9999px;
  background: linear-gradient(90deg, transparent, rgb(101 149 255 / 0.9), transparent);
  animation: sp-console-packet 2.6s linear infinite;
}

.sp-route-point {
  display: grid;
  width: 35px;
  height: 35px;
  place-items: center;
  border: 1px solid rgb(112 135 214 / 0.15);
  border-radius: 10px;
  background: rgb(255 255 255 / 0.025);
  color: rgb(135 155 236);
}

.sp-route-point--gateway {
  background: rgb(77 105 255 / 0.07);
}

.sp-route-labels {
  display: grid;
  grid-template-columns: 35px 1fr 35px 1fr 35px;
  gap: 8px;
  margin-top: 6px;
  font-size: 7px;
  text-align: center;
  color: var(--ui-text-dimmed);
}

.sp-route-labels span:nth-child(1) { grid-column: 1; }
.sp-route-labels span:nth-child(2) { grid-column: 3; }
.sp-route-labels span:nth-child(3) { grid-column: 5; }

.sp-console-response {
  display: flex;
  margin-top: 13px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid rgb(52 211 153 / 0.10);
  border-radius: 11px;
  background: rgb(52 211 153 / 0.025);
  padding: 9px 10px;
}

.sp-status-200 {
  border-radius: 6px;
  background: rgb(52 211 153 / 0.10);
  padding: 3px 6px;
  font-family: var(--font-mono);
  font-size: 9px;
  font-weight: 800;
  color: rgb(52 211 153);
}

.sp-response-bars {
  display: flex;
  height: 18px;
  align-items: end;
  gap: 3px;
}

.sp-response-bars span {
  width: 3px;
  border-radius: 9999px;
  background: linear-gradient(to top, rgb(66 126 255 / 0.4), rgb(52 211 153 / 0.8));
  transform-origin: bottom;
  animation: sp-console-bar 1.1s ease-in-out infinite alternate;
}

.sp-response-bars span:nth-child(1) { height: 7px; animation-delay: -0.1s; }
.sp-response-bars span:nth-child(2) { height: 13px; animation-delay: -0.6s; }
.sp-response-bars span:nth-child(3) { height: 9px; animation-delay: -0.3s; }
.sp-response-bars span:nth-child(4) { height: 17px; animation-delay: -0.8s; }
.sp-response-bars span:nth-child(5) { height: 11px; animation-delay: -0.5s; }
.sp-response-bars span:nth-child(6) { height: 15px; animation-delay: -0.2s; }
.sp-response-bars span:nth-child(7) { height: 8px; animation-delay: -0.9s; }
.sp-response-bars span:nth-child(8) { height: 12px; animation-delay: -0.4s; }

.sp-console-footer {
  display: flex;
  margin-top: 11px;
  flex-wrap: wrap;
  gap: 8px 14px;
  font-size: 8px;
  color: var(--ui-text-dimmed);
}

.sp-console-footer span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

@keyframes sp-gateway-glow {
  from { transform: translate3d(0, 0, 0) scale(0.92); opacity: 0.5; }
  to { transform: translate3d(-3rem, 2rem, 0) scale(1.08); opacity: 0.9; }
}

@keyframes sp-console-packet {
  from { left: -35px; }
  to { left: calc(100% + 35px); }
}

@keyframes sp-console-bar {
  from { transform: scaleY(0.35); opacity: 0.42; }
  to { transform: scaleY(1); opacity: 0.95; }
}

@media (max-width: 639px) {
  .sp-live-request-console {
    transform: none;
  }

  .sp-gateway-stat {
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
  }

  .sp-gateway-stat small {
    white-space: normal;
  }
}

@media (max-width: 1023px) {
  .sp-live-hero {
    border-radius: 1.5rem;
  }

  .sp-section-radar {
    opacity: 0.08;
  }

  .sp-ribbon-track {
    animation-duration: 42s;
  }
}

@media (max-width: 639px) {
  .sp-live-hero {
    padding-inline: 1.1rem;
  }

  .sp-ribbon-fade {
    width: 2rem;
  }
}

/* Keep the design calm and accessible for customers who request reduced motion. */
@media (prefers-reduced-motion: reduce) {
  .sp-motion-page *,
  .sp-motion-page *::before,
  .sp-motion-page *::after {
    scroll-behavior: auto !important;
    animation-duration: 0.001ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.001ms !important;
  }
}


/* R14: light-mode index correction.
   Keep the page itself fully opaque; only code/terminal surfaces stay dark. */
:global(html.light) .sp-motion-page {
  opacity: 1 !important;
  filter: none !important;
}

:global(html.light) .sp-public-hero :deep(.sp-code-block),
:global(html.light) .sp-live-request-console {
  opacity: 1 !important;
  background: rgb(7 17 31 / .98) !important;
  color: rgb(226 232 240) !important;
  border-color: rgb(100 116 139 / .30) !important;
  box-shadow: 0 16px 38px rgb(15 23 42 / .14), inset 0 1px rgb(255 255 255 / .04) !important;
}

:global(html.light) .sp-public-hero :deep(.sp-code-block__header),
:global(html.light) .sp-console-titlebar {
  background: rgb(15 27 47 / .98) !important;
  color: rgb(203 213 225) !important;
  border-bottom-color: rgb(100 116 139 / .24) !important;
}

:global(html.light) .sp-public-hero :deep(.sp-code-block__content),
:global(html.light) .sp-public-hero :deep(.sp-code-block pre),
:global(html.light) .sp-public-hero :deep(.sp-code-block code),
:global(html.light) .sp-live-request-console :is(code, .sp-console-json, .sp-console-request-line, .sp-console-route-head, .sp-route-labels, .sp-console-footer) {
  color: rgb(226 232 240) !important;
}

.sp-gateway-heading-tail {
  white-space: normal;
}

@media (max-width: 639px) {
  .sp-public-hero :deep(.sp-code-block) {
    border-radius: 1rem !important;
  }

  .sp-public-hero :deep(.sp-code-block__header) {
    min-height: 3rem;
    padding-inline: .85rem;
  }

  .sp-public-hero :deep(.sp-code-block__content pre) {
    min-width: max-content;
    padding: 1rem;
    font-size: .75rem;
    line-height: 1.7;
  }

  .sp-gateway-showcase {
    border-radius: 1.25rem;
  }
}

</style>
