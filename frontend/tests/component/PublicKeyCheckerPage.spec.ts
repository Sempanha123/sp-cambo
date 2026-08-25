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
  masked_key: 'sk-spc-...1234',
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
  recent_requests: [{
    time: '2026-08-24T10:30:00Z',
    model: 'claude-coding',
    status: 'success',
    input_tokens: '100',
    output_tokens: '20',
    charge: { minor: '125000', currency: 'USD', exponent: 6 }
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
  const input = page.find('input[placeholder="spc_..."]')
  await input.setValue('sk-spc-real-secret')
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

    expect(checkApiKey).toHaveBeenCalledWith({ api_key: 'sk-spc-real-secret' })
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
})
