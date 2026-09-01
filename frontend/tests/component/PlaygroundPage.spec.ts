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
const { getPlaygroundQuota, runPlayground, streamPlayground, redeemCode, activity } = vi.hoisted(() => ({
  getPlaygroundQuota: vi.fn(),
  runPlayground: vi.fn(),
  streamPlayground: vi.fn(),
  redeemCode: vi.fn(),
  activity: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  account: { playgroundQuota: getPlaygroundQuota, runPlayground, streamPlayground, redeemCode, activity }
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
  fallback_model_aliases: [],
  available_model_aliases: ['sp-sonnet'],
  available_models: plane.models,
  model_balances: [{ alias: 'sp-sonnet', free_eligible: true, balance_available: false, token_remaining: 0, credit_remaining: 0, next_expires_at: null }],
  default_model_alias: 'sp-sonnet',
  allow_model_switching: true,
  ...overrides
})

beforeEach(() => {
  plane.models = [model({ public_alias: 'sp-sonnet', capabilities: capabilities({ messages_api: true }) })]
  getPlaygroundQuota.mockReset().mockResolvedValue(quota())
  runPlayground.mockReset().mockResolvedValue({
    request_id: 'req-playground-test',
    message: 'Hello from the model',
    response: { content: [{ type: 'text', text: 'Hello from the model' }] },
    quota: quota({ remaining: 4000 })
  })
  streamPlayground.mockReset().mockImplementation(async (_input, handlers) => {
    handlers?.onMeta?.({ request_id: 'req-playground-test', protocol: 'messages', streaming: true })
    handlers?.onDelta?.('Hello ')
    handlers?.onDelta?.('from the model')
    handlers?.onDone?.({ request_id: 'req-playground-test', protocol: 'messages', event_count: 2, text_length: 20, response: { streamed: true } })
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
    getPlaygroundQuota.mockResolvedValue(quota({ free_model_aliases: ['sp-codex'], available_model_aliases: ['sp-codex'], model_balances: [{ alias: 'sp-codex', free_eligible: true, balance_available: false, token_remaining: 0, credit_remaining: 0, next_expires_at: null }], default_model_alias: 'sp-codex' }))

    const page = await mountPlayground()
    expect(page.text()).toContain('What can I help you build?')
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

    expect(streamPlayground).toHaveBeenCalledWith(expect.objectContaining({
      model: 'sp-sonnet',
      protocol: 'messages',
      messages: [{ role: 'user', content: 'Hello' }],
      funding_source: 'daily'
    }), expect.any(Object), expect.anything())
    expect(page.text()).toContain('Hello from the model')
  })

  it('uses the same 64K Auto ceiling for daily-free users', async () => {
    plane.models = [model({ public_alias: 'sp-sonnet', capabilities: capabilities({ messages_api: true, max_output_tokens: 65536 }) })]
    getPlaygroundQuota.mockResolvedValue(quota({ max_output_tokens: 65536 }))
    const page = await mountPlayground()
    const vm = page.vm as unknown as { composer: string, send: () => Promise<void> }
    vm.composer = 'Generate a long answer'
    await vm.send()

    expect(streamPlayground).toHaveBeenCalledWith(expect.objectContaining({
      max_output_tokens: 65536,
      funding_source: 'daily'
    }), expect.any(Object), expect.anything())
    expect(page.text()).toContain('Auto')
  })

  it('lets Auto use the larger ceiling for a purchased-only model', async () => {
    plane.models = [model({ public_alias: 'sp-premium', capabilities: capabilities({ messages_api: true, max_output_tokens: 65536 }) })]
    getPlaygroundQuota.mockResolvedValue(quota({
      remaining: 0,
      max_output_tokens: 65536,
      free_model_aliases: [],
      fallback_available: true,
      fallback_model_aliases: ['sp-premium'],
      available_model_aliases: ['sp-premium'],
      model_balances: [{ alias: 'sp-premium', free_eligible: false, balance_available: true, token_remaining: 500000, credit_remaining: 0, next_expires_at: null }],
      default_model_alias: 'sp-premium'
    }))
    const page = await mountPlayground()
    const vm = page.vm as unknown as { composer: string, send: () => Promise<void> }
    vm.composer = 'Generate a complete project file'
    await vm.send()

    expect(streamPlayground).toHaveBeenCalledWith(expect.objectContaining({
      max_output_tokens: 65536,
      funding_source: 'balance'
    }), expect.any(Object), expect.anything())
  })

  it('requires explicit opt-in before spending redeemed or purchased balance after daily quota is exhausted', async () => {
    getPlaygroundQuota.mockResolvedValue(quota({
      remaining: 0,
      redeem_token_remaining: 700,
      paid_token_remaining: 800,
      paid_credit_remaining: 900,
      fallback_available: true,
      fallback_model_aliases: ['sp-sonnet']
    }))
    const page = await mountPlayground()
    const textarea = page.find('textarea')
    await textarea.setValue('Continue after free quota')
    await textarea.trigger('keydown.enter')
    await nextTick()

    expect(streamPlayground).not.toHaveBeenCalled()
    expect(page.text()).toContain('Daily Playground quota exhausted')
    expect(page.text()).toContain('Continue with customer balance')
    expect(page.text()).toContain('asks before using purchased/redeemed balance')

    const continueButton = page.findAll('button').find(button => button.text().includes('Continue with customer balance'))
    expect(continueButton).toBeTruthy()
    await continueButton!.trigger('click')
    await textarea.trigger('keydown.enter')
    await flushPromises()

    expect(streamPlayground).toHaveBeenCalledWith(expect.objectContaining({
      model: 'sp-sonnet',
      funding_source: 'balance'
    }), expect.any(Object), expect.anything())
    expect(page.text()).toContain('Customer balance enabled')
  })

  it('offers a purchased-only model and explicitly spends its purchased balance when selected', async () => {
    plane.models = [
      model({ public_alias: 'sp-sonnet', display_name: 'Free model', capabilities: capabilities({ messages_api: true }) }),
      model({ public_alias: 'sp-premium', display_name: 'Purchased model', capabilities: capabilities({ messages_api: true }) })
    ]
    getPlaygroundQuota.mockResolvedValue(quota({
      fallback_available: true,
      fallback_model_aliases: ['sp-premium'],
      available_model_aliases: ['sp-sonnet', 'sp-premium'],
      model_balances: [
        { alias: 'sp-sonnet', free_eligible: true, balance_available: false, token_remaining: 0, credit_remaining: 0, next_expires_at: null },
        { alias: 'sp-premium', free_eligible: false, balance_available: true, token_remaining: 5000, credit_remaining: 0, next_expires_at: '2026-08-28T00:00:00Z' }
      ]
    }))

    const page = await mountPlayground()
    expect(page.text()).toContain('sp-premium')
    expect(page.text()).toContain('5,000')

    const vm = page.vm as unknown as { selectedAlias: string | undefined, composer: string, send: () => Promise<void> }
    vm.selectedAlias = 'sp-premium'
    vm.composer = 'Use my purchased model'
    await nextTick()
    await vm.send()

    expect(streamPlayground).toHaveBeenCalledWith(expect.objectContaining({
      model: 'sp-premium',
      funding_source: 'balance'
    }), expect.any(Object), expect.anything())
  })

  it('does not offer a free alias that has no customer chat protocol', async () => {
    plane.models = [
      model({ public_alias: 'sp-broken', display_name: 'Protocol-less model', capabilities: capabilities() }),
      model({ public_alias: 'sp-sonnet', display_name: 'Working model', capabilities: capabilities({ messages_api: true }) })
    ]
    getPlaygroundQuota.mockResolvedValue(quota({
      free_model_aliases: ['sp-broken', 'sp-sonnet'],
      available_model_aliases: ['sp-broken', 'sp-sonnet'],
      model_balances: [
        { alias: 'sp-broken', free_eligible: true, balance_available: false, token_remaining: 0, credit_remaining: 0, next_expires_at: null },
        { alias: 'sp-sonnet', free_eligible: true, balance_available: false, token_remaining: 0, credit_remaining: 0, next_expires_at: null }
      ],
      default_model_alias: 'sp-broken'
    }))

    const page = await mountPlayground()

    expect(page.text()).toContain('sp-sonnet')
    expect(page.text()).not.toContain('sp-broken')
    expect(page.text()).toContain('Anthropic Messages')
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
