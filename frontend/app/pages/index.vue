<script setup lang="ts">
useSeoMeta({
  title: 'Managed AI access with prepaid, metered billing',
  description: 'SP Cambo gives your team one endpoint for managed AI models: prepaid token and credit packages, scoped API keys, and exact per-request metering for Claude Code, Codex CLI and your own SDK code.',
  ogTitle: 'SP Cambo — managed AI access, prepaid and metered'
})

const auth = useAuthStore()
const { claudeCodeShell } = useCliSnippets()

const pillars = [
  {
    icon: 'i-lucide-key-round',
    title: 'Scoped API keys',
    description: 'Issue keys per project or package. Lists stay masked, while the signed-in owner can securely re-copy the current encrypted secret. Rotate or revoke instantly.'
  },
  {
    icon: 'i-lucide-gauge',
    title: 'Exact metering',
    description: 'Every request reserves quota, then settles against the usage the provider actually reported. Interim numbers are labelled as estimates until settlement.'
  },
  {
    icon: 'i-lucide-wallet',
    title: 'Prepaid, no surprises',
    description: 'Buy a token or credit package up front. When a package is spent or expires, requests stop — there is no overage bill to discover later.'
  },
  {
    icon: 'i-lucide-shield-check',
    title: 'Provider isolation',
    description: 'Your key never reaches an upstream provider, and upstream credentials never reach your machine. SP Cambo terminates auth at its own gateway.'
  },
  {
    icon: 'i-lucide-clock',
    title: 'Time-boxed access',
    description: 'Packages carry an exact lifetime in seconds from activation. A one-day package means 24 hours, not "until the end of tomorrow".'
  },
  {
    icon: 'i-lucide-scroll-text',
    title: 'Auditable ledger',
    description: 'Purchases, reservations, settlements and expiries are recorded as immutable ledger entries you can reconcile against your own records.'
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
    title: 'Point your tool at SP Cambo',
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
</script>

<template>
  <div>
    <section class="sp-public-hero">
      <UContainer class="py-10 sm:py-14 lg:py-16">
        <div class="sp-hero-panel grid items-center gap-10 px-6 py-10 sm:px-9 lg:grid-cols-[1.05fr_0.95fr] lg:px-12 lg:py-14">
          <div class="relative z-10 space-y-7">
            <UBadge
              color="neutral"
              variant="subtle"
              size="lg"
              class="rounded-full"
            >
              <span class="flex items-center gap-2">
                <span class="inline-flex size-1.5 rounded-full bg-success" />
                AI POWERED SOLUTIONS
              </span>
            </UBadge>

            <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance sm:text-5xl lg:text-6xl">
              Premium <span class="sp-gradient-text">AI packages</span><br> & API access
            </h1>

            <p class="max-w-xl text-lg leading-8 text-muted text-pretty">
              High-performance managed AI models with simple prepaid pricing. Buy access, issue a scoped API key and connect your apps or CLI tools to one endpoint.
            </p>

            <div class="flex flex-wrap items-center gap-3">
              <UButton
                v-if="auth.authenticated"
                to="/dashboard"
                size="xl"
                trailing-icon="i-lucide-arrow-right"
              >
                Open dashboard
              </UButton>
              <template v-else>
                <UButton
                  to="/register"
                  size="xl"
                  trailing-icon="i-lucide-arrow-right"
                >
                  Create account
                </UButton>
                <UButton
                  to="/pricing"
                  size="xl"
                  color="neutral"
                  variant="subtle"
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

            <ul class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
              <li class="flex items-center gap-2">
                <UIcon
                  name="i-lucide-check"
                  class="size-4 text-primary"
                />
                No subscription
              </li>
              <li class="flex items-center gap-2">
                <UIcon
                  name="i-lucide-check"
                  class="size-4 text-primary"
                />
                No overage billing
              </li>
              <li class="flex items-center gap-2">
                <UIcon
                  name="i-lucide-check"
                  class="size-4 text-primary"
                />
                Bakong KHQR payment
              </li>
            </ul>

            <div class="flex flex-wrap items-center gap-4 text-xs text-muted">
              <span class="inline-flex items-center gap-1.5"><UIcon name="i-lucide-shield-check" class="size-4 text-primary" /> Secure access</span>
              <span class="inline-flex items-center gap-1.5"><UIcon name="i-lucide-globe-2" class="size-4 text-primary" /> Developer ready</span>
              <span class="inline-flex items-center gap-1.5"><UIcon name="i-lucide-headphones" class="size-4 text-primary" /> SP Cambo support</span>
            </div>
          </div>

          <div class="relative">
            <div class="sp-hero-orbit hidden min-h-[310px] lg:block" aria-hidden="true">
              <div class="sp-hero-core"><SpBrandMark class="size-16 text-white" /></div>
              <div class="sp-floating-chip sp-floating-chip--api text-sm font-semibold">API</div>
              <div class="sp-floating-chip sp-floating-chip--code"><UIcon name="i-lucide-code-xml" class="size-6" /></div>
              <div class="sp-floating-chip sp-floating-chip--chart"><UIcon name="i-lucide-chart-no-axes-combined" class="size-6" /></div>
            </div>
            <div class="lg:hidden">
              <SpCodeBlock filename="Claude Code — bash" :code="claudeCodeShell" />
            </div>
          </div>
        </div>
      </UContainer>
    </section>

    <UContainer class="py-16 sm:py-20">
      <div class="max-w-2xl space-y-3">
        <h2 class="text-3xl font-semibold tracking-tight text-highlighted">
          Built for predictable AI spend
        </h2>
        <p class="text-muted text-pretty">
          SP Cambo sits between your tools and the model providers, so access, cost and limits
          are decided by your prepaid balance rather than an invoice at the end of the month.
        </p>
      </div>

      <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <div
          class="sp-premium-card sp-key-checker-card space-y-3 rounded-2xl p-6"
        >
          <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <UIcon
              name="i-lucide-key"
              class="size-5"
            />
          </div>
          <h3 class="font-medium text-highlighted">
            API Key Checker
          </h3>
          <p class="text-sm text-muted text-pretty">
            Check your API key status without logging in. Verify expiry, remaining usage, and associated model.
          </p>
          <UButton
            to="/public/key-checker"
            size="sm"
            class="mt-2"
          >
            Open Key Checker
          </UButton>
        </div>

        <div
          v-for="pillar in pillars"
          :key="pillar.title"
          class="sp-premium-card space-y-3 rounded-2xl p-6"
        >
          <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <UIcon
              :name="pillar.icon"
              class="size-5"
            />
          </div>
          <h3 class="font-medium text-highlighted">
            {{ pillar.title }}
          </h3>
          <p class="text-sm text-muted text-pretty">
            {{ pillar.description }}
          </p>
        </div>
      </div>
    </UContainer>

    <div class="border-y border-default/60 bg-elevated/10">
      <UContainer class="py-16 sm:py-20">
        <div class="max-w-2xl space-y-3">
          <h2 class="text-3xl font-semibold tracking-tight text-highlighted">
            Four steps to your first request
          </h2>
          <p class="text-muted">
            Most customers are running against SP Cambo within a few minutes of paying.
          </p>
        </div>

        <ol class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <li
            v-for="(step, index) in steps"
            :key="step.title"
            class="relative space-y-3"
          >
            <div class="flex items-center gap-3">
              <span
                class="sp-numeric flex size-8 shrink-0 items-center justify-center rounded-full border border-default bg-default text-sm font-semibold text-highlighted"
              >
                {{ index + 1 }}
              </span>
              <UIcon
                :name="step.icon"
                class="size-4 text-dimmed"
              />
            </div>
            <h3 class="font-medium text-highlighted">
              {{ step.title }}
            </h3>
            <p class="text-sm text-muted text-pretty">
              {{ step.description }}
            </p>
          </li>
        </ol>

        <div class="mt-10 flex flex-wrap gap-3">
          <UButton
            to="/docs/quick-start"
            trailing-icon="i-lucide-arrow-right"
          >
            Read the quick start
          </UButton>
          <UButton
            to="/docs/claude-code"
            color="neutral"
            variant="subtle"
          >
            Claude Code setup
          </UButton>
          <UButton
            to="/docs/codex-cli"
            color="neutral"
            variant="subtle"
          >
            Codex CLI setup
          </UButton>
        </div>
      </UContainer>
    </div>

    <UContainer class="py-16 sm:py-20">
      <div class="grid gap-5 md:grid-cols-3">
        <UCard
          v-for="audience in audiences"
          :key="audience.to"
          :ui="{ root: 'sp-premium-card h-full', body: 'flex h-full flex-col gap-4' }"
         class="sp-app-card">
          <div class="flex size-10 items-center justify-center rounded-lg bg-elevated text-default">
            <UIcon
              :name="audience.icon"
              class="size-5"
            />
          </div>
          <div class="space-y-2">
            <h3 class="text-lg font-medium text-highlighted">
              {{ audience.title }}
            </h3>
            <p class="text-sm text-muted text-pretty">
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

    <UContainer class="pb-20">
      <div
        class="sp-premium-card relative overflow-hidden rounded-3xl px-6 py-12 text-center sm:px-12"
      >
        <div
          class="sp-ambient-glow pointer-events-none absolute inset-x-0 -top-24 h-64"
          aria-hidden="true"
        />
        <div class="relative mx-auto max-w-2xl space-y-5">
          <h2 class="text-3xl font-semibold tracking-tight text-highlighted text-balance">
            Start with one package, scale when you need to
          </h2>
          <p class="text-muted text-pretty">
            Live packages, prices and model availability are published in your dashboard and on
            the pricing page. Nothing is pre-committed and nothing renews automatically.
          </p>
          <div class="flex flex-wrap justify-center gap-3">
            <UButton
              :to="auth.authenticated ? '/dashboard/buy' : '/register'"
              size="lg"
              trailing-icon="i-lucide-arrow-right"
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
