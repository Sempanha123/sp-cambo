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
}

const claims = ref<PurchaseClaim[]>([])
const currentClaim = computed(() => claims.value.find(claim => claim.order_id === orderId.value) ?? null)
const pendingClaim = computed(() => currentClaim.value?.status === 'PENDING' ? currentClaim.value : null)
const claimedAccess = computed(() => currentClaim.value?.status === 'CLAIMED' ? currentClaim.value : null)
const purchasedModel = computed(() => currentClaim.value?.allowed_model_aliases?.[0] ?? order.value?.items[0]?.package_slug ?? '')

const loadClaims = async () => {
  try {
    claims.value = await api.request<PurchaseClaim[]>('/me/api-key-claims', { collection: true })
  } catch {
    // The entitlement is already valid even when this optional activation UI is unavailable.
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
    // Automatic detection uses the exact same server-side Bakong verification
    // as the manual button. The browser never declares a payment successful.
    applyAttempt(await api.orders.requestVerification(orderId.value))

    // The order carries fulfilment, which lands after the payment is confirmed.
    if (payment.value?.status === 'PAID' || payment.value?.status === 'VERIFYING') {
      await loadOrder()
      if (order.value?.status === 'FULFILLED') await loadClaims()
    }
  } catch {
    // A failed poll is not worth surfacing: the next one may succeed and the
    // customer can always press "I have paid".
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
      toast.add({
        title: 'Checking with Bakong',
        description: 'SP Cambo is verifying the transfer. This page updates itself when it settles.',
        icon: 'i-lucide-search',
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
          <div class="rounded-lg border border-default bg-elevated/30 p-6">
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
                    ? 'Your entitlement is live. Requests can start immediately.'
                    : 'SP Cambo is activating your entitlement. This page updates itself — you do not need to pay again.' }}
                </p>
              </div>
              <UAlert
                v-if="isFulfilled && pendingClaim"
                color="info"
                variant="subtle"
                icon="i-lucide-key-round"
                title="API access is ready to activate"
                description="Choose a new SP Cambo key or reuse one of your existing active keys. Reusing a key keeps Claude Code and SDK configuration unchanged."
              />

              <UAlert
                v-else-if="isFulfilled && claimedAccess"
                color="success"
                variant="subtle"
                icon="i-lucide-key-round"
                title="API access is active"
                :description="claimedAccess.masked_key ? `Purchased model access is attached to ${claimedAccess.masked_key}.` : 'Your purchased model access is attached to an API key.'"
              />

              <div class="flex flex-wrap justify-center gap-2">
                <UButton
                  v-if="pendingClaim"
                  :to="`/dashboard/claim-key?claim=${pendingClaim.id}`"
                  icon="i-lucide-key-round"
                >
                  Activate API access
                </UButton>
                <UButton
                  to="/dashboard/entitlements"
                  :color="pendingClaim ? 'neutral' : 'primary'"
                  :variant="pendingClaim ? 'subtle' : 'solid'"
                  icon="i-lucide-hourglass"
                >
                  View entitlements
                </UButton>
                <UButton
                  :to="purchasedModel ? `/dashboard/cli-setup?model=${encodeURIComponent(purchasedModel)}` : '/dashboard/cli-setup'"
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-terminal"
                >
                  Setup Claude / CLI
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
                  Nothing was charged. Do not pay an expired code — ask for a new one, which carries a fresh
                  expiry for the same order.
                </p>
              </div>
              <div class="flex flex-wrap justify-center gap-2">
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
                    icon="i-lucide-search"
                    :loading="verifying"
                    @click="iHavePaid"
                  >
                    I have paid — check now
                  </UButton>
                  <p class="text-center text-xs text-muted">
                    SP Cambo checks Bakong automatically while this page is open. Keep this button for an immediate
                    re-check after you pay; pressing it more than once is harmless.
                  </p>
                </div>

                <div class="flex items-center justify-center gap-2 text-xs text-dimmed">
                  <UIcon
                    name="i-lucide-radio"
                    class="size-3.5 animate-pulse"
                  />
                  Auto-checking Bakong for payment
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
