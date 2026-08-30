// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import type { ApiKeySummary, RequestActivity, UsageSummary } from '~/types/commerce'
import { SpApiError } from '~/utils/spApiError'
import UsagePage from '~/pages/dashboard/usage.vue'

const key = (overrides: Partial<ApiKeySummary> & { id: string }): ApiKeySummary => ({
  label: `Key ${overrides.id}`,
  prefix: 'sk-',
  last_four: 'ab12',
  status: 'ACTIVE',
  created_at: '2026-08-22T10:00:00.000Z',
  last_used_at: null,
  expires_at: null,
  allowed_model_aliases: [],
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null,
    max_request_bytes: null,
    max_output_tokens: null
  },
  bound_entitlement_id: null,
  secret_recopy_available: true,
  ...overrides
})

const request = (overrides: Partial<RequestActivity> = {}): RequestActivity => ({
  id: 'request-1',
  public_model: 'sp-cambo-chat',
  internal_model: 'provider/sp-cambo-chat',
  provider: 'Provider A',
  provider_slug: 'provider-a',
  route_version: 1,
  api_key_id: 'key-1',
  api_key_label: 'Production',
  api_key_prefix: 'sk-',
  state: 'settled',
  endpoint: '/v1/messages',
  started_at: '2026-08-22T10:00:00.000Z',
  finished_at: '2026-08-22T10:00:00.420Z',
  duration_ms: 420,
  input_tokens: 120,
  output_tokens: 45,
  cache_read_tokens: null,
  cache_write_tokens: null,
  reasoning_tokens: null,
  total_tokens: null,
  reserved_units: null,
  metered_units: '165',
  credit_charge: null,
  estimated: false,
  error_code: null,
  ...overrides
})

const summary: UsageSummary = {
  range: { from: '2026-08-21T10:00:00.000Z', to: '2026-08-22T10:00:00.000Z' },
  requests: 3,
  input_tokens: 240,
  output_tokens: 90,
  metered_units: '330',
  credit_charge: { minor: '0', currency: 'USD', exponent: 2 },
  buckets: [],
  by_model: [{
    public_model: 'sp-cambo-chat',
    requests: 3,
    metered_units: '330',
    credit_charge: { minor: '0', currency: 'USD', exponent: 2 }
  }]
}

const route = reactive<{ query: Record<string, string | string[] | undefined> }>({ query: {} })

const plane = {
  keys: [key({ id: 'key-1', label: 'Production' })] as ApiKeySummary[] | null,
  keyError: null as SpApiError | null,
  activity: [request()] as RequestActivity[]
}

const { listKeys, listActivity, usageSummary } = vi.hoisted(() => ({
  listKeys: vi.fn(),
  listActivity: vi.fn(),
  usageSummary: vi.fn()
}))

mockNuxtImport('useRoute', () => () => route)
mockNuxtImport('useSpApi', () => () => ({
  account: {
    apiKeys: listKeys,
    activity: listActivity,
    usageSummary
  }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  route.query = {}
  plane.keys = [key({ id: 'key-1', label: 'Production' })]
  plane.keyError = null
  plane.activity = [request()]

  listKeys.mockReset().mockImplementation(async () => {
    if (plane.keyError) {
      throw plane.keyError
    }

    return plane.keys ?? []
  })
  listActivity.mockReset().mockImplementation(async () => plane.activity)
  usageSummary.mockReset().mockResolvedValue(summary)

  clearNuxtData()
  clearNuxtState()
})

const settle = async () => {
  await nextTick()
  await nextTick()
  await new Promise(resolve => setTimeout(resolve, 0))
  await nextTick()
}

const mountUsage = async () => {
  const page = await mountSuspended(UsagePage)

  await settle()

  return page
}

interface UsageVm {
  keyFilter: string | undefined
}

describe('request-log API-key filtering', () => {
  it('validates a deep-linked owned key before sending it to activity', async () => {
    route.query = { key: 'key-1' }

    await mountUsage()

    expect(listActivity).toHaveBeenLastCalledWith(expect.objectContaining({ key_id: 'key-1' }))
  })

  it('does not send a stale, foreign, blank, or repeated query key', async () => {
    for (const query of [
      { key: 'not-owned' },
      { key: '' },
      { key: ['key-1', 'not-owned'] }
    ]) {
      route.query = query
      clearNuxtData()
      clearNuxtState()
      listActivity.mockClear()

      await mountUsage()

      expect(listActivity).toHaveBeenLastCalledWith(expect.objectContaining({ key_id: undefined }))
    }
  })

  it('updates activity only when a selected key is owned and clears back to unfiltered', async () => {
    const page = await mountUsage()
    const vm = page.vm as unknown as UsageVm

    vm.keyFilter = 'key-1'
    await settle()

    expect(listActivity).toHaveBeenLastCalledWith(expect.objectContaining({ key_id: 'key-1' }))

    vm.keyFilter = undefined
    await settle()

    expect(listActivity).toHaveBeenLastCalledWith(expect.objectContaining({ key_id: undefined }))
  })

  it('keeps account-wide summary requests free of key_id', async () => {
    route.query = { key: 'key-1' }

    await mountUsage()

    expect(usageSummary).toHaveBeenCalled()
    expect(usageSummary).not.toHaveBeenCalledWith(expect.objectContaining({ key_id: expect.anything() }))
  })

  it('explains a filtered empty request log without treating it as account-wide', async () => {
    route.query = { key: 'key-1' }
    plane.activity = []

    const page = await mountUsage()

    expect(page.text()).toContain('Once a request runs against Production')
    expect(page.text()).toContain('Account-wide metrics, chart and model totals above do not change with this filter.')
  })

  it('keeps the log honestly unfiltered when the owned-key list cannot load', async () => {
    plane.keyError = new SpApiError({
      code: 'server_error',
      status: 500,
      message: 'SP Cambo could not load your key list.'
    })
    route.query = { key: 'key-1' }

    const page = await mountUsage()

    expect(listActivity).toHaveBeenLastCalledWith(expect.objectContaining({ key_id: undefined }))
    expect(page.text()).toContain('The request log remains unfiltered')
    expect(page.text()).toContain('Retry keys')
  })
})

describe('request token metadata', () => {
  it('renders raw nonzero categories but not a legacy zero total', async () => {
    plane.activity = [request({
      input_tokens: null,
      output_tokens: 0,
      cache_read_tokens: 10,
      cache_write_tokens: 4,
      reasoning_tokens: 2,
      total_tokens: 0
    })]

    const page = await mountUsage()
    const text = page.text()

    expect(text).toContain('Input')
    expect(text).toContain('Output')
    expect(text).toContain('Cache read')
    expect(text).toContain('Cache write')
    expect(text).toContain('Reasoning')
    expect(text).not.toContain('Total')
    expect(text).toContain('raw request metadata')
  })

  it('renders a server-recorded nonzero total without calculating it', async () => {
    plane.activity = [request({ total_tokens: 165 })]

    const page = await mountUsage()

    expect(page.text()).toContain('Total')
    expect(page.text()).toContain('165')
  })
})
