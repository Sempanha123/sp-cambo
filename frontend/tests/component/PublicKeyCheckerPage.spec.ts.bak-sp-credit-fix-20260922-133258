// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PublicApiKeyStatus } from '~/types/api'
import PublicKeyCheckerPage from '~/pages/public/key-checker.vue'

const { checkApiKey } = vi.hoisted(() => ({ checkApiKey: vi.fn() }))

mockNuxtImport('useSpApi', () => () => ({ checkApiKey }))

enableAutoUnmount(afterEach)

const activeResponse = (): PublicApiKeyStatus => ({
  valid: true,
  masked_key: 'sk-...1234',
  status: 'ACTIVE',
  package: 'Token Test, Credit Test',
  allowed_models: ['claude-coding'],
  created_at: '2026-08-24T10:00:00Z',
  expires_at: '2026-08-25T10:00:00Z',
  quota_remaining: '0',
  credit_remaining: { minor: '2000000', currency: 'USD', exponent: 6 },
  credit_balances: [{ minor: '2000000', currency: 'USD', exponent: 6 }],
  tokens_used: { input: '100', output: '20', total: '135' },
  total_spend: { minor: '125000', currency: 'USD', exponent: 6 },
  total_spend_by_currency: [{ minor: '125000', currency: 'USD', exponent: 6 }],
  last_used: '2026-08-24T10:30:00Z',
  active_requests: 0,
  server_time: '2026-08-24T10:30:01Z',
  recent_requests: [{
    request_id: 'request-1',
    time: '2026-08-24T10:30:00Z',
    finished_at: '2026-08-24T10:30:00.420Z',
    endpoint: '/v1/messages',
    model: 'claude-coding',
    state: 'settled',
    status: 'success',
    duration_ms: 420,
    input_tokens: '100',
    output_tokens: '20',
    total_tokens: '120',
    reserved_units: null,
    charge: { minor: '125000', currency: 'USD', exponent: 6 },
    error_code: null
  }]
})

beforeEach(() => {
  checkApiKey.mockReset().mockResolvedValue(activeResponse())
  clearNuxtData()
  clearNuxtState()
})

const settle = async () => {
  await nextTick()
  await new Promise(resolve => setTimeout(resolve, 0))
  await nextTick()
}

const submitKey = async (response: PublicApiKeyStatus) => {
  checkApiKey.mockResolvedValueOnce(response)
  const page = await mountSuspended(PublicKeyCheckerPage)
  const input = page.find('input[placeholder="sk-..."]')
  await input.setValue('sk-real-secret')
  await page.find('form').trigger('submit')
  await settle()
  return page
}

describe('public API key checker contract', () => {
  it('respects a verified disabled key instead of forcing valid=true in the browser', async () => {
    const page = await submitKey({
      ...activeResponse(),
      valid: false,
      status: 'DISABLED'
    })

    expect(checkApiKey).toHaveBeenCalledWith({ api_key: 'sk-real-secret' })
    expect(page.text()).toContain('Key is disabled')
    expect(page.text()).toContain('DISABLED')
    expect(page.text()).not.toContain('This key could not be verified')
  })

  it('renders zero quota as zero and keeps credit/spend as exact money objects', async () => {
    const page = await submitKey(activeResponse())
    const text = page.text()

    expect(text).toContain('Quota remaining')
    expect(text).toMatch(/Quota remaining\s*0/)
    expect(text).not.toMatch(/Quota remaining\s*Unlimited/)
    expect(text).toContain('$2.000000')
    expect(text).toContain('$0.125000')
    expect(text).toContain('claude-coding')
    expect(text).toContain('135')
  })

  it('rejects an impossible key format before sending the secret', async () => {
    const page = await mountSuspended(PublicKeyCheckerPage)
    await page.find('input[placeholder="sk-..."]').setValue('wrong-prefix-key')
    await page.find('form').trigger('submit')
    await settle()

    expect(checkApiKey).not.toHaveBeenCalled()
    expect(page.text()).toContain('SP Cambo API keys begin with “sk-”.')
  })

  it('renders safe per-model capability details returned by the checker', async () => {
    const page = await submitKey({
      ...activeResponse(),
      model_details: [{
        public_alias: 'claude-coding',
        display_name: 'Claude Coding',
        status: 'ACTIVE',
        context_tokens: 200_000,
        max_output_tokens: 64_000,
        capability_basis: 'PROVIDER_PUBLIC_SPEC',
        features: ['Streaming', 'Tools']
      }],
      limits: {
        requests_per_minute: 60,
        tokens_per_minute: 200_000,
        concurrency: 4,
        max_request_bytes: 1_048_576,
        max_output_tokens: 64_000
      }
    })

    const text = page.text()
    expect(text).toContain('Claude Coding')
    expect(text).toContain('200K')
    expect(text).toContain('64K')
    expect(text).toMatch(/Requests\/min\s*60/)
    expect(text).toContain('1 MB')
  })

  it('keeps live refresh enabled across more than one successful interval', async () => {
    const page = await submitKey(activeResponse())
    const liveSwitch = page.find('[role="switch"]')
    expect(liveSwitch.exists()).toBe(true)

    vi.useFakeTimers()
    try {
      await liveSwitch.trigger('click')
      await nextTick()
      expect(liveSwitch.attributes('aria-checked')).toBe('true')

      await vi.advanceTimersByTimeAsync(15_000)
      await nextTick()
      expect(checkApiKey).toHaveBeenCalledTimes(2)
      expect(liveSwitch.attributes('aria-checked')).toBe('true')

      await vi.advanceTimersByTimeAsync(15_000)
      await nextTick()
      expect(checkApiKey).toHaveBeenCalledTimes(3)
      expect(liveSwitch.attributes('aria-checked')).toBe('true')
    } finally {
      vi.useRealTimers()
    }
  })
})
