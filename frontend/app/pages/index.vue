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
    description: 'Issue keys per project or per package. Full secrets are shown once at creation, then only a prefix and last four digits. Rotate or revoke instantly.'
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
    <section class="sp-public-hero relative overflow-hidden">
      <div
        class="sp-grid-backdrop pointer-events-none absolute inset-0"
        aria-hidden="true"
      />
      <div
        class="sp-khmer-motif pointer-events-none absolute inset-y-0 right-0 w-[36rem] opacity-[0.055]"
        aria-hidden="true"
      />
      <div
        class="sp-ambient-glow pointer-events-none absolute inset-x-0 -top-32 h-96"
        aria-hidden="true"
      />

      <UContainer class="relative py-16 sm:py-24 lg:py-28">
        <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
          <div class="space-y-7">
            <UBadge
              color="neutral"
              variant="subtle"
              size="lg"
              class="rounded-full"
            >
              <span class="flex items-center gap-2">
                <span class="relative flex size-1.5">
                  <span class="absolute inline-flex size-full animate-ping rounded-full bg-primary opacity-75" />
                  <span class="relative inline-flex size-1.5 rounded-full bg-primary" />
                </span>
                <span class="sp-khmer-chip">កម្ពុជា</span>
                Prepaid AI access for Cambodia and beyond
              </span>
            </UBadge>

            <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance sm:text-5xl lg:text-6xl">
              <span class="sp-gradient-text">Managed AI access</span> you can actually budget for
            </h1>

            <p class="max-w-xl text-lg text-muted text-pretty">
              Buy prepaid token or credit packages, issue scoped API keys, and run Claude Code,
              Codex CLI or your own SDK against one endpoint. Every request is metered exactly and
              settled against real provider usage.
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

            <div class="inline-flex items-center gap-2 rounded-full border border-default/70 bg-elevated/35 px-3 py-1.5 text-xs text-muted">
              <span class="sp-khmer-rule !h-px !w-8" />
              <span style="font-family: 'Noto Sans Khmer', 'Khmer OS System', sans-serif;">បច្ចេកវិទ្យា AI សម្រាប់កម្ពុជា</span>
              <span class="text-dimmed">· Built for developers everywhere</span>
            </div>
          </div>

          <div class="space-y-4 lg:pl-4">
            <SpCodeBlock
              filename="Claude Code — bash"
              :code="claudeCodeShell"
            />
            <p class="text-xs text-muted">
              Replace the placeholder key with a key you create in the dashboard, and the model
              placeholder with an alias from
              <NuxtLink
                to="/models"
                class="text-default underline decoration-dotted underline-offset-2"
              >the model
                catalogue
              </NuxtLink>.
            </p>
          </div>
        </div>
      </UContainer>
    </section>

    <USeparator />

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
          class="sp-premium-card sp-key-checker-card space-y-3 rounded-xl border border-default bg-elevated/30 p-6"
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
          class="sp-premium-card space-y-3 rounded-xl border border-default bg-elevated/30 p-6"
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

    <div class="border-y border-default bg-elevated/25">
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
        >
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
        class="sp-premium-card relative overflow-hidden rounded-2xl border border-default bg-elevated/40 px-6 py-12 text-center sm:px-12"
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
