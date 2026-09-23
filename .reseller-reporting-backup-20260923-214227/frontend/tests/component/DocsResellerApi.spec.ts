// @vitest-environment nuxt
import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount } from '@vue/test-utils'
import { RESELLER_MANAGEMENT_SCOPES } from '~/types/reseller'
import ResellerApiDocs from '~/pages/docs/reseller-api.vue'

/**
 * The reseller API reference, mounted for real.
 *
 * A reference page is only worth having if a reader can act on it without being
 * misled, so the three things asserted here are the three ways this page could
 * mislead someone:
 *
 * It could name a host that is not this deployment's, sending an integration at
 * somebody else's control plane. It could print something that looks like a
 * credential. And it could imply a capability the API does not have — the reason
 * this page exists at all is that two grantable scopes and the whole notion of a
 * usage endpoint are absent from the shipped surface.
 */

enableAutoUnmount(afterEach)

const render = async () => {
  const page = await mountSuspended(ResellerApiDocs)

  return page.text()
}

describe('the host it tells you to call', () => {
  it('comes from runtime config rather than a literal', async () => {
    const text = await render()
    const { public: config } = useRuntimeConfig()

    expect(text).toContain(`${config.apiBaseUrl.replace(/\/+$/, '')}/reseller-management`)
    expect(text).not.toContain('spcambo.com')
  })
})

describe('credentials', () => {
  it('shows a placeholder and never a value that could be mistaken for a real key', async () => {
    const text = await render()

    expect(text).toContain('sk-spm-your-management-key')

    /*
     * The page names both prefixes many times in prose, which is correct — what it
     * must never contain is a prefix followed by a long run of key-like characters,
     * because that is what a real secret looks like. The placeholder breaks after
     * four ("your-"), and the one-time-reveal example is elided rather than filled
     * in, so neither trips this.
     */
    const secretShaped = /sk-sp[mc]-[A-Za-z0-9]{6,}/

    expect(text).not.toMatch(secretShaped)
  })
})

describe('capabilities it must not imply', () => {
  it('lists every scope the control plane grants', async () => {
    const text = await render()

    for (const scope of RESELLER_MANAGEMENT_SCOPES) {
      expect(text, scope).toContain(scope)
    }
  })

  it('states that the two unenforced scopes authorise nothing', async () => {
    const text = await render()

    expect(text.match(/no endpoint on this surface reads it yet/gi)).toHaveLength(2)
  })

  it('says there is no usage endpoint, rather than leaving it to be assumed', async () => {
    const text = await render()

    expect(text).toContain('No usage endpoint')
    expect(text).toContain('No way to read allocations back')
  })

  it('does not describe a rotate route for a key, because none exists', async () => {
    const text = await render()

    expect(text).not.toMatch(/\/rotate/)
  })
})

describe('the parts a reader would get wrong unprompted', () => {
  it('warns that omitting the alias list grants every published alias', async () => {
    const text = await render()

    expect(text).toContain('Omitting the field grants every published alias')
  })

  it('says an allocation inherits its source lot\'s expiry', async () => {
    const text = await render()

    expect(text).toContain('inherits its source\'s expiry')
  })

  it('distinguishes a safe idempotent retry from a conflicting reuse', async () => {
    const text = await render()

    expect(text).toContain('identical')
    expect(text).toContain('idempotency_conflict')
  })

  it('states the rate limit is per account and not per key', async () => {
    const text = await render()

    expect(text).toContain('per reseller account rather than per key')
  })
})
