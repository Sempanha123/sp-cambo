<script setup lang="ts">
import type { PublicPackage } from '~/types/commerce'

useSeoMeta({
  title: 'Pricing',
  description: 'Prepaid token and credit packages for SP Cambo. Every package has a published price, an exact lifetime, and a fixed set of allowed models.'
})

const api = useSpApi()
const auth = useAuthStore()
const packages = await useSpResource('catalog:packages', () => api.catalog.packages())

const sorted = computed(() => [...(packages.data.value ?? [])].sort((a, b) => a.sort_order - b.sort_order))
const selectedFamily = ref<'all' | string>('all')
const selectedKind = ref<'all' | 'SP_TOKENS' | 'SP_CREDITS'>('all')

const isSoldOut = (item: PublicPackage) => item.stock_remaining !== null && BigInt(item.stock_remaining) <= 0n

const stockLabel = (item: PublicPackage) => {
  if (item.stock_remaining === null) return 'Available'
  if (isSoldOut(item)) return 'Sold out'
  return `${formatUnits(item.stock_remaining)} left`
}

const packageKind = (item: PublicPackage): 'SP_TOKENS' | 'SP_CREDITS' => {
  if (item.package_kind === 'SP_CREDITS' || ['Credits', 'SP Credits'].includes(item.display_unit_label ?? '')) return 'SP_CREDITS'
  return 'SP_TOKENS'
}

const familyTabs = computed(() => {
  const seen = new Map<string, string>()
  for (const item of sorted.value) seen.set(item.family, item.family_label)

  return [
    { value: 'all', label: 'All models' },
    ...[...seen.entries()].map(([value, label]) => ({ value, label }))
  ]
})

const filtered = computed(() => sorted.value.filter(item =>
  (selectedFamily.value === 'all' || item.family === selectedFamily.value)
  && (selectedKind.value === 'all' || packageKind(item) === selectedKind.value)
))

const billingModeLabel = (item: PublicPackage) =>
  packageKind(item) === 'SP_CREDITS' ? 'Credits' : 'Tokens'

// Customer-facing wording never exposes the old "SP Tokens / SP Credits" labels.
// Keep the replacements so already-seeded production rows render cleanly before reseeding.
const customerLabel = (value: string | null | undefined) => (value ?? '')
  .replaceAll('SP Tokens', 'Tokens')
  .replaceAll('SP Credits', 'Credits')
  .replaceAll('SP Credit', 'Credit')
  .replaceAll('SP billable tokens', 'Tokens')
  .replaceAll('SP billable units', 'Tokens')

const includedLabel = (item: PublicPackage): string => {
  if (item.display_units && item.display_unit_label) {
    const label = customerLabel(item.display_unit_label)
    const creditLabel = BigInt(item.display_units) === 1n ? 'Credit' : 'Credits'
    return label === 'Credits'
      ? `$${formatUnits(item.display_units)} ${creditLabel}`
      : `${formatUnits(item.display_units)} ${label}`
  }

  if (item.billing_mode === 'CREDIT_BALANCE' && item.credit_amount) {
    return `${formatMoney(item.credit_amount)} credit`
  }

  return `${formatUnits(item.advertised_units)} ${customerLabel(item.unit_label)}`
}

const primaryModel = (item: PublicPackage) => item.allowed_model_aliases[0] || item.family_label

const faqs = [
  {
    label: 'What happens when a package runs out?',
    content: 'Requests are refused with a clear machine-readable error instead of being billed as overage. Buy another package and access resumes immediately.'
  },
  {
    label: 'How is a package lifetime measured?',
    content: 'In exact seconds from activation. A package listed as 24 hours expires exactly 24 hours after it activates, not at midnight.'
  },
  {
    label: 'Which package is spent first?',
    content: 'The one that expires first. SP Cambo spends first-expiring-first-out so you do not lose value to expiry while a longer-lived package sits unused.'
  },
  {
    label: 'Are estimates ever billed?',
    content: 'No. During a request SP Cambo reserves a maximum estimate, then settles only the locally measured input + delivered output.'
  },
  {
    label: 'How do I pay?',
    content: 'With Bakong KHQR. Access activates once the backend verifies the payment.'
  },
  {
    label: 'Do packages renew automatically?',
    content: 'No. Nothing renews and no payment method is stored. Every purchase is a deliberate one-off.'
  }
]
</script>

<template>
  <div class="sp-r8-pricing-page">
    <UContainer class="pt-10 sm:pt-14">
      <section class="sp-r8-pricing-hero">
        <div class="max-w-3xl space-y-5">
          <div class="flex flex-wrap items-center gap-2">
            <UBadge color="neutral" variant="subtle" class="rounded-full">
              PREPAID · NO SUBSCRIPTION
            </UBadge>
            <span class="sp-r8-pricing-kicker">Pay once · use until spent or expired</span>
          </div>

          <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance sm:text-5xl lg:text-6xl">
            Choose a package that
            <span class="sp-r8-pricing-gradient">fits your workload.</span>
          </h1>

          <p class="max-w-2xl text-base leading-7 text-muted sm:text-lg">
            Compare Tokens and Credits without the long wall of boxes.
            Filter by model family, see the real backend price, and buy only what you need.
          </p>

          <div class="flex flex-wrap gap-2">
            <span class="sp-r8-benefit"><UIcon name="i-lucide-shield-check" class="size-3.5" /> Prepaid control</span>
            <span class="sp-r8-benefit"><UIcon name="i-lucide-repeat-2" class="size-3.5" /> Smart reuse</span>
            <span class="sp-r8-benefit"><UIcon name="i-lucide-qr-code" class="size-3.5" /> Bakong KHQR</span>
            <span class="sp-r8-benefit"><UIcon name="i-lucide-key-round" class="size-3.5" /> API access</span>
          </div>
        </div>

        <div class="sp-r8-pricing-visual" aria-hidden="true">
          <div class="sp-r8-pricing-visual__ring sp-r8-pricing-visual__ring--a" />
          <div class="sp-r8-pricing-visual__ring sp-r8-pricing-visual__ring--b" />
          <div class="sp-r8-pricing-visual__core">
            <UIcon name="i-lucide-wallet-cards" class="size-10" />
          </div>
          <span class="sp-r8-pricing-visual__node sp-r8-pricing-visual__node--a"><UIcon name="i-lucide-brain-circuit" class="size-4" /></span>
          <span class="sp-r8-pricing-visual__node sp-r8-pricing-visual__node--b"><UIcon name="i-lucide-code-xml" class="size-4" /></span>
          <span class="sp-r8-pricing-visual__node sp-r8-pricing-visual__node--c"><UIcon name="i-lucide-zap" class="size-4" /></span>
        </div>
      </section>

      <div class="sp-r8-pricing-filter mt-7">
        <div class="sp-r8-pricing-tabs">
          <button
            v-for="tab in familyTabs"
            :key="tab.value"
            type="button"
            class="sp-r8-pricing-tab"
            :class="{ 'sp-r8-pricing-tab--active': selectedFamily === tab.value }"
            @click="selectedFamily = tab.value"
          >
            <SpPublicAliasIcon
              v-if="tab.value !== 'all'"
              :alias="tab.label"
              :label="tab.label"
              size="xs"
            />
            <UIcon v-else name="i-lucide-layout-grid" class="size-3.5" />
            {{ tab.label }}
          </button>
        </div>

        <div class="sp-r8-kind-tabs">
          <button
            type="button"
            :class="{ 'sp-r8-kind-tab--active': selectedKind === 'all' }"
            @click="selectedKind = 'all'"
          >
            All
          </button>
          <button
            type="button"
            :class="{ 'sp-r8-kind-tab--active': selectedKind === 'SP_TOKENS' }"
            @click="selectedKind = 'SP_TOKENS'"
          >
            Tokens
          </button>
          <button
            type="button"
            :class="{ 'sp-r8-kind-tab--active': selectedKind === 'SP_CREDITS' }"
            @click="selectedKind = 'SP_CREDITS'"
          >
            Credits
          </button>
        </div>
      </div>
    </UContainer>

    <UContainer class="pb-14 pt-6 sm:pb-16">
      <SpAsyncSection
        :loading="packages.initialLoading.value"
        :unavailable="packages.unavailable.value"
        :failed="packages.failed.value"
        :empty="packages.isEmpty.value"
        :offline="packages.error.value?.code === 'network_unreachable'"
        :error-message="packages.error.value?.message"
        unavailable-description="Package pricing is published by the SP Cambo control plane. It has not been made available yet, so no prices are shown here."
        empty-title="No packages on sale yet"
        empty-description="Packages appear here as soon as an administrator publishes them."
        empty-icon="i-lucide-package"
        loading-variant="cards"
        :loading-count="6"
        @retry="packages.refresh()"
      >
        <div
          v-if="filtered.length === 0"
          class="sp-r8-pricing-empty"
        >
          No packages match these filters.
        </div>

        <div
          v-else
          class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
          <article
            v-for="item in filtered"
            :key="item.slug"
            class="sp-r8-package-card"
            :class="{
              'sp-r8-package-card--featured': item.featured,
              'sp-r8-package-card--soldout': isSoldOut(item)
            }"
          >
            <div class="sp-r8-package-card__top">
              <div class="flex min-w-0 items-start gap-3">
                <SpModelLogo
                  :model="primaryModel(item)"
                  :label="item.family_label"
                  size="md"
                />

                <div class="min-w-0">
                  <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <h2 class="truncate text-base font-semibold text-highlighted">
                      {{ customerLabel(item.name) }}
                    </h2>
                    <UBadge
                      v-if="item.featured"
                      color="primary"
                      variant="subtle"
                      size="xs"
                    >
                      Popular
                    </UBadge>
                  </div>

                  <p
                    v-if="item.subtitle"
                    class="mt-1 line-clamp-2 text-xs leading-5 text-muted"
                  >
                    {{ customerLabel(item.subtitle) }}
                  </p>
                </div>
              </div>

              <UBadge
                :color="isSoldOut(item) ? 'error' : 'success'"
                variant="subtle"
                size="xs"
              >
                {{ stockLabel(item) }}
              </UBadge>
            </div>

            <div class="sp-r8-package-card__price">
              <span>{{ formatMoney(item.price) }}</span>
              <small>{{ billingModeLabel(item) }} · one-off</small>
            </div>

            <dl class="sp-r8-package-card__specs">
              <div>
                <dt>Includes</dt>
                <dd>{{ includedLabel(item) }}</dd>
              </div>
              <div>
                <dt>Lifetime</dt>
                <dd>{{ formatDurationSeconds(item.duration_seconds) }}</dd>
              </div>
              <div>
                <dt>Requests / min</dt>
                <dd>{{ item.limits.requests_per_minute === null ? 'Standard' : formatCount(item.limits.requests_per_minute) }}</dd>
              </div>
              <div>
                <dt>Concurrency</dt>
                <dd>{{ item.limits.concurrency === null ? 'Standard' : formatCount(item.limits.concurrency) }}</dd>
              </div>
            </dl>

            <div
              v-if="item.allowed_model_aliases.length"
              class="space-y-2"
            >
              <p class="text-[10px] font-semibold tracking-[.12em] text-dimmed uppercase">
                Public aliases
              </p>
              <div class="flex flex-wrap gap-1.5">
                <SpModelBadge
                  v-for="alias in item.allowed_model_aliases.slice(0, 4)"
                  :key="alias"
                  :model="alias"
                  compact
                />
                <UBadge
                  v-if="item.allowed_model_aliases.length > 4"
                  color="neutral"
                  variant="subtle"
                  size="xs"
                >
                  +{{ item.allowed_model_aliases.length - 4 }}
                </UBadge>
              </div>
            </div>

            <div class="sp-r8-package-card__footer">
              <div class="flex items-center gap-2 text-[11px] text-muted">
                <UIcon name="i-lucide-check-circle-2" class="size-3.5 text-success" />
                <span>{{ item.auto_creates_api_key ? 'API access included' : 'Playground access' }}</span>
              </div>

              <UButton
                :to="isSoldOut(item) ? undefined : (auth.authenticated ? `/dashboard/buy?package=${item.slug}` : '/register')"
                :disabled="isSoldOut(item)"
                :color="item.featured ? 'primary' : 'neutral'"
                :variant="item.featured ? 'solid' : 'subtle'"
                size="sm"
                block
                :trailing-icon="isSoldOut(item) ? undefined : 'i-lucide-arrow-right'"
              >
                {{ isSoldOut(item) ? 'Sold out' : (auth.authenticated ? 'Choose package' : 'Create account to buy') }}
              </UButton>
            </div>
          </article>
        </div>
      </SpAsyncSection>
    </UContainer>

    <UContainer class="pb-14">
      <section class="sp-r8-billing-panel">
        <div class="space-y-3">
          <span class="sp-r8-billing-panel__icon">
            <UIcon name="i-lucide-gauge" class="size-5" />
          </span>
          <h2 class="text-2xl font-semibold tracking-tight text-highlighted">
            Clear billing, less visual noise.
          </h2>
          <p class="max-w-lg text-sm leading-6 text-muted">
            New input/output is metered normally. Repeated prompt prefixes can use the smart-reuse rate,
            and Credits settle against SP Cambo's local meter. Nothing renews automatically.
          </p>
          <UButton to="/docs/billing" color="neutral" variant="subtle" size="sm" trailing-icon="i-lucide-arrow-right">
            Billing reference
          </UButton>
        </div>

        <div>
          <UAccordion :items="faqs" />
        </div>
      </section>
    </UContainer>
  </div>
</template>

<style scoped>
.sp-r8-pricing-hero {
  position: relative;
  min-height: 22rem;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1.75rem;
  background:
    radial-gradient(circle at 82% 44%, rgb(63 99 255 / .11), transparent 21rem),
    linear-gradient(145deg, rgb(255 255 255 / .016), transparent 45%),
    color-mix(in oklab, var(--ui-bg-elevated) 34%, transparent);
  padding: 2rem;
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .025);
  backdrop-filter: blur(16px);
}

.sp-r8-pricing-kicker {
  font-size: .68rem;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--ui-text-dimmed);
}

.sp-r8-pricing-gradient {
  color: transparent;
  background: linear-gradient(110deg, rgb(97 160 255), rgb(80 215 255), rgb(130 100 255));
  background-size: 180% 100%;
  background-clip: text;
  -webkit-background-clip: text;
  animation: sp-r8-pricing-gradient 7s linear infinite;
}

.sp-r8-benefit {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 9999px;
  background: color-mix(in oklab, var(--ui-bg) 34%, transparent);
  padding: .42rem .65rem;
  font-size: .7rem;
  color: var(--ui-text-muted);
}

.sp-r8-pricing-visual {
  position: absolute;
  right: -4rem;
  top: 50%;
  width: 30rem;
  height: 24rem;
  transform: translateY(-50%);
}

.sp-r8-pricing-visual__ring {
  position: absolute;
  left: 50%;
  top: 50%;
  border: 1px solid rgb(96 132 255 / .14);
  border-radius: 9999px;
}

.sp-r8-pricing-visual__ring--a {
  width: 18rem;
  height: 12rem;
  transform: translate(-50%, -50%) rotate(-14deg);
  animation: sp-r8-pricing-ring-a 18s linear infinite;
}

.sp-r8-pricing-visual__ring--b {
  width: 15rem;
  height: 15rem;
  transform: translate(-50%, -50%);
  border-color: rgb(125 84 255 / .11);
  animation: sp-r8-pricing-ring-b 23s linear infinite reverse;
}

.sp-r8-pricing-visual__core {
  position: absolute;
  left: 50%;
  top: 50%;
  display: grid;
  width: 7rem;
  height: 7rem;
  place-items: center;
  transform: translate(-50%, -50%) rotate(-6deg);
  border: 1px solid rgb(139 157 255 / .18);
  border-radius: 30%;
  color: rgb(165 194 255);
  background: linear-gradient(145deg, rgb(61 91 210 / .68), rgb(73 61 167 / .68));
  box-shadow: 0 22px 55px rgb(30 47 133 / .20);
  animation: sp-r8-pricing-core 5.4s ease-in-out infinite;
}

.sp-r8-pricing-visual__node {
  position: absolute;
  display: grid;
  width: 3rem;
  height: 3rem;
  place-items: center;
  border: 1px solid rgb(130 147 208 / .12);
  border-radius: .9rem;
  color: rgb(185 201 245);
  background: rgb(19 30 59 / .6);
  backdrop-filter: blur(10px);
  animation: sp-r8-pricing-node 5s ease-in-out infinite;
}

.sp-r8-pricing-visual__node--a { left: 17%; top: 21%; animation-delay: -1.1s; }
.sp-r8-pricing-visual__node--b { right: 14%; top: 42%; animation-delay: -2.3s; }
.sp-r8-pricing-visual__node--c { right: 30%; bottom: 10%; animation-delay: -3.6s; }

.sp-r8-pricing-filter {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1rem;
  background: color-mix(in oklab, var(--ui-bg-elevated) 38%, transparent);
  padding: .55rem;
  backdrop-filter: blur(14px);
}

.sp-r8-pricing-tabs,
.sp-r8-kind-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: .35rem;
}

.sp-r8-pricing-tab,
.sp-r8-kind-tabs button {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  border: 1px solid transparent;
  border-radius: .75rem;
  padding: .5rem .65rem;
  font-size: .72rem;
  color: var(--ui-text-muted);
  transition: border-color .2s ease, background-color .2s ease, color .2s ease;
}

.sp-r8-pricing-tab:hover,
.sp-r8-kind-tabs button:hover {
  color: var(--ui-text-default);
}

.sp-r8-pricing-tab--active,
.sp-r8-kind-tab--active {
  border-color: color-mix(in oklab, var(--ui-primary) 22%, transparent) !important;
  background: color-mix(in oklab, var(--ui-primary) 8%, transparent);
  color: var(--ui-text-highlighted) !important;
}

.sp-r8-package-card {
  position: relative;
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 1rem;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1.1rem;
  background:
    linear-gradient(145deg, rgb(255 255 255 / .014), transparent 44%),
    color-mix(in oklab, var(--ui-bg-elevated) 40%, transparent);
  padding: 1rem;
  box-shadow: inset 0 1px 0 rgb(255 255 255 / .02);
  backdrop-filter: blur(13px);
  transition: transform .24s ease, border-color .24s ease, box-shadow .24s ease;
}

.sp-r8-package-card:hover {
  transform: translateY(-2px);
  border-color: color-mix(in oklab, var(--ui-primary) 18%, transparent);
  box-shadow: 0 20px 45px -38px rgb(61 105 255 / .22);
}

.sp-r8-package-card--featured {
  border-color: color-mix(in oklab, var(--ui-primary) 28%, transparent);
  background:
    linear-gradient(145deg, color-mix(in oklab, var(--ui-primary) 5%, transparent), transparent 45%),
    color-mix(in oklab, var(--ui-bg-elevated) 44%, transparent);
}

.sp-r8-package-card--soldout {
  opacity: .58;
}

.sp-r8-package-card__top {
  display: flex;
  min-width: 0;
  align-items: flex-start;
  justify-content: space-between;
  gap: .75rem;
}

.sp-r8-package-card__price span,
.sp-r8-package-card__price small {
  display: block;
}

.sp-r8-package-card__price span {
  font-size: 1.55rem;
  font-weight: 650;
  color: var(--ui-text-highlighted);
}

.sp-r8-package-card__price small {
  margin-top: .15rem;
  font-size: .68rem;
  color: var(--ui-text-muted);
}

.sp-r8-package-card__specs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .7rem 1rem;
  border-top: 1px solid rgb(255 255 255 / .04);
  padding-top: .8rem;
}

.sp-r8-package-card__specs dt {
  font-size: .63rem;
  color: var(--ui-text-dimmed);
}

.sp-r8-package-card__specs dd {
  margin-top: .12rem;
  overflow-wrap: anywhere;
  font-size: .76rem;
  color: var(--ui-text-default);
}

.sp-r8-package-card__footer {
  display: grid;
  gap: .65rem;
  margin-top: auto;
  border-top: 1px solid rgb(255 255 255 / .04);
  padding-top: .8rem;
}

.sp-r8-pricing-empty {
  border: 1px dashed rgb(255 255 255 / .08);
  border-radius: 1rem;
  padding: 3rem 1rem;
  text-align: center;
  font-size: .875rem;
  color: var(--ui-text-muted);
}

.sp-r8-billing-panel {
  display: grid;
  gap: 2rem;
  border: 1px solid rgb(255 255 255 / .045);
  border-radius: 1.4rem;
  background:
    radial-gradient(circle at 10% 20%, rgb(70 107 255 / .07), transparent 17rem),
    color-mix(in oklab, var(--ui-bg-elevated) 34%, transparent);
  padding: 1.2rem;
  backdrop-filter: blur(14px);
}

.sp-r8-billing-panel__icon {
  display: grid;
  width: 2.7rem;
  height: 2.7rem;
  place-items: center;
  border-radius: .85rem;
  background: color-mix(in oklab, var(--ui-primary) 9%, transparent);
  color: var(--ui-primary);
}

@keyframes sp-r8-pricing-gradient {
  from { background-position: 0% 50%; }
  to { background-position: 180% 50%; }
}

@keyframes sp-r8-pricing-ring-a {
  from { transform: translate(-50%, -50%) rotate(-14deg); }
  to { transform: translate(-50%, -50%) rotate(346deg); }
}

@keyframes sp-r8-pricing-ring-b {
  from { transform: translate(-50%, -50%) rotate(0); }
  to { transform: translate(-50%, -50%) rotate(360deg); }
}

@keyframes sp-r8-pricing-core {
  0%, 100% { transform: translate(-50%, -50%) rotate(-6deg) translateY(0); }
  50% { transform: translate(-50%, -50%) rotate(-3deg) translateY(-9px); }
}

@keyframes sp-r8-pricing-node {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-7px); }
}

@media (min-width: 1024px) {
  .sp-r8-pricing-hero {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    align-items: center;
    padding: 2.5rem;
  }

  .sp-r8-billing-panel {
    grid-template-columns: .85fr 1.15fr;
    padding: 1.5rem;
  }
}

@media (max-width: 1023px) {
  .sp-r8-pricing-visual {
    opacity: .24;
    right: -13rem;
  }
}

@media (max-width: 639px) {
  .sp-r8-pricing-hero {
    min-height: auto;
    padding: 1.1rem;
  }

  .sp-r8-pricing-visual {
    display: none;
  }

  .sp-r8-pricing-filter {
    align-items: stretch;
    flex-direction: column;
  }

  .sp-r8-kind-tabs {
    border-top: 1px solid rgb(255 255 255 / .04);
    padding-top: .5rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sp-r8-pricing-page *,
  .sp-r8-pricing-page *::before,
  .sp-r8-pricing-page *::after {
    animation-duration: .001ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .001ms !important;
  }
}
</style>
