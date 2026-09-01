// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { MoneyAmount, PublicModel, PublicModelCapabilities } from '~/types/commerce'
import ModelsPage from '~/pages/models.vue'

/**
 * The public model catalogue, mounted for real.
 *
 * This page is read before anyone buys, so the guarantee under test is that it
 * never states less than the meter charges and never more than the catalogue
 * says. Two failures matter more than the rest:
 *
 * 1. Every billed category has to appear. The control plane charges reasoning
 *    tokens at the output rate when a model publishes no reasoning rate of its
 *    own, so omitting that row reads as "reasoning is free" on exactly the
 *    models where thinking is the largest part of the bill.
 * 2. A protocol the catalogue does not mention must render as nothing, not as
 *    "unsupported". Absent means "not stated": the gateway gates each surface on
 *    the matching flag, and a guessed answer either sends a customer into a
 *    `model_unavailable` they could not predict or scares them off a surface
 *    that works.
 *
 * `tests/unit/format.spec.ts` covers the money formatting itself; this file
 * covers what the card is allowed to claim with it.
 */

const usd = (minor: string): MoneyAmount => ({ minor, currency: 'USD', exponent: 2 })

const capabilities = (overrides: Partial<PublicModelCapabilities> = {}): PublicModelCapabilities => ({
  streaming: true,
  tools: true,
  vision: false,
  reasoning: false,
  context_tokens: 200000,
  max_output_tokens: 8192,
  ...overrides
})

type CreditPricing = NonNullable<PublicModel['credit_pricing']>

const creditPricing = (overrides: Partial<CreditPricing> = {}): CreditPricing => ({
  input_per_million: usd('300'),
  output_per_million: usd('1500'),
  cache_read_per_million: null,
  cache_write_per_million: null,
  reasoning_per_million: null,
  ...overrides
})

const model = (overrides: Partial<PublicModel> & { public_alias: string }): PublicModel => ({
  display_name: `Model ${overrides.public_alias}`,
  family: 'standard',
  family_label: 'Standard',
  description: null,
  capabilities: capabilities(),
  credit_pricing: null,
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null
  },
  status: 'available',
  ...overrides
})

/** What the mocked control plane will answer with, set per test. */
const plane = {
  models: [] as PublicModel[]
}

const { listModels } = vi.hoisted(() => ({ listModels: vi.fn() }))

mockNuxtImport('useSpApi', () => () => ({
  catalog: { models: listModels }
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  plane.models = []

  listModels.mockReset().mockImplementation(async () => plane.models)

  /*
   * `useSpResource` keys into Nuxt's payload, which is shared for the whole test
   * file. Without clearing it, the second test would render the first test's
   * catalogue and pass for the wrong reason.
   */
  clearNuxtData()
  clearNuxtState()
})

const mountModels = async () => {
  const page = await mountSuspended(ModelsPage)

  await nextTick()
  await nextTick()

  return page
}

type Page = Awaited<ReturnType<typeof mountModels>>

/**
 * The credit pricing rows of the single rendered card, as label -> displayed value.
 *
 * A card renders two definition lists — capabilities and limits first, pricing
 * last — so the pricing one is the final `dl`. Returns `{}` when the model is not
 * credit priced, since then there is no pricing block to read at all.
 */
const pricingRows = (page: Page): Record<string, string> => {
  if (!page.text().includes('Credit pricing per million tokens')) {
    return {}
  }

  const pricing = page.findAll('article dl').at(-1)
  const rows: Record<string, string> = {}

  for (const row of pricing?.findAll('div') ?? []) {
    const label = row.find('dt')
    const value = row.find('dd')

    if (label.exists() && value.exists()) {
      rows[label.text()] = value.text()
    }
  }

  return rows
}

/** The badge whose label is exactly `label`, or undefined when none is rendered. */
const badge = (page: Page, label: string) =>
  page.findAll('span').find(candidate => candidate.text() === label)

describe('credit pricing disclosure', () => {
  it('states that reasoning is charged at the output rate when no reasoning rate is published', async () => {
    plane.models = [model({
      public_alias: 'sp-thinker',
      capabilities: capabilities({ reasoning: true }),
      credit_pricing: creditPricing({ reasoning_per_million: null })
    })]

    const page = await mountModels()

    expect(pricingRows(page)).toEqual({
      Input: '$3.00',
      Output: '$15.00',
      Reasoning: 'Output rate'
    })
    expect(page.text()).toContain('Reasoning tokens are charged at the output rate')
    expect(page.text()).toContain('They are not free')
  })

  it('shows the published reasoning rate as money, with no output-rate caveat', async () => {
    plane.models = [model({
      public_alias: 'sp-thinker',
      capabilities: capabilities({ reasoning: true }),
      credit_pricing: creditPricing({ reasoning_per_million: usd('900') })
    })]

    const page = await mountModels()

    expect(pricingRows(page)).toEqual({
      Input: '$3.00',
      Output: '$15.00',
      Reasoning: '$9.00'
    })
    expect(page.text()).not.toContain('They are not free')
  })

  it('omits the reasoning row for a model that cannot produce reasoning tokens', async () => {
    plane.models = [model({
      public_alias: 'sp-fast',
      capabilities: capabilities({ reasoning: false }),
      credit_pricing: creditPricing()
    })]

    const page = await mountModels()

    expect(pricingRows(page)).toEqual({
      Input: '$3.00',
      Output: '$15.00'
    })
  })

  it('lists cache rates only when the catalogue states them', async () => {
    plane.models = [model({
      public_alias: 'sp-cached',
      credit_pricing: creditPricing({
        cache_read_per_million: usd('30'),
        cache_write_per_million: usd('375')
      })
    })]

    const page = await mountModels()

    expect(pricingRows(page)).toEqual({
      'Input': '$3.00',
      'Output': '$15.00',
      'Cache read': '$0.30',
      'Cache write': '$3.75'
    })
  })

  it('points at packages rather than inventing a rate when a model is not credit priced', async () => {
    plane.models = [model({ public_alias: 'sp-package-only', credit_pricing: null })]

    const page = await mountModels()

    expect(page.text()).toContain('Sold through token packages rather than credit pricing')
    expect(page.text()).not.toContain('Credit pricing per million tokens')
  })
})

describe('protocol support', () => {
  it('lists every surface the catalogue states, chat completions included', async () => {
    plane.models = [model({
      public_alias: 'sp-fast',
      capabilities: capabilities({
        messages_api: true,
        responses_api: true,
        chat_completions_api: true
      })
    })]

    const page = await mountModels()

    for (const label of ['Messages API', 'Responses API', 'Chat Completions API']) {
      const rendered = badge(page, label)

      expect(rendered, `${label} badge`).toBeDefined()
      expect(rendered?.classes()).not.toContain('opacity-50')
    }
  })

  it('says nothing about a surface the catalogue does not state, while it states another', async () => {
    plane.models = [model({
      public_alias: 'sp-fast',
      capabilities: capabilities({ messages_api: true })
    })]

    const page = await mountModels()
    const text = page.text()

    expect(text).toContain('Messages API')
    expect(text).not.toContain('Responses API')
    expect(text).not.toContain('Chat Completions API')
    expect(text).not.toContain('states no inference protocol')
  })

  it('warns rather than falls silent when the catalogue states no protocol at all', async () => {
    plane.models = [model({ public_alias: 'sp-unstated', capabilities: capabilities() })]

    const page = await mountModels()
    const text = page.text()

    expect(text).toContain('This model states no inference protocol')
    expect(text).toContain('model_unavailable')
  })

  it('renders a stated-false surface as unsupported rather than dropping it', async () => {
    plane.models = [model({
      public_alias: 'sp-fast',
      capabilities: capabilities({ messages_api: true, chat_completions_api: false })
    })]

    const page = await mountModels()
    const supported = badge(page, 'Messages API')
    const stated = badge(page, 'Chat Completions API')

    expect(supported, 'Messages API badge').toBeDefined()
    expect(stated, 'Chat Completions API badge').toBeDefined()
    expect(supported?.classes()).not.toContain('opacity-50')
    expect(stated?.classes()).toContain('opacity-50')
  })
})
