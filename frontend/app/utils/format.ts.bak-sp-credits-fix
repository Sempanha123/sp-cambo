import type { MoneyAmount } from '~/types/commerce'

/**
 * Display formatting for SP Cambo money and quota values.
 *
 * Money and metered quota arrive as integer strings (minor units / raw units)
 * and are formatted with string and BigInt arithmetic only. No binary-float
 * maths is performed on a commercial value anywhere in the frontend.
 */

const CURRENCY_SYMBOLS: Record<string, string> = {
  USD: '$',
  KHR: '៛'
}

/** Groups an unsigned digit string in thousands: `1234567` -> `1,234,567`. */
export function groupDigits(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

interface SignedDigits {
  negative: boolean
  digits: string
}

function parseIntegerString(value: string): SignedDigits {
  const trimmed = value.trim()
  const negative = trimmed.startsWith('-')
  const digits = trimmed.replace(/^[+-]/, '').replace(/\D/g, '')

  return {
    negative,
    digits: digits === '' ? '0' : digits
  }
}

/** Exact decimal string for an integer minor-unit amount, e.g. `1050`/2 -> `10.50`. */
export function minorUnitsToDecimalString(minor: string, exponent: number): string {
  const { negative, digits } = parseIntegerString(minor)

  if (exponent <= 0) {
    return `${negative ? '-' : ''}${groupDigits(digits)}`
  }

  const padded = digits.padStart(exponent + 1, '0')
  const whole = padded.slice(0, padded.length - exponent)
  const fraction = padded.slice(padded.length - exponent)

  return `${negative ? '-' : ''}${groupDigits(whole)}.${fraction}`
}

/**
 * Basis points as a percentage, e.g. `2500` -> `25%`, `2550` -> `25.5%`.
 *
 * Basis points are hundredths of a percent, so dividing by 100 is exactly the
 * two-decimal case `minorUnitsToDecimalString` already handles — no float
 * division is performed on a margin. Trailing zeros are trimmed so a whole
 * percentage reads as one.
 */
export function formatBasisPoints(bps: number | null | undefined): string {
  if (bps === null || bps === undefined || !Number.isFinite(bps)) {
    return '—'
  }

  const decimal = minorUnitsToDecimalString(String(Math.trunc(bps)), 2)

  return `${decimal.replace(/\.?0+$/, '')}%`
}

/** Formats a `MoneyAmount` for display, e.g. `$10.50`. */
export function formatMoney(amount: MoneyAmount | null | undefined): string {
  if (!amount) {
    return '—'
  }

  const value = minorUnitsToDecimalString(amount.minor, amount.exponent)
  const symbol = CURRENCY_SYMBOLS[amount.currency.toUpperCase()]

  if (symbol) {
    return value.startsWith('-') ? `-${symbol}${value.slice(1)}` : `${symbol}${value}`
  }

  return `${amount.currency.toUpperCase()} ${value}`
}

/** True when a money amount is exactly zero, using string arithmetic. */
export function isZeroMoney(amount: MoneyAmount | null | undefined): boolean {
  if (!amount) {
    return true
  }

  return parseIntegerString(amount.minor).digits.replace(/^0+/, '') === ''
}

/** True when an integer minor-unit string is exactly zero. */
export function isZeroMinor(minor: string | null | undefined): boolean {
  if (minor === null || minor === undefined || minor === '') {
    return true
  }

  return parseIntegerString(minor).digits.replace(/^0+/, '') === ''
}

/**
 * Display for an integer minor-unit amount whose currency scale is not published.
 *
 * The decimal point cannot be placed without knowing the currency's exponent, and
 * guessing it would misstate money by a factor of a hundred. So the exact integer
 * is shown and labelled as minor units rather than dressed up as a decimal
 * amount. Callers with an `exponent` should use `formatMoney` instead.
 */
export function formatMinorUnits(minor: string | null | undefined, currency?: string | null): string {
  if (minor === null || minor === undefined || minor === '') {
    return '—'
  }

  const { negative, digits } = parseIntegerString(minor)
  const value = `${negative ? '-' : ''}${groupDigits(digits)}`

  return currency ? `${value} ${currency.toUpperCase()}` : value
}

/** Grouped integer display for token/credit unit strings. */
export function formatUnits(units: string | number | null | undefined): string {
  if (units === null || units === undefined || units === '') {
    return '—'
  }

  const { negative, digits } = parseIntegerString(String(units))

  return `${negative ? '-' : ''}${groupDigits(digits)}`
}

const COMPACT_STEPS = [
  { suffix: 'T', digits: 13 },
  { suffix: 'B', digits: 10 },
  { suffix: 'M', digits: 7 },
  { suffix: 'K', digits: 4 }
] as const

/**
 * Compact token display used in dense tiles, e.g. `20,000,000` -> `20M`.
 * Falls back to grouped digits below one thousand.
 */
export function formatCompactUnits(units: string | number | null | undefined): string {
  if (units === null || units === undefined || units === '') {
    return '—'
  }

  const { negative, digits } = parseIntegerString(String(units))
  const sign = negative ? '-' : ''
  const normalised = digits.replace(/^0+(?=\d)/, '')

  for (const step of COMPACT_STEPS) {
    if (normalised.length >= step.digits) {
      const scale = step.digits - 1
      const whole = normalised.slice(0, normalised.length - scale)
      const remainder = normalised.slice(normalised.length - scale)
      const firstDecimal = remainder.charAt(0)

      const value = firstDecimal === '0' || whole.length >= 3
        ? groupDigits(whole)
        : `${groupDigits(whole)}.${firstDecimal}`

      return `${sign}${value}${step.suffix}`
    }
  }

  return `${sign}${groupDigits(normalised)}`
}

/**
 * Integer percentage of `part` relative to `total`, computed with BigInt so
 * large quota values never lose precision. Returns null when total is zero.
 */
export function percentOfUnits(part: string | null | undefined, total: string | null | undefined): number | null {
  if (!part || !total) {
    return null
  }

  try {
    const totalValue = BigInt(parseIntegerString(total).digits)

    if (totalValue === 0n) {
      return null
    }

    const partValue = BigInt(parseIntegerString(part).digits)
    const percent = Number((partValue * 10000n) / totalValue) / 100

    return Math.min(100, Math.max(0, percent))
  } catch {
    return null
  }
}

/**
 * Exact integer value of a unit string, or null when absent or unparsable.
 *
 * Quota and minor-unit values are transported as integer strings precisely so
 * they survive quantities a double cannot hold. Anything that totals or compares
 * them goes through here rather than through `Number`.
 */
export function parseUnits(value: string | number | null | undefined): bigint | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const { negative, digits } = parseIntegerString(String(value))

  try {
    const parsed = BigInt(digits)

    return negative ? -parsed : parsed
  } catch {
    return null
  }
}

/** Exact total of integer unit strings. Unparsable entries are skipped, not guessed. */
export function sumUnits(values: Array<string | number | null | undefined>): string {
  let total = 0n

  for (const value of values) {
    const parsed = parseUnits(value)

    if (parsed !== null) {
      total += parsed
    }
  }

  return total.toString()
}

/** Exact comparison of two integer unit strings: negative, zero or positive. */
export function compareUnits(
  a: string | number | null | undefined,
  b: string | number | null | undefined
): number {
  const left = parseUnits(a) ?? 0n
  const right = parseUnits(b) ?? 0n

  if (left < right) {
    return -1
  }

  return left > right ? 1 : 0
}

/**
 * Largest of a set of integer unit strings, compared with BigInt.
 *
 * Used to scale usage charts against the busiest bucket. Negative or unparsable
 * values are ignored rather than guessed at; usage is never negative.
 */
export function maxUnits(values: Array<string | null | undefined>): string {
  let largest = 0n

  for (const value of values) {
    if (value === null || value === undefined || value === '') {
      continue
    }

    const { negative, digits } = parseIntegerString(String(value))

    if (negative) {
      continue
    }

    try {
      const parsed = BigInt(digits)

      if (parsed > largest) {
        largest = parsed
      }
    } catch {
      continue
    }
  }

  return largest.toString()
}

/** True when an integer unit string is zero or negative. */
export function isUnitsDepleted(units: string | null | undefined): boolean {
  if (units === null || units === undefined || units === '') {
    return true
  }

  const { negative, digits } = parseIntegerString(units)

  return negative || digits.replace(/^0+/, '') === ''
}

/**
 * Exact duration label from seconds. `86400` renders as `24 hours` because an
 * SP Cambo "1 day" package means exactly 24 hours from activation.
 */
export function formatDurationSeconds(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined || seconds <= 0) {
    return '—'
  }

  if (seconds % 86400 === 0 && seconds >= 172800) {
    const days = seconds / 86400

    return `${days} days`
  }

  if (seconds % 3600 === 0) {
    const hours = seconds / 3600

    return hours === 1 ? '1 hour' : `${hours} hours`
  }

  if (seconds % 60 === 0) {
    const minutes = seconds / 60

    return minutes === 1 ? '1 minute' : `${minutes} minutes`
  }

  return `${seconds} seconds`
}

/** Localized absolute timestamp. Backend stores UTC; the browser renders local time. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }

  const date = new Date(iso)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(date)
}

/** Localized date without a time component. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }

  const date = new Date(iso)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

/** Full timestamp including timezone, used where exact expiry matters. */
export function formatExactTimestamp(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }

  const date = new Date(iso)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'full',
    timeStyle: 'long'
  }).format(date)
}

/** Compact `1d 4h`, `3h 12m`, `48s` style remaining-time label. */
export function formatRemaining(milliseconds: number): string {
  if (!Number.isFinite(milliseconds) || milliseconds <= 0) {
    return 'Expired'
  }

  const totalSeconds = Math.floor(milliseconds / 1000)
  const days = Math.floor(totalSeconds / 86400)
  const hours = Math.floor((totalSeconds % 86400) / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  if (days > 0) {
    return `${days}d ${hours}h`
  }

  if (hours > 0) {
    return `${hours}h ${minutes}m`
  }

  if (minutes > 0) {
    return `${minutes}m ${seconds}s`
  }

  return `${seconds}s`
}

/** `mm:ss` clock used by the payment countdown. */
export function formatClock(milliseconds: number): string {
  const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000))
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60

  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

/** Grouped plain integer, safe for request counts and token counts. */
export function formatCount(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) {
    return '—'
  }

  return groupDigits(String(Math.trunc(value)))
}

/** Latency label: sub-second values stay in milliseconds. */
export function formatLatency(milliseconds: number | null | undefined): string {
  if (milliseconds === null || milliseconds === undefined || !Number.isFinite(milliseconds)) {
    return '—'
  }

  if (milliseconds < 1000) {
    return `${Math.round(milliseconds)} ms`
  }

  return `${(milliseconds / 1000).toFixed(milliseconds < 10000 ? 2 : 1)} s`
}

/** Byte-size label for request caps. */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) {
    return '—'
  }

  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unitIndex = 0

  while (value >= 1024 && unitIndex < units.length - 1) {
    value = value / 1024
    unitIndex += 1
  }

  const rounded = unitIndex === 0 ? String(Math.round(value)) : value.toFixed(value < 10 ? 1 : 0)

  return `${rounded} ${units[unitIndex]}`
}

/** Masked API key display for lists: prefix, ellipsis, last four. */
export function maskApiKey(prefix: string, lastFour: string): string {
  return `${prefix}${'•'.repeat(8)}${lastFour}`
}
