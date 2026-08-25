// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PublicModel, PublicModelCapabilities } from '~/types/commerce'
import PlaygroundPage from '~/pages/dashboard/playground.vue'

const capabilities = (overrides: Partial<PublicModelCapabilities> = {}): PublicModelCapabilities => ({
  streaming: true,
  tools: true,
  vision: false,
  reasoning: false,
  context_tokens: 200000,
  max_output_tokens: 8192,
  ...overrides
})

const model = (overrides: Partial<PublicModel> & { public_alias: string }): PublicModel => ({
  display_name: 'Test model',
  family: 'test',
  family_label: 'Test',
  description: null,
  capabilities: capabilities(),
  credit_pricing: null,
  limits: { requests_per_minute: null, tokens_per_minute: null, concurrency: null },
  status: 'available',
  ...overrides
})

const plane = { models: [] as PublicModel[] }
const { listModels, getPlaygroundQuota, runPlayground, redeemCode, activity } = vi.hoisted(() => ({
  listModels: vi.fn(),
  getPlaygroundQuota: vi.fn(),
  runPlayground: vi.fn(),
  redeemCode: vi.fn(),
  activity: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  catalog: { models: listModels },
  account: { playgroundQuota: getPlaygroundQuota, runPlayground, redeemCode, activity }
}))

enableAutoUnmount(afterEach)

const quota = (overrides = {}) => ({
  enabled: true,
  limit: 4096,
  remaining: 4096,
  reset_at: '2026-08-26T00:00:00+07:00',
  max_output_tokens: 2048,
  free_model_aliases: ['sp-sonnet'],
  redeem_token_remaining: 0,
  paid_token_remaining: 0,
  paid_credit_remaining: 0,
  fallback_available: false,
  default_model_alias: 'sp-sonnet',
  allow_model_switching: true,
  ...overrides
})

beforeEach(() => {
  plane.models = [model({ public_alias: 'sp-sonnet', capabilities: capabilities({ messages_api: true }) })]
  listModels.mockReset().mockImplementation(async () => plane.models)
  getPlaygroundQuota.mockReset().mockResolvedValue(quota())
  runPlayground.mockReset().mockResolvedValue({
    request_id: 'req-playground-test',
    message: 'Hello from the model',
    response: { content: [{ type: 'text', text: 'Hello from the model' }] },
    quota: quota({ remaining: 4000 })
  })
  redeemCode.mockReset()
  activity.mockReset().mockResolvedValue([])
  clearNuxtData()
  clearNuxtState()
})

const mountPlayground = async () => {
  const page = await mountSuspended(PlaygroundPage)
  await flushPromises()
  await nextTick()
  return page
}

describe('customer chat Playground', () => {
  it('renders as chat and automatically chooses a supported protocol', async () => {
    plane.models = [model({ public_alias: 'sp-codex', capabilities: capabilities({ responses_api: true }) })]
    getPlaygroundQuota.mockResolvedValue(quota({ free_model_aliases: ['sp-codex'], default_model_alias: 'sp-codex' }))

    const page = await mountPlayground()
    expect(page.text()).toContain('Start a conversation')
    expect(page.text()).toContain('Responses API')
    expect(page.text()).not.toContain('What you will send with your own key')
  })

  it('sends conversation history to the hosted Playground and renders normalized assistant text', async () => {
    const page = await mountPlayground()
    const textarea = page.find('textarea')
    await textarea.setValue('Hello')
    await textarea.trigger('keydown.enter')
    await nextTick()
    await nextTick()

    expect(runPlayground).toHaveBeenCalledWith(expect.objectContaining({
      model: 'sp-sonnet',
      protocol: 'messages',
      messages: [{ role: 'user', content: 'Hello' }]
    }))
    expect(page.text()).toContain('Hello from the model')
  })

  it('does not use legacy paid fallback after daily Playground quota is exhausted', async () => {
    getPlaygroundQuota.mockResolvedValue(quota({
      remaining: 0,
      redeem_token_remaining: 700,
      paid_token_remaining: 800,
      paid_credit_remaining: 900,
      fallback_available: true
    }))
    const page = await mountPlayground()
    const textarea = page.find('textarea')
    await textarea.setValue('Do not send this message')
    await textarea.trigger('keydown.enter')
    await nextTick()

    expect(runPlayground).not.toHaveBeenCalled()
    expect(page.get('button[type="button"][disabled]').text()).toContain('Send')
    expect(page.text()).toContain('Daily Playground quota exhausted')
    expect(page.text()).toContain('Paid and redeemed balances cannot fund Playground requests')
    expect(page.text()).toContain('fund customer API keys only')
    expect(page.text()).not.toContain('Continue chatting')
    expect(page.text()).not.toContain('Fallback tokens')
    expect(page.text()).not.toContain('Redeem + purchased tokens')
  })

  it('locks the model selector when admin disables customer model switching', async () => {
    plane.models = [
      model({ public_alias: 'sp-sonnet', capabilities: capabilities({ messages_api: true }) }),
      model({ public_alias: 'sp-other', capabilities: capabilities({ responses_api: true }) })
    ]
    getPlaygroundQuota.mockResolvedValue(quota({ allow_model_switching: false }))
    const page = await mountPlayground()

    expect(page.text()).toContain('sp-sonnet')
    expect(page.text()).not.toContain('sp-other')
  })
})
