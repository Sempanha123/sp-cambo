import { describe, expect, it } from 'vitest'
import {
  clockSkewMs,
  countdownToneClass,
  isAwaitingPayment,
  parseInstant,
  paymentOutcome,
  pollDelayMs,
  remainingMs
} from '~/utils/paymentState'

/** A device clock that is ten minutes ahead of the control plane. */
const DEVICE_NOW = Date.parse('2026-08-21T12:10:00Z')
const SERVER_NOW = '2026-08-21T12:00:00Z'

describe('parseInstant', () => {
  it('reads an ISO-8601 instant with an explicit offset', () => {
    expect(parseInstant('2026-08-21T12:00:00Z')).toBe(Date.parse('2026-08-21T12:00:00Z'))
    expect(parseInstant('2026-08-21T19:00:00+07:00')).toBe(Date.parse('2026-08-21T12:00:00Z'))
  })

  it('has no answer for a missing or unparsable timestamp, rather than guessing one', () => {
    expect(parseInstant(null)).toBeNull()
    expect(parseInstant(undefined)).toBeNull()
    expect(parseInstant('')).toBeNull()
    expect(parseInstant('soon')).toBeNull()
  })
})

describe('clockSkewMs', () => {
  it('measures how far the device runs ahead of the server', () => {
    expect(clockSkewMs(SERVER_NOW, DEVICE_NOW)).toBe(10 * 60_000)
  })

  it('is negative when the device runs behind', () => {
    expect(clockSkewMs('2026-08-21T12:20:00Z', DEVICE_NOW)).toBe(-10 * 60_000)
  })

  it('is zero when the server time is unusable, leaving the countdown uncorrected', () => {
    expect(clockSkewMs(null, DEVICE_NOW)).toBe(0)
    expect(clockSkewMs('not-a-time', DEVICE_NOW)).toBe(0)
  })
})

describe('remainingMs', () => {
  it('counts down against the server clock, not the device clock', () => {
    // The attempt expires 15 server-minutes from the server's "now". A device
    // running 10 minutes fast must still be shown the full 15 minutes.
    const skewMs = clockSkewMs(SERVER_NOW, DEVICE_NOW)

    expect(remainingMs({ expiresAt: '2026-08-21T12:15:00Z', deviceNowMs: DEVICE_NOW, skewMs }))
      .toBe(15 * 60_000)
  })

  it('would have called a live code expired if the device clock were trusted', () => {
    // Same inputs with the skew ignored: proof the correction is doing work.
    expect(remainingMs({ expiresAt: '2026-08-21T12:15:00Z', deviceNowMs: DEVICE_NOW, skewMs: 0 }))
      .toBe(5 * 60_000)
  })

  it('floors at zero instead of counting backwards', () => {
    expect(remainingMs({ expiresAt: '2026-08-21T11:00:00Z', deviceNowMs: DEVICE_NOW, skewMs: 0 })).toBe(0)
  })

  it('distinguishes an unknown expiry from an elapsed one', () => {
    expect(remainingMs({ expiresAt: null, deviceNowMs: DEVICE_NOW, skewMs: 0 })).toBeNull()
  })
})

describe('paymentOutcome', () => {
  const outcome = (
    attemptStatus: Parameters<typeof paymentOutcome>[0]['attemptStatus'],
    orderStatus: Parameters<typeof paymentOutcome>[0]['orderStatus'] = 'PENDING_PAYMENT',
    countdownExpired = false
  ) => paymentOutcome({ attemptStatus, orderStatus, countdownExpired })

  it('waits while the attempt is pending and the code is live', () => {
    expect(outcome('PENDING')).toBe('waiting')
    expect(isAwaitingPayment(outcome('PENDING'))).toBe(true)
  })

  it('keeps waiting while the backend verifies, which is not yet a payment', () => {
    expect(outcome('VERIFYING')).toBe('waiting')
    expect(outcome('PENDING', 'VERIFYING')).toBe('waiting')
  })

  it('reports paid from either the attempt or the order', () => {
    expect(outcome('PAID')).toBe('paid')
    expect(outcome('PENDING', 'PAID')).toBe('paid')
    expect(outcome('PENDING', 'FULFILLED')).toBe('paid')
  })

  it('never tells a customer to pay again for a transfer that settled at the deadline', () => {
    // A payment confirmed as the clock hits zero must read as paid, not expired.
    expect(outcome('PAID', 'PAID', true)).toBe('paid')
    expect(outcome('PENDING', 'FULFILLED', true)).toBe('paid')
  })

  it('treats an elapsed countdown as expired once the server has said nothing better', () => {
    expect(outcome('PENDING', 'PENDING_PAYMENT', true)).toBe('expired')
    expect(outcome('EXPIRED')).toBe('expired')
    expect(outcome('PENDING', 'EXPIRED')).toBe('expired')
  })

  it('reports a failed or cancelled order as failed while the code is still live', () => {
    expect(outcome('FAILED')).toBe('failed')
    expect(outcome('PENDING', 'FAILED')).toBe('failed')
    expect(outcome('PENDING', 'CANCELLED')).toBe('failed')
  })

  it('waits when nothing has loaded yet rather than declaring a failure', () => {
    expect(outcome(null, null)).toBe('waiting')
    expect(outcome(undefined, undefined)).toBe('waiting')
  })
})

describe('pollDelayMs', () => {
  it('polls briskly at first, then backs off', () => {
    expect(pollDelayMs(0)).toBe(4000)
    expect(pollDelayMs(59_999)).toBe(4000)
    expect(pollDelayMs(60_000)).toBe(8000)
    expect(pollDelayMs(4 * 60_000)).toBe(8000)
    expect(pollDelayMs(5 * 60_000)).toBe(15_000)
  })

  it('never returns a delay that would busy-loop a forgotten tab', () => {
    for (const elapsed of [0, 30_000, 60_000, 600_000, 3600_000]) {
      expect(pollDelayMs(elapsed)).toBeGreaterThanOrEqual(4000)
    }
  })

  it('does not shorten as the wait grows', () => {
    const samples = [0, 60_000, 5 * 60_000, 60 * 60_000].map(pollDelayMs)

    for (let index = 1; index < samples.length; index += 1) {
      expect(samples[index]!).toBeGreaterThanOrEqual(samples[index - 1]!)
    }
  })
})

describe('countdownToneClass', () => {
  it('escalates as the window closes', () => {
    expect(countdownToneClass(10 * 60_000)).toBe('text-highlighted')
    expect(countdownToneClass(4 * 60_000)).toBe('text-warning')
    expect(countdownToneClass(30_000)).toBe('text-error')
    expect(countdownToneClass(0)).toBe('text-error')
  })

  it('does not alarm when the remaining time is simply unknown', () => {
    expect(countdownToneClass(null)).toBe('text-highlighted')
  })
})
