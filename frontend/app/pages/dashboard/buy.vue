<script setup lang="ts">
import type { PromotionPreview, PublicPackage } from '~/types/commerce'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Buy tokens & credits',
  description: 'Choose a package and pay with Bakong KHQR. Prices, quantities and totals all come from SP Cambo.',
  robots: 'noindex'
})

const api = useSpApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const packages = await useSpResource('catalog:packages', () => api.catalog.packages(), { server: false })

const selectedSlug = ref<string | null>(null)
const quantity = ref(1)
const familyFilter = ref<'all' | 'claude' | 'codex' | 'gemini'>('all')
const modeFilter = ref<'all' | 'SP_TOKENS' | 'SP_CREDITS'>('all')

/** `?package=<slug>` deep-links from the public pricing page. */
watch([() => route.query.package, packages.data], ([slug, list]) => {
  if (!list) {
    return
  }

  const requested = typeof slug === 'string' ? list.find(item => item.slug === slug) : undefined

  if (requested) {
    selectedSlug.value = requested.slug
    familyFilter.value = packageFamily(requested)
    modeFilter.value = packageKind(requested)
  } else if (!selectedSlug.value) {
    selectedSlug.value = (list.find(item => item.featured && !isSoldOut(item)) ?? list.find(item => !isSoldOut(item)) ?? list[0])?.slug ?? null
  }
}, { immediate: true })

const sorted = computed(() => [...(packages.data.value ?? [])].sort((a, b) => a.sort_order - b.sort_order))
const familyFilters = [
  { value: 'all' as const, label: 'All models' },
  { value: 'claude' as const, label: 'Claude' },
  { value: 'codex' as const, label: 'GPT / Codex' },
  { value: 'gemini' as const, label: 'Gemini' }
]
function packageFamily(item: PublicPackage): 'claude' | 'codex' | 'gemini' {
  const value = `${item.family} ${item.family_label} ${item.allowed_model_aliases.join(' ')}`.toLowerCase()
  if (value.includes('claude') || value.includes('opus') || value.includes('sonnet') || value.includes('haiku')) return 'claude'
  if (value.includes('gemini')) return 'gemini'
  return 'codex'
}
function packageKind(item: PublicPackage): 'SP_TOKENS' | 'SP_CREDITS' {
  if (item.package_kind === 'SP_CREDITS' || ['Credits', 'SP Credits'].includes(item.display_unit_label ?? '')) return 'SP_CREDITS'
  return 'SP_TOKENS'
}
const filteredPackages = computed(() => sorted.value.filter(item =>
  (familyFilter.value === 'all' || packageFamily(item) === familyFilter.value)
  && (modeFilter.value === 'all' || packageKind(item) === modeFilter.value)
))
function isSoldOut(item: PublicPackage) { return item.stock_remaining !== null && BigInt(item.stock_remaining) <= 0n }
const stockLabel = (item: PublicPackage) => item.stock_remaining === null
  ? 'Available'
  : isSoldOut(item)
    ? 'Sold out'
    : `${formatUnits(item.stock_remaining)} left`
const primaryModel = (item: PublicPackage) => item.allowed_model_aliases[0] || item.family_label

const selected = computed<PublicPackage | null>(() =>
  sorted.value.find(item => item.slug === selectedSlug.value) ?? null
)

// Keep the order summary synchronized with what the customer can currently see.
// This prevents a stale token package from remaining selected after switching to Credits.
watch([filteredPackages, familyFilter, modeFilter], ([visible]) => {
  const currentStillVisible = visible.some(item => item.slug === selectedSlug.value && !isSoldOut(item))
  if (currentStillVisible) return

  selectedSlug.value = (visible.find(item => !isSoldOut(item)) ?? visible[0])?.slug ?? null
}, { immediate: true })

const select = (item: PublicPackage) => {
  if (isSoldOut(item)) return
  selectedSlug.value = item.slug
  promotion.value = null
  promoError.value = null
}

/** ------------------------------------------------------------ promotion */

const promoCode = ref('')
const promotion = ref<PromotionPreview | null>(null)
const promoError = ref<string | null>(null)
const previewing = ref(false)

const applyPromotion = async () => {
  const code = promoCode.value.trim()
  const item = selected.value

  if (!code || !item) {
    return
  }

  previewing.value = true
  promoError.value = null
  promotion.value = null

  try {
    // Every discount is calculated by the backend. The browser never derives a total.
    promotion.value = await api.orders.previewPromotion({
      package_slug: item.slug,
      quantity: quantity.value,
      promotion_code: code
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    promoError.value = error.isUnavailable
      ? 'Promotion codes cannot be checked yet — the endpoint is not published.'
      : error.message
  } finally {
    previewing.value = false
  }
}

const clearPromotion = () => {
  promoCode.value = ''
  promotion.value = null
  promoError.value = null
}

/** Re-checking is required whenever the priced inputs change. */
watch([selectedSlug, quantity], () => {
  if (promotion.value) {
    promotion.value = null
    promoError.value = 'Quantity or package changed. Check the code again.'
  }
})

/** ---------------------------------------------------------------- order */

const placing = ref(false)
const orderError = ref<string | null>(null)
const orderKey = ref<string | null>(null)

const mintOrderKey = () => {
  try {
    orderKey.value = newIdempotencyKey('order')
  } catch {
    orderKey.value = null
    orderError.value = 'This browser cannot generate the safety key that stops an order being created twice. Open SP Cambo over HTTPS and try again.'
  }
}

// A changed package, quantity, or accepted promotion is a new purchase attempt.
watch([selectedSlug, quantity, () => promotion.value?.valid ? promotion.value.code : null], mintOrderKey, { immediate: true })

const placeOrder = async () => {
  const item = selected.value
  const key = orderKey.value

  if (!item) {
    return
  }
  if (!key) {
    mintOrderKey()

    return
  }

  placing.value = true
  orderError.value = null

  try {
    const order = await api.orders.create({
      package_slug: item.slug,
      quantity: quantity.value,
      promotion_code: promotion.value?.valid ? promotion.value.code : undefined,
      idempotency_key: key
    })

    // Keep the submit guard through navigation as additional double-tap protection.
    await router.push(`/dashboard/checkout/${order.id}`)
  } catch (cause) {
    const error = toSpApiError(cause)

    orderError.value = error.isUnavailable
      ? 'Ordering is not available yet — the control plane has not published the orders endpoint.'
      : error.message

    toast.add({
      title: 'Order not created',
      description: orderError.value,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })

    // An input conflict is definitive; a fresh key permits a corrected attempt.
    // Every other failure retains the key because a lost response may hide success.
    if (error.code === 'idempotency_conflict') {
      mintOrderKey()
    }

    placing.value = false
  }
}

const customerLabel = (value: string | null | undefined) => (value ?? '')
  .replaceAll('SP Tokens', 'Tokens')
  .replaceAll('SP Credits', 'Credits')
  .replaceAll('SP billable tokens', 'Tokens')
  .replaceAll('SP billable units', 'Tokens')

const packageGrantLabel = (item: PublicPackage): string => {
  if (item.display_units && item.display_unit_label) {
    const label = customerLabel(item.display_unit_label)
    return label === 'Credits' ? `$${formatUnits(item.display_units)} Credits` : `${formatUnits(item.display_units)} ${label}`
  }

  if (item.billing_mode === 'CREDIT_BALANCE' && item.credit_amount) {
    return `${formatMoney(item.credit_amount)} credit`
  }

  return `${formatUnits(item.advertised_units)} ${customerLabel(item.unit_label)}`
}

const quantityOptions = [1, 2, 3, 5, 10].map(value => ({ label: `${value}×`, value }))
</script>

<template>
  <SpDashboardPage
    title="Buy tokens & credits"
    icon="i-lucide-package"
    description="Prepaid packages. Nothing renews, nothing is stored for a future charge, and a spent package stops requests instead of billing you more."
  >
    <SpAsyncSection
      :loading="packages.initialLoading.value"
      :unavailable="packages.unavailable.value"
      :failed="packages.failed.value"
      :empty="packages.isEmpty.value"
      :offline="packages.error.value?.code === 'network_unreachable'"
      :error-message="packages.error.value?.message"
      unavailable-title="The package catalogue is not published yet"
      unavailable-description="SP Cambo publishes packages, prices and lifetimes from the control plane. Until that endpoint is live there is nothing to buy here — and we do not display example prices."
      empty-title="No packages on sale"
      empty-description="Nothing is currently published for your account."
      empty-icon="i-lucide-package"
      loading-variant="cards"
      :loading-count="3"
      @retry="packages.refresh()"
    >
      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <section class="space-y-4">
          <SpSectionHeading
            title="Choose a package"
            description="Choose your model family first, then pick tokens or credits. Sold-out packages stay visible so you can compare prices."
          />

          <div class="space-y-3 rounded-2xl border border-default bg-elevated/25 p-3 sm:p-4">
            <div class="flex flex-wrap gap-2">
              <button
                v-for="family in familyFilters"
                :key="family.value"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition"
                :class="familyFilter === family.value ? 'border-primary/50 bg-primary/10 text-highlighted' : 'border-default bg-default/35 text-muted hover:border-primary/30 hover:text-default'"
                @click="familyFilter = family.value"
              >
                <SpModelLogo v-if="family.value !== 'all'" :model="family.label" :label="family.label" size="xs" />
                <UIcon v-else name="i-lucide-layout-grid" class="size-4" />
                <span>{{ family.label }}</span>
              </button>
            </div>
            <div class="flex flex-wrap gap-2 border-t border-default pt-3">
              <UButton size="xs" :color="modeFilter === 'all' ? 'primary' : 'neutral'" :variant="modeFilter === 'all' ? 'soft' : 'ghost'" icon="i-lucide-layers-3" @click="modeFilter = 'all'">All packages</UButton>
              <UButton size="xs" :color="modeFilter === 'SP_TOKENS' ? 'primary' : 'neutral'" :variant="modeFilter === 'SP_TOKENS' ? 'soft' : 'ghost'" icon="i-lucide-gauge" @click="modeFilter = 'SP_TOKENS'">Tokens</UButton>
              <UButton size="xs" :color="modeFilter === 'SP_CREDITS' ? 'primary' : 'neutral'" :variant="modeFilter === 'SP_CREDITS' ? 'soft' : 'ghost'" icon="i-lucide-wallet-cards" @click="modeFilter = 'SP_CREDITS'">Credits</UButton>
              <span class="ms-auto self-center text-xs text-muted">{{ filteredPackages.length }} shown</span>
            </div>
          </div>
          <div class="rounded-xl border border-success/20 bg-success/5 p-4">
            <div class="flex items-start gap-3">
              <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success/10 text-success">
                <UIcon name="i-lucide-sparkles" class="size-4" />
              </div>
              <div>
                <p class="text-sm font-semibold text-highlighted">Smart reuse included with every package</p>
                <p class="mt-1 text-xs leading-5 text-muted">Repeated prompt context can be recognized automatically and charged at 25% of the normal Token rate, helping long chats, coding sessions and agent workflows cost less while keeping pricing predictable.</p>
                <p class="mt-1 text-[11px] leading-4 text-dimmed">New input/output is metered normally. Larger bundles have a lower effective purchase price. $1 Credit = 100,000 billable Tokens. Credits are platform usage credits, not withdrawable cash.</p>
              </div>
            </div>
          </div>

          <p v-if="filteredPackages.length === 0" class="rounded-xl border border-dashed border-default px-5 py-10 text-center text-sm text-muted">
            No packages match this model and package type.
          </p>

          <ul
            v-else
            class="grid gap-3 sm:grid-cols-2"
            role="radiogroup"
            aria-label="Packages"
          >
            <li
              v-for="item in filteredPackages"
              :key="item.slug"
            >
              <button
                type="button"
                role="radio"
                :aria-checked="item.slug === selectedSlug"
                class="sp-catalog-option w-full rounded-2xl border p-4 text-left transition"
                :class="[
                  item.slug === selectedSlug ? 'border-primary bg-primary/5 ring-1 ring-primary/40' : 'border-default bg-elevated/30 hover:border-accented',
                  isSoldOut(item) ? 'cursor-not-allowed opacity-55' : 'hover:-translate-y-0.5'
                ]"
                :disabled="isSoldOut(item)"
                @click="select(item)"
              >
                <div class="flex items-start justify-between gap-2">
                  <div class="flex min-w-0 items-start gap-3">
                    <SpModelLogo :model="primaryModel(item)" :label="item.family_label" size="md" />
                    <div class="min-w-0">
                      <p class="truncate font-semibold text-highlighted">{{ customerLabel(item.name) }}</p>
                      <p v-if="item.subtitle" class="truncate text-xs text-muted">{{ customerLabel(item.subtitle) }}</p>
                      <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <UBadge :color="isSoldOut(item) ? 'error' : 'success'" variant="subtle" size="xs">{{ stockLabel(item) }}</UBadge>
                        <UBadge v-if="item.badge" color="primary" variant="subtle" size="xs">{{ item.badge }}</UBadge>
                      </div>
                    </div>
                  </div>
                </div>

                <p class="sp-numeric mt-3 text-xl font-semibold text-highlighted">
                  {{ formatMoney(item.price) }}
                  <span
                    v-if="item.compare_at_price"
                    class="ms-2 text-sm font-normal text-dimmed line-through"
                  >{{ formatMoney(item.compare_at_price) }}</span>
                </p>

                <dl class="mt-3 space-y-1 text-xs text-muted">
                  <div class="flex justify-between gap-2">
                    <dt>Quantity</dt>
                    <dd class="sp-numeric text-default">
                      {{ packageGrantLabel(item) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-2">
                    <dt>Lifetime</dt>
                    <dd class="text-default">
                      {{ formatDurationSeconds(item.duration_seconds) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-2">
                    <dt>Model family</dt>
                    <dd class="text-default">{{ item.family_label }}</dd>
                  </div>
                </dl>

                <div v-if="item.allowed_model_aliases.length" class="mt-3 flex flex-wrap gap-1.5">
                  <SpModelBadge v-for="alias in item.allowed_model_aliases.slice(0, 3)" :key="alias" :model="alias" compact />
                  <UBadge v-if="item.allowed_model_aliases.length > 3" color="neutral" variant="outline" size="xs">+{{ item.allowed_model_aliases.length - 3 }}</UBadge>
                </div>
              </button>
            </li>
          </ul>
        </section>

        <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
          <div class="sp-buy-package-card space-y-4 rounded-lg border border-default bg-elevated/40 p-5">
            <h2 class="font-medium text-highlighted">
              Order summary
            </h2>

            <template v-if="selected">
              <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Package
                  </dt>
                  <dd class="text-right text-default">
                    {{ customerLabel(selected.name) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Unit price
                  </dt>
                  <dd class="sp-numeric text-default">
                    {{ formatMoney(selected.price) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Includes
                  </dt>
                  <dd class="sp-numeric text-right text-default">
                    {{ packageGrantLabel(selected) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-3">
                  <dt class="text-muted">
                    Valid for
                  </dt>
                  <dd class="text-right text-default">
                    {{ formatDurationSeconds(selected.duration_seconds) }}
                  </dd>
                </div>
              </dl>

              <UFormField
                label="Quantity"
                help="Each unit is a separate entitlement lot with its own expiry."
              >
                <USelectMenu
                  v-model="quantity"
                  :items="quantityOptions"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>

              <UFormField
                label="Promotion code"
                :error="promoError ?? undefined"
              >
                <div class="flex gap-2">
                  <UInput
                    v-model="promoCode"
                    placeholder="Optional"
                    autocapitalize="characters"
                    class="min-w-0 flex-1"
                    @keydown.enter.prevent="applyPromotion"
                  />
                  <UButton
                    color="neutral"
                    variant="subtle"
                    :loading="previewing"
                    :disabled="!promoCode.trim()"
                    @click="applyPromotion"
                  >
                    Check
                  </UButton>
                </div>
              </UFormField>

              <div
                v-if="promotion"
                class="space-y-2 rounded-md border p-3 text-sm"
                :class="promotion.valid ? 'border-success/40 bg-success/5' : 'border-default bg-elevated/40'"
              >
                <div class="flex items-center gap-2">
                  <UIcon
                    :name="promotion.valid ? 'i-lucide-circle-check' : 'i-lucide-circle-x'"
                    class="size-4"
                    :class="promotion.valid ? 'text-success' : 'text-dimmed'"
                  />
                  <p class="font-medium text-highlighted">
                    {{ promotion.label || promotion.code }}
                  </p>
                </div>

                <p
                  v-if="!promotion.valid"
                  class="text-muted"
                >
                  {{ promotion.reason ?? 'This code cannot be applied to this order.' }}
                </p>

                <dl
                  v-else
                  class="space-y-1"
                >
                  <div class="flex justify-between gap-3">
                    <dt class="text-muted">
                      Subtotal
                    </dt>
                    <dd class="sp-numeric text-default">
                      {{ formatMoney(promotion.subtotal) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-muted">
                      Discount
                    </dt>
                    <dd class="sp-numeric text-success">
                      −{{ formatMoney(promotion.discount_total) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3 border-t border-default pt-1">
                    <dt class="font-medium text-highlighted">
                      Total
                    </dt>
                    <dd class="sp-numeric font-medium text-highlighted">
                      {{ formatMoney(promotion.total) }}
                    </dd>
                  </div>
                  <div
                    v-if="promotion.bonus_units"
                    class="flex justify-between gap-3"
                  >
                    <dt class="text-muted">
                      Bonus
                    </dt>
                    <dd class="sp-numeric text-success">
                      +{{ formatUnits(promotion.bonus_units) }}
                    </dd>
                  </div>
                </dl>

                <UButton
                  color="neutral"
                  variant="link"
                  size="xs"
                  class="px-0"
                  @click="clearPromotion"
                >
                  Remove code
                </UButton>
              </div>

              <p
                v-else
                class="text-xs text-muted"
              >
                SP Cambo calculates the payable total when the order is created. The exact amount is shown
                on the payment screen before you pay anything.
              </p>

              <UAlert
                v-if="orderError"
                role="alert"
                icon="i-lucide-circle-alert"
                color="error"
                variant="subtle"
                :description="orderError"
              />

              <UButton
                block
                size="lg"
                :loading="placing"
                :disabled="selected ? isSoldOut(selected) : true"
                icon="i-lucide-qr-code"
                @click="placeOrder"
              >
                Continue to payment
              </UButton>

              <p class="text-xs text-dimmed">
                You will be shown a Bakong KHQR code with the exact amount and a real expiry.
                {{ selected.auto_creates_api_key
                  ? 'After verified payment, the purchased models become available in Playground and SP Cambo prepares or updates your default API key automatically.'
                  : 'After verified payment, the purchased models become available in Playground immediately. You can create a scoped API key later if you need external API/CLI access.' }}
              </p>
            </template>

            <p
              v-else
              class="text-sm text-muted"
            >
              Select a package to see the summary.
            </p>
          </div>

          <div class="sp-dashboard-section space-y-2 rounded-lg border border-default p-4 text-xs text-muted">
            <p class="font-medium text-default">
              How consumption works
            </p>
            <p>
              Holding more than one package spends the soonest-expiring one first, so nothing is wasted.
              Quantity left in a package when its lifetime ends is forfeited.
            </p>
            <NuxtLink
              to="/docs/billing"
              class="inline-flex items-center gap-1 text-primary underline decoration-dotted underline-offset-4"
            >
              Billing model
            </NuxtLink>
          </div>
        </aside>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
