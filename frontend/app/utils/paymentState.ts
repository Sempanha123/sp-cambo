import type { OrderStatus, PaymentAttemptStatus } from '~/types/commerce'

/**
 * Payment-screen state, kept out of the page component so it can be tested
 * directly.
 *
 * Every function here is pure: a value of what the control plane said plus, at
 * most, the device clock. The rule the module exists to enforce is that the
 * countdown and the outcome follow the server. A device with a wrong clock must
 * not be able to show an expired code as payable, or a payable code as expired,
 * and nothing in the browser may decide that money arrived.
 */

/** Milliseconds for an ISO-8601 instant, or null when it cannot be trusted. */
export function parseInstant(iso: string | null | undefined): number | null {
  if (!iso) {
    return null
  }

  const parsed = Date.parse(iso)

  return Number.isNaN(parsed) ? null : parsed
}

/**
 * How far this device's clock runs ahead of the control plane's, from the
 * `server_time` sent with every payment attempt.
 *
 * Zero when the server time is missing or unparsable: an uncorrected countdown
 * is still better than one corrected by a guess.
 */
export function clockSkewMs(serverTimeIso: string | null | undefined, deviceNowMs: number): number {
  const serverNow = parseInstant(serverTimeIso)

  return serverNow === null ? 0 : deviceNowMs - serverNow
}

export interface CountdownInput {
  /** Server-authoritative expiry of the payment attempt. */
  expiresAt: string | null | undefined
  deviceNowMs: number
  /** From `clockSkewMs`. */
  skewMs: number
}

/**
 * Milliseconds left on the attempt, counted the way the server counts them.
 *
 * Null means "no expiry known", which is not the same as zero and must not be
 * rendered as an expired code. Never negative: a countdown does not run
 * backwards.
 */
export function remainingMs({ expiresAt, deviceNowMs, skewMs }: CountdownInput): number | null {
  const expiry = parseInstant(expiresAt)

  if (expiry === null) {
    return null
  }

  return Math.max(0, expiry - (deviceNowMs - skewMs))
}

export type PaymentOutcome = 'paid' | 'expired' | 'failed' | 'waiting'

export interface PaymentOutcomeInput {
  attemptStatus: PaymentAttemptStatus | null | undefined
  orderStatus: OrderStatus | null | undefined
  /** `remainingMs` has reached zero. */
  countdownExpired: boolean
}

/**
 * What the payment screen should show.
 *
 * `paid` is tested first on purpose: a transfer that settles in the last second
 * of the window is paid, and the customer must never be told to pay again for an
 * order the server has taken money for. An expired countdown only decides the
 * outcome when the server has not said anything better.
 */
export function paymentOutcome({ attemptStatus, orderStatus, countdownExpired }: PaymentOutcomeInput): PaymentOutcome {
  if (attemptStatus === 'PAID' || orderStatus === 'PAID' || orderStatus === 'FULFILLED') {
    return 'paid'
  }

  if (attemptStatus === 'EXPIRED' || orderStatus === 'EXPIRED' || countdownExpired) {
    return 'expired'
  }

  if (attemptStatus === 'FAILED' || orderStatus === 'FAILED' || orderStatus === 'CANCELLED') {
    return 'failed'
  }

  return 'waiting'
}

/** True while the outcome is still open, which is also the only time to poll. */
export function isAwaitingPayment(outcome: PaymentOutcome): boolean {
  return outcome === 'waiting'
}

/**
 * Delay before the next verification poll, backing off as the wait grows so a
 * customer who leaves the tab open does not hammer the control plane.
 */
export function pollDelayMs(elapsedMs: number): number {
  if (elapsedMs < 60_000) {
    return 4000
  }

  if (elapsedMs < 5 * 60_000) {
    return 8000
  }

  return 15_000
}

/**
 * Colour for the countdown. Urgency is shown in the last minute and warned about
 * in the last five; an unknown remaining time is styled as ordinary text rather
 * than as alarm.
 */
export function countdownToneClass(remaining: number | null): string {
  if (remaining === null) {
    return 'text-highlighted'
  }

  if (remaining < 60_000) {
    return 'text-error'
  }

  if (remaining < 5 * 60_000) {
    return 'text-warning'
  }

  return 'text-highlighted'
}
