import { describe, expect, it } from 'vitest'
import { MIN_VISIBLE_BAR_PERCENT, axisLabelIndexes, barHeightPercent, peakBucketUnits } from '~/utils/usageChart'

describe('peakBucketUnits', () => {
  it('finds the busiest bucket', () => {
    expect(peakBucketUnits(['1200', '87000', '450'])).toBe('87000')
  })

  it('compares beyond float precision, so a huge bucket is not rounded into a tie', () => {
    expect(peakBucketUnits(['9007199254740992', '9007199254740993'])).toBe('9007199254740993')
  })

  it('is zero for a window with no data, so the chart scales instead of dividing by nothing', () => {
    expect(peakBucketUnits([])).toBe('0')
    expect(peakBucketUnits([null, undefined, ''])).toBe('0')
  })
})

describe('barHeightPercent', () => {
  it('draws the busiest bucket full height', () => {
    expect(barHeightPercent('87000', '87000')).toBe(100)
  })

  it('scales the rest against the peak', () => {
    expect(barHeightPercent('43500', '87000')).toBe(50)
  })

  it('keeps a hairline for a bucket that had traffic but almost none', () => {
    // 1 of 87000 rounds to well under a pixel; it must still be visible.
    expect(barHeightPercent('1', '87000')).toBe(MIN_VISIBLE_BAR_PERCENT)
  })

  it('draws nothing for a genuinely empty bucket, rather than implying traffic', () => {
    expect(barHeightPercent('0', '87000')).toBe(0)
    expect(barHeightPercent(null, '87000')).toBe(0)
  })

  it('draws nothing when the whole window is empty', () => {
    expect(barHeightPercent('0', '0')).toBe(0)
  })

  it('never exceeds full height, so a bar cannot overflow its track', () => {
    for (const units of ['0', '1', '43500', '87000', '9007199254740993']) {
      const height = barHeightPercent(units, '87000')

      expect(height).toBeGreaterThanOrEqual(0)
      expect(height).toBeLessThanOrEqual(100)
    }
  })
})

describe('axisLabelIndexes', () => {
  it('labels the first, middle and last bucket', () => {
    expect([...axisLabelIndexes(24)].sort((a, b) => a - b)).toEqual([0, 11, 23])
  })

  it('never labels an index outside the buckets it was given', () => {
    for (const count of [1, 2, 3, 7, 24, 30, 720]) {
      for (const index of axisLabelIndexes(count)) {
        expect(index).toBeGreaterThanOrEqual(0)
        expect(index).toBeLessThan(count)
      }
    }
  })

  it('collapses to a single label for a single bucket', () => {
    expect([...axisLabelIndexes(1)]).toEqual([0])
  })

  it('labels nothing for an empty window', () => {
    expect(axisLabelIndexes(0).size).toBe(0)
    expect(axisLabelIndexes(-3).size).toBe(0)
  })
})
