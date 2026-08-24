import type { RequestActivity } from '~/types/commerce'

/**
 * Safe display rows for one activity record's server-published token metadata.
 *
 * Input and output stay visible so an unsettled request honestly reads as `—`
 * rather than disappearing. Cache and reasoning categories are supplementary:
 * a null is not reported and a zero adds no information, so they only appear for
 * a nonzero value. `total_tokens` is wholly server-owned; this function only
 * decides whether to render it and never derives or repairs it from components.
 */
export interface ActivityTokenRow {
  label: 'Input' | 'Output' | 'Cache read' | 'Cache write' | 'Reasoning' | 'Total'
  value: number | null
}

type ActivityTokenMetadata = Pick<
  RequestActivity,
  | 'input_tokens'
  | 'output_tokens'
  | 'cache_read_tokens'
  | 'cache_write_tokens'
  | 'reasoning_tokens'
  | 'total_tokens'
>

const isNonZero = (value: number | null) => value !== null && value !== 0

export function activityTokenRows(activity: ActivityTokenMetadata): ActivityTokenRow[] {
  const rows: ActivityTokenRow[] = [
    { label: 'Input', value: activity.input_tokens },
    { label: 'Output', value: activity.output_tokens }
  ]

  for (const row of [
    { label: 'Cache read' as const, value: activity.cache_read_tokens },
    { label: 'Cache write' as const, value: activity.cache_write_tokens },
    { label: 'Reasoning' as const, value: activity.reasoning_tokens },
    { label: 'Total' as const, value: activity.total_tokens }
  ]) {
    if (isNonZero(row.value)) {
      rows.push(row)
    }
  }

  return rows
}
