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

/** `?package=<slug>` deep-links from the public pricing page. */
watch([() => route.query.package, packages.data], ([slug, list]) => {
  if (!list) {
    return
  }

  const requested = typeof slug === 'string' ? list.find(item => item.slug === slug) : undefined

  if (requested) {
    selectedSlug.value = requested.slug
  } else if (!selectedSlug.value) {
    selectedSlug.value = (list.find(item => item.featured) ?? list[0])?.slug ?? null
  }
}, { immediate: true })

const sorted = computed(() => [...(packages.data.value ?? [])].sort((a, b) => a.sort_order - b.sort_order))

const selected = computed<PublicPackage | null>(() =>
  sorted.value.find(item => item.slug === selectedSlug.value) ?? null
)

const select = (item: PublicPackage) => {
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

const packageGrantLabel = (item: PublicPackage): string => {
  if (item.billing_mode === 'CREDIT_BALANCE' && item.credit_amount) {
    return `${formatMoney(item.credit_amount)} credit`
  }

  return `${formatUnits(item.advertised_units)} ${item.unit_label}`
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
            description="Every figure below is published by SP Cambo. Lifetimes run in exact seconds from the moment payment is confirmed."
          />

          <ul
            class="grid gap-3 sm:grid-cols-2"
            role="radiogroup"
            aria-label="Packages"
          >
            <li
              v-for="item in sorted"
              :key="item.slug"
            >
              <button
                type="button"
                role="radio"
                :aria-checked="item.slug === selectedSlug"
                class="sp-catalog-option w-full rounded-lg border p-4 text-left transition-colors"
                :class="item.slug === selectedSlug
                  ? 'border-primary bg-primary/5 ring-1 ring-primary/40'
                  : 'border-default bg-elevated/30 hover:border-accented'"
                @click="select(item)"
              >
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="truncate font-medium text-highlighted">
                      {{ item.name }}
                    </p>
                    <p
                      v-if="item.subtitle"
                      class="truncate text-xs text-muted"
                    >
                      {{ item.subtitle }}
                    </p>
                  </div>
                  <UBadge
                    v-if="item.badge"
                    color="primary"
                    variant="subtle"
                    size="sm"
                  >
                    {{ item.badge }}
                  </UBadge>
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
                    <dt>Family</dt>
                    <dd class="text-default">
                      {{ item.family_label }}
                    </dd>
                  </div>
                </dl>
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
                    {{ selected.name }}
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
