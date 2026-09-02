<script setup lang="ts">
import type { Order, PaymentAttempt } from '~/types/commerce'

definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Payment',
  description: 'Pay this order with Bakong KHQR. SP Cambo confirms the payment server-side.',
  robots: 'noindex'
})

const api = useSpApi()
const route = useRoute()
const toast = useToast()

const orderId = computed(() => String(route.params.order ?? ''))

const order = ref<Order | null>(null)
const orderError = ref<SpApiError | null>(null)
const loadingOrder = ref(true)

const payment = ref<PaymentAttempt | null>(null)
const paymentError = ref<SpApiError | null>(null)
const loadingPayment = ref(true)

type PurchaseClaim = {
  id: string
  order_id: string
  status: 'PENDING' | 'CLAIMED' | 'EXPIRED' | string
  package_name: string
  allowed_model_aliases: string[]
  api_key_id: string | null
  masked_key: string | null
  delivery_mode: 'PLAYGROUND' | 'NEW' | 'EXISTING' | null
}

const claims = ref<PurchaseClaim[]>([])
const claimError = ref<SpApiError | null>(null)
const currentClaim = computed(() => claims.value.find(claim => claim.order_id === orderId.value) ?? null)
const pendingClaim = computed(() => currentClaim.value?.status === 'PENDING' ? currentClaim.value : null)
const claimedAccess = computed(() => currentClaim.value?.status === 'CLAIMED' ? currentClaim.value : null)
const purchasedModel = computed(() => currentClaim.value?.allowed_model_aliases?.[0] ?? order.value?.items[0]?.package_slug ?? '')

const loadClaims = async () => {
  try {
    claims.value = await api.request<PurchaseClaim[]>('/me/api-key-claims', { collection: true })
    claimError.value = null
  } catch (cause) {
    // Allocation is required for new model-scoped purchases, so never hide this
    // failure behind a successful payment screen. The paid entitlement remains
    // safe/UNASSIGNED and can be retried without double granting it.
    claimError.value = toSpApiError(cause)
  }
}

/**
 * Difference between this device's clock and the control plane's.
 *
 * The countdown must follow the server, not the browser: a device with a wrong
 * clock would otherwise show a QR as live after it expired, or expired while it
 * is still payable. The arithmetic lives in `~/utils/paymentState` so it can be
 * tested without a browser.
 */
const skewMs = ref(0)
const now = ref(Date.now())

const remaining = computed(() => remainingMs({
  expiresAt: payment.value?.expires_at,
  deviceNowMs: now.value,
  skewMs: skewMs.value
}))

const outcome = computed(() => paymentOutcome({
  attemptStatus: payment.value?.status ?? null,
  orderStatus: order.value?.status ?? null,
  countdownExpired: remaining.value === 0
}))

const isPaid = computed(() => outcome.value === 'paid')
const isExpired = computed(() => outcome.value === 'expired')
const isFailed = computed(() => outcome.value === 'failed')
const isWaiting = computed(() => isAwaitingPayment(outcome.value))

/** Fulfilment lands after payment: the entitlement is live only once it does. */
const isFulfilled = computed(() => order.value?.status === 'FULFILLED')

/** --------------------------------------------------------------- loading */

const loadOrder = async () => {
  loadingOrder.value = true

  try {
    order.value = await api.orders.get(orderId.value)
    orderError.value = null
  } catch (cause) {
    orderError.value = toSpApiError(cause)
  } finally {
    loadingOrder.value = false
  }
}

const applyAttempt = (attempt: PaymentAttempt) => {
  payment.value = attempt
  skewMs.value = clockSkewMs(attempt.server_time, Date.now())
  now.value = Date.now()
}

/**
 * Reads the existing attempt, and only creates one when the order genuinely has
 * none. Creation is never retried automatically — a payment attempt is not
 * something to spam.
 */
const loadPayment = async (options: { allowCreate?: boolean } = {}) => {
  loadingPayment.value = true

  try {
    applyAttempt(await api.orders.paymentStatus(orderId.value))
    paymentError.value = null
  } catch (cause) {
    const error = toSpApiError(cause)

    if (error.code === 'not_found' && options.allowCreate !== false) {
      try {
        applyAttempt(await api.orders.createPayment(orderId.value))
        paymentError.value = null
      } catch (createCause) {
        paymentError.value = toSpApiError(createCause)
      }
    } else {
      paymentError.value = error
    }
  } finally {
    loadingPayment.value = false
  }
}

const newCode = ref(false)

const requestNewCode = async () => {
  newCode.value = true

  try {
    applyAttempt(await api.orders.createPayment(orderId.value))
    paymentError.value = null
    schedulePoll(true)
  } catch (cause) {
    const error = toSpApiError(cause)
    toast.add({
      title: 'A new code could not be issued',
      description: error.message,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })
  } finally {
    newCode.value = false
  }
}

/** ---------------------------------------------------- verification poll */

let pollTimer: ReturnType<typeof setTimeout> | undefined
let tickTimer: ReturnType<typeof setInterval> | undefined
let pollStartedAt = 0

/** Backs off so a long wait does not hammer the control plane. */
const pollDelay = () => pollDelayMs(Date.now() - pollStartedAt)

const poll = async () => {
  if (!isWaiting.value) {
    return
  }

  try {
    // Ask the control plane to auto-check this attempt. The backend owns the real
    // Bakong rate limit/lease, so many browser polls or multiple tabs still cause
    // at most one external verification per configured interval for this QR.
    applyAttempt(await api.orders.autoCheckPayment(orderId.value))
    await loadOrder()

    if (order.value?.status === 'FULFILLED') {
      await loadClaims()
    }
  } catch {
    // If Bakong is temporarily unavailable, keep the page useful by reading the
    // stored state. A later automatic poll or the explicit button can retry.
    try {
      applyAttempt(await api.orders.paymentStatus(orderId.value))
      await loadOrder()
    } catch {
      // The next poll may recover; avoid replacing a valid QR with a transient error.
    }
  }

  schedulePoll()
}

function schedulePoll(restart = false) {
  clearTimeout(pollTimer)

  if (restart) {
    pollStartedAt = Date.now()
  }

  if (!isWaiting.value) {
    return
  }

  pollTimer = setTimeout(poll, pollDelay())
}

const verifying = ref(false)

/**
 * Asks the backend to re-check Bakong now. This is a request for a check, never
 * an assertion that money arrived — only the backend can decide that.
 */
const iHavePaid = async () => {
  verifying.value = true

  try {
    applyAttempt(await api.orders.requestVerification(orderId.value))
    await loadOrder()

    if (!isPaid.value) {
      const stillVerifying = payment.value?.status === 'VERIFYING'
      toast.add({
        title: stillVerifying ? 'Verification already in progress' : 'Payment not found yet',
        description: stillVerifying
          ? 'SP Cambo is already checking this KHQR. The page will update when the current check finishes.'
          : 'Bakong has not returned a matching completed transfer yet. If you just paid, wait a few seconds and check again.',
        icon: stillVerifying ? 'i-lucide-loader-circle' : 'i-lucide-clock-3',
        color: 'info'
      })
    }

    schedulePoll(true)
  } catch (cause) {
    const error = toSpApiError(cause)
    toast.add({
      title: 'Could not check the payment',
      description: error.message,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })
  } finally {
    verifying.value = false
  }
}

/** ------------------------------------------------------------ lifecycle */

onMounted(async () => {
  await loadOrder()
  await loadPayment()
  if (order.value?.status === 'FULFILLED') await loadClaims()

  pollStartedAt = Date.now()
  tickTimer = setInterval(() => {
    now.value = Date.now()
  }, 1000)

  schedulePoll()
})

onBeforeUnmount(() => {
  clearTimeout(pollTimer)
  clearInterval(tickTimer)
})

/** Stop polling as soon as the outcome is decided. */
watch(isWaiting, (waiting) => {
  if (!waiting) {
    clearTimeout(pollTimer)
  }
})

watch(isFulfilled, async (fulfilled) => {
  if (fulfilled) {
    await loadClaims()
    toast.add({
      title: 'Package activated',
      description: 'Your entitlement is live and ready to use.',
      icon: 'i-lucide-circle-check',
      color: 'success'
    })
  }
})

let expiryRecoveryStarted = false
watch(remaining, async (value, previous) => {
  if (expiryRecoveryStarted || value !== 0 || previous === null || previous === undefined || previous <= 0 || isPaid.value) return
  expiryRecoveryStarted = true
  try {
    applyAttempt(await api.orders.requestVerification(orderId.value))
    await loadOrder()
    if (order.value?.status === 'FULFILLED') await loadClaims()
  } catch {
    // The normal expired UI remains available. A manual re-check is still safe.
  }
})

const unavailable = computed(() =>
  (orderError.value?.isUnavailable ?? false) || (paymentError.value?.isUnavailable ?? false)
)

const countdownTone = computed(() => countdownToneClass(remaining.value))
</script>

<template>
  <SpDashboardPage
    title="Payment"
    icon="i-lucide-qr-code"
  >
    <template #actions>
      <UButton
        to="/dashboard/orders"
        color="neutral"
        variant="ghost"
        icon="i-lucide-receipt"
      >
        All orders
      </UButton>
    </template>

    <SpAsyncSection
      :loading="loadingOrder && order === null"
      :unavailable="unavailable"
      :failed="orderError !== null && !unavailable"
      :offline="orderError?.code === 'network_unreachable'"
      :error-message="orderError?.message"
      unavailable-title="Checkout is not available yet"
      unavailable-description="The control plane has not published the order and payment endpoints. No money has moved and no order exists."
      loading-variant="cards"
      :loading-count="2"
      @retry="loadOrder()"
    >
      <div
        v-if="order"
        class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"
      >
        <!-- Payment -->
        <section class="space-y-4">
          <div class="sp-checkout-card rounded-lg border border-default bg-elevated/30 p-6">
            <!-- Paid -->
            <div
              v-if="isPaid"
              class="space-y-4 text-center"
            >
              <UIcon
                :name="isFulfilled ? 'i-lucide-circle-check' : 'i-lucide-loader-circle'"
                class="mx-auto size-10"
                :class="isFulfilled ? 'text-success' : 'animate-spin text-primary'"
              />
              <div class="space-y-1">
                <h2 class="text-lg font-medium text-highlighted">
                  {{ isFulfilled ? 'Payment confirmed and package activated' : 'Payment confirmed' }}
                </h2>
                <p class="text-sm text-muted">
                  {{ isFulfilled
                    ? (pendingClaim ? 'Your purchased balance is secured. Choose where to allocate it before using it.' : 'Your selected access target is ready.')
                    : 'SP Cambo is activating your entitlement. This page updates itself — you do not need to pay again.' }}
                </p>
              </div>
              <UAlert
                v-if="isFulfilled && claimedAccess?.delivery_mode === 'PLAYGROUND'"
                color="success"
                variant="subtle"
                icon="i-lucide-flask-conical"
                title="Added to Playground balance"
                description="This purchase is isolated to Playground. Normal API keys cannot spend it."
              />

              <UAlert
                v-else-if="isFulfilled && claimedAccess?.api_key_id"
                color="success"
                variant="subtle"
                icon="i-lucide-key-round"
                title="API-key balance ready"
                :description="`This purchase is allocated to ${claimedAccess.masked_key ?? 'your selected API key'} and is not mixed into Playground or your other dedicated keys.`"
              />

              <UAlert
                v-if="isFulfilled && claimError"
                color="error"
                variant="subtle"
                icon="i-lucide-circle-alert"
                title="Purchase is safe, but access choices could not be loaded"
                :description="`${claimError.message} Your paid balance has not been assigned twice. Retry loading the access choices.`"
              >
                <template #actions>
                  <UButton size="xs" color="neutral" variant="soft" icon="i-lucide-refresh-cw" @click="loadClaims">Retry access choices</UButton>
                </template>
              </UAlert>

              <UAlert
                v-else-if="isFulfilled && pendingClaim"
                color="info"
                variant="subtle"
                icon="i-lucide-route"
                title="Choose where to use this purchase"
                description="Your payment is fulfilled and the purchased balance is reserved for you. Choose Playground, create a separate API key, or add it to one existing key. Nothing is merged automatically."
              />

              <div class="flex flex-wrap justify-center gap-2">
                <UButton
                  v-if="pendingClaim"
                  :to="`/dashboard/claim-key?claim=${pendingClaim.id}`"
                  icon="i-lucide-route"
                >
                  Choose access
                </UButton>
                <UButton
                  v-if="claimedAccess?.delivery_mode === 'PLAYGROUND'"
                  to="/dashboard/playground"
                  icon="i-lucide-flask-conical"
                >
                  Open Playground
                </UButton>
                <UButton
                  v-if="claimedAccess?.api_key_id"
                  :to="`/dashboard/api-keys/${claimedAccess.api_key_id}`"
                  icon="i-lucide-key-round"
                >
                  View / copy API key
                </UButton>
                <UButton
                  to="/dashboard/entitlements"
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-hourglass"
                >
                  View entitlements
                </UButton>
                <UButton
                  v-if="claimedAccess?.api_key_id"
                  :to="purchasedModel ? `/dashboard/cli-setup?model=${encodeURIComponent(purchasedModel)}` : '/dashboard/cli-setup'"
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-terminal"
                >
                  CLI setup
                </UButton>
              </div>
            </div>

            <!-- Expired -->
            <div
              v-else-if="isExpired"
              class="space-y-4 text-center"
            >
              <UIcon
                name="i-lucide-timer-off"
                class="mx-auto size-10 text-warning"
              />
              <div class="space-y-1">
                <h2 class="text-lg font-medium text-highlighted">
                  This payment code expired
                </h2>
                <p class="text-sm text-muted">
                  SP Cambo has not confirmed this code yet. If you already paid, do not pay again — re-check the
                  payment first. Only request a new code when you are sure the expired code was not paid.
                </p>
              </div>
              <div class="flex flex-wrap justify-center gap-2">
                <UButton
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-search"
                  :loading="verifying"
                  @click="iHavePaid"
                >
                  Re-check payment
                </UButton>
                <UButton
                  icon="i-lucide-refresh-cw"
                  :loading="newCode"
                  @click="requestNewCode"
                >
                  Get a new code
                </UButton>
                <UButton
                  to="/dashboard/buy"
                  color="neutral"
                  variant="subtle"
                >
                  Back to packages
                </UButton>
              </div>
            </div>

            <!-- Failed -->
            <div
              v-else-if="isFailed"
              class="space-y-4 text-center"
            >
              <UIcon
                name="i-lucide-circle-x"
                class="mx-auto size-10 text-error"
              />
              <div class="space-y-1">
                <h2 class="text-lg font-medium text-highlighted">
                  This order did not complete
                </h2>
                <p class="text-sm text-muted">
                  If you believe you paid, do not pay again. Ask SP Cambo to re-check first — verification is
                  idempotent and cannot credit you twice.
                </p>
              </div>
              <div class="flex flex-wrap justify-center gap-2">
                <UButton
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-search"
                  :loading="verifying"
                  @click="iHavePaid"
                >
                  Re-check payment
                </UButton>
                <UButton
                  to="/dashboard/buy"
                  color="neutral"
                  variant="ghost"
                >
                  Back to packages
                </UButton>
              </div>
            </div>

            <!-- Awaiting payment -->
            <div
              v-else
              class="space-y-5"
            >
              <SpStateLoading
                v-if="loadingPayment && !payment"
                variant="cards"
                :count="1"
              />

              <SpStateError
                v-else-if="paymentError && !unavailable"
                title="The payment code could not be loaded"
                :description="paymentError.message"
                @retry="loadPayment()"
              />

              <template v-else-if="payment">
                <div class="flex flex-col items-center gap-1 text-center">
                  <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
                    Scan with any Bakong-enabled app
                  </p>
                  <p class="sp-numeric text-2xl font-semibold text-highlighted">
                    {{ formatMoney(payment.amount) }}
                  </p>
                  <p class="text-sm text-muted">
                    {{ payment.merchant_display_name }}
                  </p>
                </div>

                <SpKhqrCode
                  :payload="payment.qr_payload"
                  :image-url="payment.qr_image_url"
                />

                <div class="flex flex-col items-center gap-1">
                  <p
                    class="sp-numeric text-lg font-medium"
                    :class="countdownTone"
                    role="timer"
                    aria-live="off"
                  >
                    {{ remaining === null ? '—' : formatClock(remaining) }}
                  </p>
                  <p class="text-xs text-dimmed">
                    Expires {{ formatExactTimestamp(payment.expires_at) }} · timed by SP Cambo, not your device
                  </p>
                </div>

                <div class="space-y-2">
                  <UButton
                    block
                    size="lg"
                    class="sp-payment-check-button"
                    icon="i-lucide-search"
                    :loading="verifying"
                    @click="iHavePaid"
                  >
                    I have paid — check now
                  </UButton>
                  <p class="text-center text-xs text-muted">
                    SP Cambo auto-checks Bakong while this page is open and the backend scheduler continues in the background.
                    Use this button only when you want an immediate extra re-check after paying.
                  </p>
                </div>

                <div class="flex items-center justify-center gap-2 text-xs text-dimmed">
                  <UIcon
                    name="i-lucide-radio"
                    class="size-3.5 animate-pulse"
                  />
                  Auto-checking Bakong for payment updates
                  <span v-if="payment.last_checked_at">· last checked {{ formatDateTime(payment.last_checked_at) }}</span>
                </div>
              </template>
            </div>
          </div>

          <div class="rounded-lg border border-default p-4 text-xs text-muted">
            <p class="font-medium text-default">
              Why the QR is timed
            </p>
            <p class="mt-1">
              A KHQR carries a fixed amount and a real expiry. The countdown above is driven by the SP Cambo
              server clock, so a wrong clock on this device cannot mislead you. Fulfilment happens on the
              backend after verification and credits your account exactly once, however many times it is
              re-checked.
            </p>
          </div>
        </section>

        <!-- Order -->
        <aside class="space-y-4">
          <div class="space-y-4 rounded-lg border border-default bg-elevated/40 p-5">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <h2 class="font-medium text-highlighted">
                  Order
                </h2>
                <p class="truncate font-mono text-xs text-muted">
                  {{ order.reference }}
                </p>
              </div>
              <SpStatusBadge :status="order.status.toLowerCase()" />
            </div>

            <ul class="space-y-3 border-t border-default pt-3 text-sm">
              <li
                v-for="item in order.items"
                :key="item.package_slug"
                class="flex justify-between gap-3"
              >
                <div class="min-w-0">
                  <p class="truncate text-default">
                    {{ item.package_name }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ item.quantity }} × {{ formatMoney(item.unit_price) }}
                  </p>
                </div>
                <p class="sp-numeric shrink-0 text-default">
                  {{ formatMoney(item.line_total) }}
                </p>
              </li>
            </ul>

            <dl class="space-y-1.5 border-t border-default pt-3 text-sm">
              <div class="flex justify-between gap-3">
                <dt class="text-muted">
                  Subtotal
                </dt>
                <dd class="sp-numeric text-default">
                  {{ formatMoney(order.subtotal) }}
                </dd>
              </div>
              <div
                v-if="!isZeroMoney(order.discount_total)"
                class="flex justify-between gap-3"
              >
                <dt class="text-muted">
                  Discount
                  <span
                    v-if="order.applied_promotion"
                    class="text-dimmed"
                  >({{ order.applied_promotion.code }})</span>
                </dt>
                <dd class="sp-numeric text-success">
                  −{{ formatMoney(order.discount_total) }}
                </dd>
              </div>
              <div class="flex justify-between gap-3 border-t border-default pt-1.5">
                <dt class="font-medium text-highlighted">
                  Total
                </dt>
                <dd class="sp-numeric font-medium text-highlighted">
                  {{ formatMoney(order.total) }}
                </dd>
              </div>
            </dl>

            <dl class="space-y-1 border-t border-default pt-3 text-xs text-muted">
              <div class="flex justify-between gap-3">
                <dt>Created</dt>
                <dd>{{ formatDateTime(order.created_at) }}</dd>
              </div>
              <div
                v-if="order.fulfilled_at"
                class="flex justify-between gap-3"
              >
                <dt>Fulfilled</dt>
                <dd>{{ formatDateTime(order.fulfilled_at) }}</dd>
              </div>
            </dl>
          </div>

          <div class="rounded-lg border border-default p-4 text-xs text-muted">
            <p class="font-medium text-default">
              Leaving this page is safe
            </p>
            <p class="mt-1">
              The order stays open until it is paid or expires. You can return to it from
              <NuxtLink
                to="/dashboard/orders"
                class="text-primary underline decoration-dotted underline-offset-4"
              >
                orders
              </NuxtLink>.
            </p>
          </div>
        </aside>
      </div>
    </SpAsyncSection>
  </SpDashboardPage>
</template>
