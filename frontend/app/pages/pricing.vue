<script setup lang="ts">
import type { PublicPackage } from '~/types/commerce'

useSeoMeta({
  title: 'Pricing',
  description: 'Prepaid token and credit packages for SP Cambo. Every package has a published price, an exact lifetime, and a fixed set of allowed models. No subscriptions and no overage billing.'
})

const api = useSpApi()
const auth = useAuthStore()
const packages = await useSpResource('catalog:packages', () => api.catalog.packages())

const sorted = computed(() => [...(packages.data.value ?? [])].sort((a, b) => a.sort_order - b.sort_order))

const isSoldOut = (item: PublicPackage) => item.stock_remaining !== null && BigInt(item.stock_remaining) <= 0n
const stockLabel = (item: PublicPackage) => {
  if (item.stock_remaining === null) return 'Available'
  if (isSoldOut(item)) return 'Sold out'
  return `${formatUnits(item.stock_remaining)} left`
}
const familyDescription = (label: string) => {
  const brand = modelPresentation(label, label).brand
  if (brand === 'anthropic') return 'Claude-compatible models routed through one stable SP Cambo family.'
  if (brand === 'openai') return 'Codex and GPT-style public models with stable SP Cambo model IDs.'
  if (brand === 'gemini') return 'Fast Gemini models for chat, coding and general AI workloads.'
  return 'Prepaid model access with clear limits and no automatic renewal.'
}

/** Groups by the admin-defined family so families can be compared side by side. */
const groups = computed(() => {
  const map = new Map<string, { label: string, items: PublicPackage[] }>()

  for (const item of sorted.value) {
    const group = map.get(item.family) ?? { label: item.family_label, items: [] }

    group.items.push(item)
    map.set(item.family, group)
  }

  return [...map.values()]
})

const billingModeLabel = (item: PublicPackage) => {
  if (['Credits', 'SP Credits'].includes(item.display_unit_label ?? '')) return 'Credit quota'
  return item.billing_mode === 'TOKEN_QUOTA' ? 'Token quota' : 'Credit balance'
}

const includedLabel = (item: PublicPackage): string => {
  if (item.display_units && item.display_unit_label) {
    return ['Credits', 'SP Credits'].includes(item.display_unit_label)
      ? `$${formatUnits(item.display_units)} Credits`
      : `${formatUnits(item.display_units)} ${item.display_unit_label}`
  }

  if (item.billing_mode === 'CREDIT_BALANCE' && item.credit_amount) {
    return `${formatMoney(item.credit_amount)} credit`
  }

  return `${formatUnits(item.advertised_units)} ${item.unit_label}`
}

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
    content: 'No. During a request SP Cambo reserves a maximum estimate, then settles only the locally measured input + delivered output. Unused reservation is returned. OmniRoute/provider usage counters are never used to change your balance.'
  },
  {
    label: 'Are Tokens the exact raw provider token count?',
    content: 'Tokens use a simple local 1:1 meter: one locally estimated model-visible input Token and one locally generated output Token each consume one Token. The count is deterministic but is not claimed to be an exact vendor-private tokenizer count.'
  },
  {
    label: 'How do I pay?',
    content: 'With Bakong KHQR. Scan the QR shown at checkout; access activates once our backend verifies the payment. We never mark an order paid from the browser.'
  },
  {
    label: 'Do packages renew automatically?',
    content: 'No. Nothing renews and no payment method is stored. Every purchase is a deliberate one-off.'
  }
]
</script>

<template>
  <div>
    <UContainer class="py-14 sm:py-16">
      <div class="max-w-3xl space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance">
          Prepaid packages, published prices
        </h1>
        <p class="text-lg text-muted text-pretty">
          Choose a model family, compare packages at a glance, and pay once. Larger bundles have a lower effective unit price, while smart reuse discounts repeated context. Model logos, availability, included Tokens and expiry are shown clearly before checkout.
        </p>
      </div>

      <div class="mt-10">
        <SpAsyncSection
          :loading="packages.initialLoading.value"
          :unavailable="packages.unavailable.value"
          :failed="packages.failed.value"
          :empty="packages.isEmpty.value"
          :offline="packages.error.value?.code === 'network_unreachable'"
          :error-message="packages.error.value?.message"
          unavailable-description="Package pricing is published by the SP Cambo control plane. It has not been made available yet, so no prices are shown here. We do not display example prices."
          empty-title="No packages on sale yet"
          empty-description="Packages appear here as soon as an administrator publishes them."
          empty-icon="i-lucide-package"
          loading-variant="cards"
          :loading-count="3"
          @retry="packages.refresh()"
        >
          <div class="space-y-12">
            <section
              v-for="group in groups"
              :key="group.label"
              class="space-y-5"
            >
              <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <SpModelFamilyHeader :family="group.label" :description="familyDescription(group.label)" />
                <p class="text-xs text-muted">{{ group.items.length }} package{{ group.items.length === 1 ? '' : 's' }}</p>
              </div>

              <div class="grid gap-5 lg:grid-cols-3">
                <article
                  v-for="item in group.items"
                  :key="item.slug"
                  class="relative flex flex-col gap-5 rounded-2xl border bg-elevated/30 p-6 transition"
                  :class="[
                    item.featured ? 'border-primary/60 ring-1 ring-primary/25' : 'border-default',
                    isSoldOut(item) ? 'opacity-65' : 'hover:-translate-y-0.5 hover:border-primary/30'
                  ]"
                >
                  <UBadge
                    v-if="item.badge"
                    class="absolute -top-2.5 right-5"
                    color="primary"
                    variant="solid"
                    size="sm"
                  >
                    {{ item.badge }}
                  </UBadge>

                  <div class="flex items-start gap-3">
                    <SpModelLogo :model="item.allowed_model_aliases[0] || item.family_label" :label="item.family_label" size="md" />
                    <div class="min-w-0 flex-1 space-y-1.5">
                      <h3 class="text-lg font-semibold text-highlighted">
                        {{ item.name }}
                      </h3>
                      <p v-if="item.subtitle" class="text-sm text-muted">
                        {{ item.subtitle }}
                      </p>
                      <UBadge
                        :color="isSoldOut(item) ? 'error' : 'success'"
                        variant="subtle"
                        size="sm"
                      >
                        {{ stockLabel(item) }}
                      </UBadge>
                    </div>
                  </div>

                  <div class="space-y-1">
                    <div class="flex items-end gap-2">
                      <span class="sp-numeric text-3xl font-semibold tracking-tight text-highlighted">
                        {{ formatMoney(item.price) }}
                      </span>
                      <span
                        v-if="item.compare_at_price"
                        class="sp-numeric pb-1 text-sm text-dimmed line-through"
                      >
                        {{ formatMoney(item.compare_at_price) }}
                      </span>
                    </div>
                    <p class="text-xs text-muted">
                      One-off payment · {{ billingModeLabel(item) }}
                    </p>
                  </div>

                  <dl class="space-y-2.5 border-t border-default pt-4 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                      <dt class="text-muted">
                        Included
                      </dt>
                      <dd class="sp-numeric font-medium text-highlighted">
                        {{ includedLabel(item) }}
                      </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                      <dt class="text-muted">
                        Lifetime
                      </dt>
                      <dd class="font-medium text-highlighted">
                        {{ formatDurationSeconds(item.duration_seconds) }}
                      </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                      <dt class="text-muted">
                        Requests / minute
                      </dt>
                      <dd class="sp-numeric font-medium text-highlighted">
                        {{ item.limits.requests_per_minute === null ? 'Standard' : formatCount(item.limits.requests_per_minute) }}
                      </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                      <dt class="text-muted">
                        Concurrency
                      </dt>
                      <dd class="sp-numeric font-medium text-highlighted">
                        {{ item.limits.concurrency === null ? 'Standard' : formatCount(item.limits.concurrency) }}
                      </dd>
                    </div>
                  </dl>

                  <div
                    v-if="item.allowed_model_aliases.length > 0"
                    class="space-y-2"
                  >
                    <p class="text-xs font-medium tracking-wide text-muted uppercase">
                      Works with
                    </p>
                    <ul class="flex flex-wrap gap-2">
                      <li v-for="alias in item.allowed_model_aliases" :key="alias">
                        <SpModelBadge :model="alias" compact />
                      </li>
                    </ul>
                  </div>

                  <ul class="space-y-1.5 text-sm text-muted">
                    <li
                      v-if="item.auto_creates_api_key"
                      class="flex items-start gap-2"
                    >
                      <UIcon
                        name="i-lucide-check"
                        class="mt-0.5 size-4 shrink-0 text-primary"
                      />
                      Automatic default API access is included after payment
                    </li>
                    <li class="flex items-start gap-2">
                      <UIcon
                        name="i-lucide-check"
                        class="mt-0.5 size-4 shrink-0 text-primary"
                      />
                      Spent first-expiring-first-out
                    </li>
                  </ul>

                  <UButton
                    :to="isSoldOut(item) ? undefined : (auth.authenticated ? `/dashboard/buy?package=${item.slug}` : '/register')"
                    :color="isSoldOut(item) ? 'neutral' : (item.featured ? 'primary' : 'neutral')"
                    :variant="isSoldOut(item) ? 'outline' : (item.featured ? 'solid' : 'subtle')"
                    :disabled="isSoldOut(item)"
                    block
                    class="mt-auto"
                    :trailing-icon="isSoldOut(item) ? undefined : 'i-lucide-arrow-right'"
                  >
                    {{ isSoldOut(item) ? 'Sold out' : (auth.authenticated ? 'Choose package' : 'Create account to buy') }}
                  </UButton>
                </article>
              </div>
            </section>
          </div>
        </SpAsyncSection>
      </div>
    </UContainer>

    <div class="border-y border-default bg-elevated/25">
      <UContainer class="py-14">
        <div class="grid gap-8 lg:grid-cols-3">
          <div class="space-y-3">
            <h2 class="text-2xl font-semibold tracking-tight text-highlighted">
              How billing works
            </h2>
            <p class="text-sm text-muted text-pretty">
              Two prepaid package types. New input/output is metered 1:1; repeated prompt prefixes detected by SP Cambo's local cache use 0.25× Tokens. $1 Credit settles as 100,000 billable Tokens. Credits are not withdrawable cash.
            </p>
            <UButton
              to="/docs/billing"
              color="neutral"
              variant="subtle"
              size="sm"
              trailing-icon="i-lucide-arrow-right"
            >
              Billing reference
            </UButton>
          </div>

          <div class="lg:col-span-2">
            <UAccordion :items="faqs" />
          </div>
        </div>
      </UContainer>
    </div>

    <UContainer class="py-14">
      <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
        <div class="space-y-1">
          <p class="font-medium text-highlighted">
            Reselling SP Cambo access?
          </p>
          <p class="text-sm text-muted">
            Commercial-use allocation is handled separately from retail packages.
          </p>
        </div>
        <UButton
          to="/resellers"
          color="neutral"
          variant="subtle"
          trailing-icon="i-lucide-arrow-right"
        >
          Reseller programme
        </UButton>
      </div>
    </UContainer>
  </div>
</template>
