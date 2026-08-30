import { describe, expect, it } from 'vitest'
import type { MoneyAmount } from '~/types/commerce'
import {
  compareUnits,
  formatBasisPoints,
  formatClock,
  formatCompactUnits,
  formatCount,
  formatDurationSeconds,
  formatLatency,
  formatMinorUnits,
  formatMoney,
  formatRemaining,
  formatUnits,
  groupDigits,
  isUnitsDepleted,
  isZeroMinor,
  isZeroMoney,
  maskApiKey,
  maxUnits,
  minorUnitsToDecimalString,
  parseUnits,
  percentOfUnits,
  sumUnits
} from '~/utils/format'

const usd = (minor: string): MoneyAmount => ({ minor, currency: 'USD', exponent: 2 })

describe('minorUnitsToDecimalString', () => {
  it('places the decimal point from the exponent', () => {
    expect(minorUnitsToDecimalString('1050', 2)).toBe('10.50')
    expect(minorUnitsToDecimalString('5', 2)).toBe('0.05')
    expect(minorUnitsToDecimalString('0', 2)).toBe('0.00')
  })

  it('treats a zero exponent as a whole-unit currency', () => {
    expect(minorUnitsToDecimalString('40000', 0)).toBe('40,000')
  })

  it('keeps the sign outside the digits', () => {
    expect(minorUnitsToDecimalString('-1050', 2)).toBe('-10.50')
  })

  it('does not lose precision on values beyond Number.MAX_SAFE_INTEGER', () => {
    // 9007199254740993 is the first integer a float64 cannot represent exactly.
    expect(minorUnitsToDecimalString('9007199254740993', 2)).toBe('90,071,992,547,409.93')
  })
})

describe('formatMoney', () => {
  it('renders a currency symbol when one is known', () => {
    expect(formatMoney(usd('1500'))).toBe('$15.00')
    expect(formatMoney({ minor: '40000', currency: 'KHR', exponent: 0 })).toBe('៛40,000')
  })

  it('falls back to the currency code for anything unrecognised', () => {
    expect(formatMoney({ minor: '1234', currency: 'eur', exponent: 2 })).toBe('EUR 12.34')
  })

  it('keeps a negative amount readable as -$1.00, not $-1.00', () => {
    expect(formatMoney(usd('-100'))).toBe('-$1.00')
  })

  it('renders an em dash rather than a zero for a missing amount', () => {
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney(undefined)).toBe('—')
  })
})

describe('isZeroMoney', () => {
  it('is true for zero however it is spelled', () => {
    expect(isZeroMoney(usd('0'))).toBe(true)
    expect(isZeroMoney(usd('000'))).toBe(true)
    expect(isZeroMoney(usd('-0'))).toBe(true)
  })

  it('treats an absent amount as zero so a caller never shows a discount row for nothing', () => {
    expect(isZeroMoney(null)).toBe(true)
  })

  it('is false for the smallest non-zero amount', () => {
    expect(isZeroMoney(usd('1'))).toBe(false)
  })
})

describe('groupDigits and formatUnits', () => {
  it('groups in thousands', () => {
    expect(groupDigits('1234567')).toBe('1,234,567')
    expect(groupDigits('999')).toBe('999')
  })

  it('formats quota strings without float rounding', () => {
    expect(formatUnits('20000000')).toBe('20,000,000')
    expect(formatUnits('9007199254740993')).toBe('9,007,199,254,740,993')
  })

  it('accepts a number for token counts that arrive as JSON numbers', () => {
    expect(formatUnits(1024)).toBe('1,024')
  })

  it('distinguishes "no value" from zero', () => {
    expect(formatUnits(null)).toBe('—')
    expect(formatUnits('')).toBe('—')
    expect(formatUnits('0')).toBe('0')
  })
})

describe('formatCompactUnits', () => {
  it('shortens large quotas', () => {
    expect(formatCompactUnits('20000000')).toBe('20M')
    expect(formatCompactUnits('1500')).toBe('1.5K')
    expect(formatCompactUnits('1000000000')).toBe('1B')
  })

  it('leaves values below a thousand grouped but unshortened', () => {
    expect(formatCompactUnits('999')).toBe('999')
  })
})

describe('percentOfUnits', () => {
  it('computes an exact percentage with BigInt arithmetic', () => {
    expect(percentOfUnits('50', '200')).toBe(25)
    expect(percentOfUnits('1', '3')).toBeCloseTo(33.33, 2)
  })

  it('returns null rather than dividing by zero', () => {
    expect(percentOfUnits('10', '0')).toBeNull()
    expect(percentOfUnits('10', null)).toBeNull()
  })

  it('clamps to 100 so a reserved overshoot cannot render an impossible bar', () => {
    expect(percentOfUnits('300', '200')).toBe(100)
  })

  it('stays exact for quota values a float would round', () => {
    expect(percentOfUnits('9007199254740993', '9007199254740993')).toBe(100)
  })
})

describe('maxUnits', () => {
  it('picks the largest integer string, not the longest', () => {
    expect(maxUnits(['9', '1000', '250'])).toBe('1000')
  })

  it('compares beyond float precision', () => {
    expect(maxUnits(['9007199254740993', '9007199254740992'])).toBe('9007199254740993')
  })

  it('ignores blanks, nulls and negatives', () => {
    expect(maxUnits([null, undefined, '', '-500', '7'])).toBe('7')
  })

  it('is zero for an empty set, so a chart scales instead of dividing by nothing', () => {
    expect(maxUnits([])).toBe('0')
  })
})

describe('isUnitsDepleted', () => {
  it('is true for zero, negative and missing', () => {
    expect(isUnitsDepleted('0')).toBe(true)
    expect(isUnitsDepleted('-5')).toBe(true)
    expect(isUnitsDepleted(null)).toBe(true)
  })

  it('is false while anything remains', () => {
    expect(isUnitsDepleted('1')).toBe(false)
  })
})

describe('formatDurationSeconds', () => {
  it('renders an SP Cambo "1 day" package as exactly 24 hours', () => {
    expect(formatDurationSeconds(86400)).toBe('24 hours')
  })

  it('handles sub-hour and multi-day lifetimes', () => {
    expect(formatDurationSeconds(3600)).toBe('1 hour')
    expect(formatDurationSeconds(60)).toBe('1 minute')
  })

  it('has no answer for a missing lifetime', () => {
    expect(formatDurationSeconds(null)).toBe('—')
  })
})

describe('formatRemaining', () => {
  it('reads as a countdown at each magnitude', () => {
    expect(formatRemaining(90_000)).toBe('1m 30s')
    expect(formatRemaining(3 * 3600_000 + 12 * 60_000)).toBe('3h 12m')
    expect(formatRemaining(28 * 3600_000)).toBe('1d 4h')
  })

  it('says Expired rather than counting backwards', () => {
    expect(formatRemaining(0)).toBe('Expired')
    expect(formatRemaining(-1)).toBe('Expired')
  })
})

describe('formatClock', () => {
  it('pads to mm:ss for the payment countdown', () => {
    expect(formatClock(65_000)).toBe('01:05')
    expect(formatClock(600_000)).toBe('10:00')
  })

  it('floors at zero, never showing a negative clock', () => {
    expect(formatClock(-5000)).toBe('00:00')
  })
})

describe('formatCount and formatLatency', () => {
  it('groups request counts', () => {
    expect(formatCount(12345)).toBe('12,345')
    expect(formatCount(null)).toBe('—')
  })

  it('keeps sub-second latency in milliseconds', () => {
    expect(formatLatency(842)).toBe('842 ms')
    expect(formatLatency(1500)).toBe('1.50 s')
    expect(formatLatency(null)).toBe('—')
  })
})

describe('isZeroMinor and formatMinorUnits', () => {
  it('recognises zero however the backend spells it', () => {
    expect(isZeroMinor('0')).toBe(true)
    expect(isZeroMinor('000')).toBe(true)
    expect(isZeroMinor(null)).toBe(true)
    expect(isZeroMinor('1')).toBe(false)
  })

  /**
   * `admin/overview.fulfilled_revenue` publishes no `exponent`, so the decimal
   * point cannot be placed. Guessing it would misstate revenue by a factor of a
   * hundred, so the exact integer is shown instead.
   */
  it('shows exact minor units rather than inventing a decimal point', () => {
    expect(formatMinorUnits('1050')).toBe('1,050')
    expect(formatMinorUnits('1050', 'usd')).toBe('1,050 USD')
  })

  it('stays exact past float precision', () => {
    expect(formatMinorUnits('9007199254740993')).toBe('9,007,199,254,740,993')
  })

  it('distinguishes "no value" from zero', () => {
    expect(formatMinorUnits(null)).toBe('—')
    expect(formatMinorUnits('0')).toBe('0')
  })
})

describe('parseUnits, sumUnits and compareUnits', () => {
  it('parses an integer string exactly, beyond what a double holds', () => {
    expect(parseUnits('9007199254740993')).toBe(9007199254740993n)
    expect(parseUnits('-500')).toBe(-500n)
  })

  it('has no value for a missing or unparsable quantity', () => {
    expect(parseUnits(null)).toBeNull()
    expect(parseUnits('')).toBeNull()
  })

  it('totals exactly, skipping entries it cannot read rather than guessing', () => {
    expect(sumUnits(['20000000', '5000000'])).toBe('25000000')
    expect(sumUnits(['9007199254740993', '1'])).toBe('9007199254740994')
    expect(sumUnits([null, undefined, '', '7'])).toBe('7')
    expect(sumUnits([])).toBe('0')
  })

  it('compares exactly, where Number() would call two allocations equal', () => {
    expect(Number('9007199254740993') === Number('9007199254740992')).toBe(true)
    expect(compareUnits('9007199254740993', '9007199254740992')).toBe(1)
    expect(compareUnits('100', '1000')).toBe(-1)
    expect(compareUnits('100', '100')).toBe(0)
  })

  it('treats a missing side as zero so a sort never throws', () => {
    expect(compareUnits(null, '1')).toBe(-1)
    expect(compareUnits('1', null)).toBe(1)
  })
})

describe('maskApiKey', () => {
  it('shows only the prefix and last four characters', () => {
    const masked = maskApiKey('sk-', '9f2a')

    expect(masked.startsWith('sk-')).toBe(true)
    expect(masked.endsWith('9f2a')).toBe(true)
  })

  it('never reproduces anything resembling a full secret', () => {
    const masked = maskApiKey('sk-', '9f2a')

    expect(masked).not.toContain('secret')
    expect(masked.replace(/[^a-z0-9]/gi, '').length).toBeLessThan(24)
  })
})

describe('formatBasisPoints', () => {
  it('reads basis points as a percentage', () => {
    expect(formatBasisPoints(2500)).toBe('25%')
    expect(formatBasisPoints(10_000)).toBe('100%')
    expect(formatBasisPoints(100)).toBe('1%')
    expect(formatBasisPoints(0)).toBe('0%')
  })

  it('keeps a fractional percentage exactly', () => {
    expect(formatBasisPoints(2550)).toBe('25.5%')
    expect(formatBasisPoints(2505)).toBe('25.05%')
    expect(formatBasisPoints(1)).toBe('0.01%')
  })

  it('groups a percentage above a thousand without eating its zeros', () => {
    // The trailing-zero trim must not reach past the decimal point into `1,000`.
    expect(formatBasisPoints(100_000)).toBe('1,000%')
    expect(formatBasisPoints(20_000)).toBe('200%')
  })

  it('shows a negative margin as negative', () => {
    // A package priced under cost yields a negative `margin_bps`; hiding the sign
    // would turn a loss into an apparent profit.
    expect(formatBasisPoints(-500)).toBe('-5%')
    expect(formatBasisPoints(-2550)).toBe('-25.5%')
  })

  it('reports an absent margin as unknown rather than as zero', () => {
    expect(formatBasisPoints(null)).toBe('—')
    expect(formatBasisPoints(undefined)).toBe('—')
    expect(formatBasisPoints(Number.NaN)).toBe('—')
    expect(formatBasisPoints(Number.POSITIVE_INFINITY)).toBe('—')
  })
})
