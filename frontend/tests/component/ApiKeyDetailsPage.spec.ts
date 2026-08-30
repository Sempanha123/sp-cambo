// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import type { ApiKeyCreated, ApiKeyDetails, ApiKeyUsageSummary, RequestActivity } from '~/types/commerce'
import ApiKeyDetailsPage from '~/pages/dashboard/api-keys/[id].vue'

const KEY_ID = 'key-details-1'
const SECRET = 'sk-details-000000000000000000000000'

const details = (recopy = true): ApiKeyDetails => ({
  key: {
    id: KEY_ID,
    label: 'SP Cambo Default',
    prefix: 'sk-',
    last_four: 'abcd',
    status: 'ACTIVE',
    created_at: '2026-08-26T00:00:00Z',
    last_used_at: '2026-08-26T12:00:00Z',
    expires_at: '2026-09-26T00:00:00Z',
    allowed_model_aliases: ['claude-sonnet'],
    limits: {
      requests_per_minute: null,
      tokens_per_minute: null,
      concurrency: null,
      max_request_bytes: null,
      max_output_tokens: null
    },
    bound_entitlement_id: null,
    secret_recopy_available: recopy
  },
  balance_source: 'dedicated_and_legacy_entitlements',
  token_quota_remaining: '19950000',
  credit_balances: [],
  funding: [{
    id: 'lot-1',
    package_name: 'Claude 20M',
    source: 'ORDER',
    access_scope: 'API_KEY',
    dedicated_to_this_key: true,
    billing_mode: 'TOKEN_QUOTA',
    original_units: '20000000',
    remaining_units: '19950000',
    reserved_units: '0',
    unit_label: 'tokens',
    currency: 'USD',
    currency_exponent: 2,
    allowed_model_aliases: ['claude-sonnet'],
    activated_at: '2026-08-26T00:00:00Z',
    expires_at: '2026-09-26T00:00:00Z',
    days_remaining: 30
  }],
  server_time: '2026-08-27T00:00:00Z'
})

const usage: ApiKeyUsageSummary = {
  key: {
    ...details().key,
    limits: details().key.limits
  },
  range: { from: '2026-08-01T00:00:00Z', to: '2026-09-01T00:00:00Z' },
  requests: 2,
  input_tokens: 500,
  output_tokens: 250,
  metered_units: '750',
  credit_charge: { minor: '0', currency: 'USD', exponent: 2 },
  buckets: [],
  by_model: [{
    public_model: 'claude-sonnet',
    requests: 2,
    metered_units: '750',
    credit_charge: { minor: '0', currency: 'USD', exponent: 2 }
  }]
}

const activity: RequestActivity[] = [{
  id: 'req-1',
  public_model: 'claude-sonnet',
  internal_model: null,
  provider: null,
  provider_slug: null,
  route_version: null,
  api_key_id: KEY_ID,
  api_key_label: 'SP Cambo Default',
  api_key_prefix: 'sk-',
  state: 'settled',
  endpoint: '/v1/messages',
  started_at: '2026-08-26T12:00:00Z',
  finished_at: '2026-08-26T12:00:01Z',
  duration_ms: 1000,
  input_tokens: 500,
  output_tokens: 250,
  cache_read_tokens: null,
  cache_write_tokens: null,
  reasoning_tokens: null,
  total_tokens: 750,
  reserved_units: null,
  metered_units: '750',
  credit_charge: null,
  estimated: false,
  error_code: null
}]

const { getDetails, getUsage, getActivity, revealKey, rotateKey, toastAdd } = vi.hoisted(() => ({
  getDetails: vi.fn(),
  getUsage: vi.fn(),
  getActivity: vi.fn(),
  revealKey: vi.fn(),
  rotateKey: vi.fn(),
  toastAdd: vi.fn()
}))

mockNuxtImport('useRoute', () => () => ({ params: { id: KEY_ID } }))
mockNuxtImport('useSpApi', () => () => ({
  account: {
    apiKeyDetails: getDetails,
    apiKeyUsageSummary: getUsage,
    activity: getActivity,
    revealApiKey: revealKey,
    rotateApiKey: rotateKey
  }
}))
mockNuxtImport('useToast', () => () => ({ add: toastAdd }))

enableAutoUnmount(afterEach)

beforeEach(() => {
  getDetails.mockReset().mockResolvedValue(details())
  getUsage.mockReset().mockResolvedValue(usage)
  getActivity.mockReset().mockResolvedValue(activity)
  revealKey.mockReset().mockResolvedValue({ key: details().key, secret: SECRET } satisfies ApiKeyCreated)
  rotateKey.mockReset().mockResolvedValue({ key: details().key, secret: SECRET } satisfies ApiKeyCreated)
  toastAdd.mockReset()
  clearNuxtData()
  clearNuxtState()
})

const mountPage = async () => {
  const page = await mountSuspended(ApiKeyDetailsPage)
  await flushPromises()
  await nextTick()
  await nextTick()
  return page
}

describe('per-key details', () => {
  it('shows the key scope and account-backed entitlement instead of a fake key wallet', async () => {
    const page = await mountPage()

    expect(getDetails).toHaveBeenCalledWith(KEY_ID)
    expect(getUsage).toHaveBeenCalledWith(KEY_ID, { bucket: 'day' })
    expect(getActivity).not.toHaveBeenCalled()
    expect(page.text()).toContain('Dedicated key balance')
    expect(page.text()).toContain('19,950,000')
    expect(page.text()).toContain('Last 30 days on this key')

    const modelsButton = page.findAll('button').find(button => button.text().includes('Models & balance'))
    expect(modelsButton).toBeTruthy()
    await modelsButton!.trigger('click')
    await nextTick()
    expect(page.text()).toContain('Claude 20M')
    expect(page.text()).toContain('claude-sonnet')

    const activityButton = page.findAll('button').find(button => button.text().includes('Activity'))
    await activityButton!.trigger('click')
    await nextTick()
    expect(getActivity).toHaveBeenCalledWith({ key_id: KEY_ID, limit: 50 })
  })

  it('re-fetches a recoverable secret only when the owner asks to copy it', async () => {
    const page = await mountPage()
    const vm = page.vm as unknown as { copyKey: () => Promise<void>, secret: string | null }

    expect(page.html()).not.toContain(SECRET)
    await vm.copyKey()
    await nextTick()

    expect(revealKey).toHaveBeenCalledWith(KEY_ID)
    expect(vm.secret).toBe(SECRET)
    expect(document.body.textContent).toContain(SECRET)
  })

  it('offers a one-time replacement for a legacy hash-only key instead of pretending it can be reconstructed', async () => {
    getDetails.mockResolvedValue(details(false))
    const page = await mountPage()
    const vm = page.vm as unknown as { copyKey: () => Promise<void>, replaceOpen: boolean, replaceLegacyKey: () => Promise<void> }

    await vm.copyKey()
    expect(vm.replaceOpen).toBe(true)
    expect(revealKey).not.toHaveBeenCalled()

    await vm.replaceLegacyKey()
    expect(rotateKey).toHaveBeenCalledWith(KEY_ID)
  })
})
