import { describe, expect, it } from 'vitest'
import { activityTokenRows } from '~/utils/activityTokens'

const tokens = (overrides: Partial<Parameters<typeof activityTokenRows>[0]> = {}) => ({
  input_tokens: 120,
  output_tokens: 45,
  cache_read_tokens: null,
  saved_tokens: null,
  cache_write_tokens: null,
  reasoning_tokens: null,
  total_tokens: null,
  ...overrides
})

describe('activityTokenRows', () => {
  it('keeps the two primary categories in a fixed order', () => {
    expect(activityTokenRows(tokens())).toEqual([
      { label: 'Input', value: 120 },
      { label: 'Output', value: 45 }
    ])
  })

  it('preserves an unreported primary category and a recorded zero', () => {
    expect(activityTokenRows(tokens({ input_tokens: null, output_tokens: 0 }))).toEqual([
      { label: 'Input', value: null },
      { label: 'Output', value: 0 }
    ])
  })

  it('adds nonzero supplementary categories in their server-metadata order', () => {
    expect(activityTokenRows(tokens({
      cache_read_tokens: 14,
      saved_tokens: '12',
      cache_write_tokens: 9,
      reasoning_tokens: 3,
      total_tokens: 191
    }))).toEqual([
      { label: 'Input', value: 120 },
      { label: 'Output', value: 45 },
      { label: 'Reused input', value: 14 },
      { label: 'Saved', value: '12' },
      { label: 'Cache write', value: 9 },
      { label: 'Reasoning', value: 3 },
      { label: 'Total', value: 191 }
    ])
  })

  it('does not render null or zero supplementary categories as invented information', () => {
    expect(activityTokenRows(tokens({
      cache_read_tokens: 0,
      saved_tokens: '0',
      cache_write_tokens: null,
      reasoning_tokens: 0,
      total_tokens: 0
    }))).toEqual([
      { label: 'Input', value: 120 },
      { label: 'Output', value: 45 }
    ])
  })

  it('never derives a replacement total for a historical zero total', () => {
    const rows = activityTokenRows(tokens({
      input_tokens: 100,
      output_tokens: 20,
      cache_read_tokens: 5,
      saved_tokens: '4',
      total_tokens: 0
    }))

    expect(rows).toEqual([
      { label: 'Input', value: 100 },
      { label: 'Output', value: 20 },
      { label: 'Reused input', value: 5 },
      { label: 'Saved', value: '4' }
    ])
    expect(rows.some(row => row.label === 'Total')).toBe(false)
  })
})
