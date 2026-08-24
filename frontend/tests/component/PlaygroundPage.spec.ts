// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PublicModel, PublicModelCapabilities } from '~/types/commerce'
import PlaygroundPage from '~/pages/dashboard/playground.vue'

/**
 * The playground, mounted for real.
 *
 * Its whole value is that a copied request works, so the two things under test are
 * the ones that would make it worthless:
 *
 * The first is that a protocol the alias does not state can never be the one the
 * page builds against. The control plane gates each surface on the matching
 * capability flag and refuses an unstated one with `model_unavailable`, so handing
 * a customer a snippet for it produces a 400 they have every reason to blame on
 * their account rather than on our page.
 *
 * The second is that nothing on the page is a figure SP Cambo has not measured.
 * There is no response, no token count and no charge until the request actually
 * settles, and inventing any of them here is worse than showing none.
 */

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

/** What the mocked control plane will answer with, set per test. */
const plane = {
  models: [] as PublicModel[]
}

const { listModels, listKeys } = vi.hoisted(() => ({
  listModels: vi.fn(),
  listKeys: vi.fn()
}))

mockNuxtImport('useSpApi', () => () => ({
  catalog: { models: listModels },
  account: { apiKeys: listKeys }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  plane.models = [model({ public_alias: 'sp-sonnet', capabilities: capabilities({ messages_api: true }) })]

  listModels.mockReset().mockImplementation(async () => plane.models)
  listKeys.mockReset().mockImplementation(async () => [])

  /*
   * `useSpResource` keys into Nuxt's payload, which is shared for the whole test
   * file, so without clearing it a later test renders an earlier test's catalogue.
   */
  clearNuxtData()
  clearNuxtState()
})

const mountPlayground = async () => {
  const page = await mountSuspended(PlaygroundPage)

  await nextTick()
  await nextTick()

  return page
}

describe('protocol selection', () => {
  it('builds against the surface the alias states rather than the page default', async () => {
    plane.models = [model({
      public_alias: 'sp-codex',
      capabilities: capabilities({ responses_api: true })
    })]

    const page = await mountPlayground()
    const text = page.text()

    expect(text).toContain('/v1/responses')
    expect(text).not.toContain('/v1/messages')
    expect(text).toContain('sp-codex')
  })

  it('refuses to build anything for an alias that states no protocol', async () => {
    plane.models = [model({ public_alias: 'sp-orphan' })]

    const page = await mountPlayground()
    const text = page.text()

    expect(text).toContain('This alias states no inference protocol')
    expect(text).toContain('model_unavailable')

    // No request section, so no snippet a customer could copy and be refused for.
    expect(text).not.toContain('What you will send')
    expect(text).not.toContain('curl ')
  })

  it('names the output ceiling field the selected protocol actually reads', async () => {
    plane.models = [model({
      public_alias: 'sp-chat',
      capabilities: capabilities({ chat_completions_api: true })
    })]

    const page = await mountPlayground()

    expect(page.text()).toContain('"max_completion_tokens": 256')
  })

  it('always sends max_tokens on Claude Messages, which has no server-side default', async () => {
    const page = await mountPlayground()

    expect(page.text()).toContain('"max_tokens": 256')
  })
})

describe('what the catalogue has not published', () => {
  it('shows a placeholder to replace rather than inventing an alias', async () => {
    plane.models = []

    const page = await mountPlayground()
    const text = page.text()

    expect(text).toContain('<your-model-alias>')
    expect(text).toContain('No alias is published')
  })

  it('does not offer streaming for an alias the catalogue says cannot stream', async () => {
    plane.models = [model({
      public_alias: 'sp-batch',
      capabilities: capabilities({ messages_api: true, streaming: false })
    })]

    const page = await mountPlayground()

    expect(page.text()).toContain('The catalogue states this alias does not stream')

    // Temperature first, streaming second. The streaming one must be unusable.
    const switches = page.findAll('[role="switch"]')

    expect(switches.length).toBe(2)
    expect(switches.at(-1)!.attributes()).toHaveProperty('disabled')
  })

  it('says a missing ceiling is missing instead of showing a number', async () => {
    plane.models = [model({
      public_alias: 'sp-sonnet',
      capabilities: capabilities({ messages_api: true, max_output_tokens: null, context_tokens: null })
    })]

    const page = await mountPlayground()
    const text = page.text()

    expect(text).toContain('No ceiling is published for this alias')
    expect(text).toContain('Not published')
  })
})

describe('figures it must not invent', () => {
  it('states no amount of money anywhere, because no charge exists until the request settles', async () => {
    const page = await mountPlayground()
    const text = page.text()

    expect(text).not.toContain('$')
    expect(text).not.toContain('៛')
    expect(text).toContain('will not estimate it')
  })

  it('says plainly that the request is not sent from the browser', async () => {
    const page = await mountPlayground()

    expect(page.text()).toContain('SP Cambo does not run this request for you')
  })

  it('never puts a credential in the request it shows', async () => {
    const page = await mountPlayground()
    const text = page.text()

    expect(text).toContain('sk-spc-your-key')
    expect(text).not.toMatch(/sk-spc-(?!your-key)/)
  })
})
