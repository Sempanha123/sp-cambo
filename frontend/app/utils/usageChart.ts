import { isUnitsDepleted, maxUnits, percentOfUnits } from './format'

/**
 * Scaling for the usage chart.
 *
 * Bucket quantities are exact integer strings and are compared as such: the
 * chart is scaled against the busiest bucket with BigInt arithmetic, never by
 * converting quota to a float.
 */

/** Smallest bar drawn for a non-zero bucket, so a quiet period is still visible. */
export const MIN_VISIBLE_BAR_PERCENT = 2

/** Height of the busiest bucket's bar, against which the rest are scaled. */
export function peakBucketUnits(bucketUnits: Array<string | null | undefined>): string {
  return maxUnits(bucketUnits)
}

/**
 * Bar height as a percentage of the peak.
 *
 * Whether a bucket is empty is decided from its units, not from the rounded
 * percentage: `percentOfUnits` truncates at two decimals, so a real but tiny
 * bucket — one request in a busy day — comes back as 0 and would otherwise be
 * drawn as no traffic at all. Such a bucket gets a hairline; a genuinely empty
 * one still gets nothing.
 */
export function barHeightPercent(units: string | null | undefined, peakUnits: string): number {
  if (isUnitsDepleted(units)) {
    return 0
  }

  const percent = percentOfUnits(units, peakUnits)

  if (percent === null) {
    return 0
  }

  return percent < MIN_VISIBLE_BAR_PERCENT ? MIN_VISIBLE_BAR_PERCENT : percent
}

/**
 * Which bucket indexes get an axis label: the first, the middle and the last, so
 * labels stay readable at any bucket count instead of overlapping.
 */
export function axisLabelIndexes(count: number): Set<number> {
  if (count <= 0) {
    return new Set<number>()
  }

  return new Set([0, Math.floor((count - 1) / 2), count - 1])
}
