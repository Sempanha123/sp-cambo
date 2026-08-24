import { describe, expect, it } from 'vitest'
import { resolveSupportChannel, supportChannelProblem } from '~/utils/supportChannel'

/**
 * The support channel resolver.
 *
 * Two things are being protected here. The first is that nothing is invented: an
 * unconfigured deployment must resolve to `null` so the surfaces keep their present
 * copy rather than pointing at an address nobody reads. The second is that the value
 * is deployment configuration which ends up in an `href`, so only schemes that can
 * actually reach a human are accepted — a `javascript:` value in an environment file
 * must not become a link on a page a signed-in customer is reading.
 */

describe('a channel that is not configured', () => {
  it('resolves to nothing at all, for every shape of absent', () => {
    expect(resolveSupportChannel(undefined)).toBeNull()
    expect(resolveSupportChannel(null)).toBeNull()
    expect(resolveSupportChannel('')).toBeNull()
    expect(resolveSupportChannel('   ')).toBeNull()
  })

  it('is not a deployment problem, because most deployments publish none', () => {
    expect(supportChannelProblem(undefined)).toBeNull()
    expect(supportChannelProblem('')).toBeNull()
    expect(supportChannelProblem('  ')).toBeNull()
  })
})

describe('an email address', () => {
  it('accepts the bare form an operator is most likely to type', () => {
    expect(resolveSupportChannel('help@spcambo.example')).toEqual({
      href: 'mailto:help@spcambo.example',
      label: 'help@spcambo.example',
      kind: 'email'
    })
  })

  it('accepts an explicit mailto: without doubling the scheme', () => {
    // The bare-address pattern also matches `mailto:help@…`, so resolving in the
    // wrong order produces `mailto:mailto:help@…` — a link that opens nothing.
    expect(resolveSupportChannel('mailto:help@spcambo.example')).toEqual({
      href: 'mailto:help@spcambo.example',
      label: 'help@spcambo.example',
      kind: 'email'
    })
  })

  it('keeps a subject an operator used for routing, but does not show it', () => {
    const channel = resolveSupportChannel('mailto:help@spcambo.example?subject=SP%20Cambo')

    expect(channel?.href).toBe('mailto:help@spcambo.example?subject=SP%20Cambo')
    expect(channel?.label).toBe('help@spcambo.example')
  })

  it('trims padding a deployment template left behind', () => {
    expect(resolveSupportChannel('  help@spcambo.example  ')?.href).toBe('mailto:help@spcambo.example')
  })

  it('rejects a mailto: with nothing usable after it', () => {
    expect(resolveSupportChannel('mailto:')).toBeNull()
    expect(resolveSupportChannel('mailto:not-an-address')).toBeNull()
  })
})

describe('a page or chat handle', () => {
  it('accepts an https URL and reads as the host and path', () => {
    expect(resolveSupportChannel('https://t.me/spcambo')).toEqual({
      href: 'https://t.me/spcambo',
      label: 't.me/spcambo',
      kind: 'link'
    })
  })

  it('accepts plain http, which a self-hosted help desk may still be on', () => {
    expect(resolveSupportChannel('http://help.spcambo.example')?.kind).toBe('link')
  })

  it('does not show a trailing slash, which reads as a truncated address', () => {
    expect(resolveSupportChannel('https://spcambo.example/support/')?.label).toBe('spcambo.example/support')
    expect(resolveSupportChannel('https://spcambo.example/')?.label).toBe('spcambo.example')
  })

  it('keeps a query string, which some help desks use to preselect a queue', () => {
    const channel = resolveSupportChannel('https://help.spcambo.example/new?queue=api')

    expect(channel?.href).toBe('https://help.spcambo.example/new?queue=api')
    expect(channel?.label).toBe('help.spcambo.example/new?queue=api')
  })

  it('keeps a port, so a staging help desk is not silently addressed wrongly', () => {
    expect(resolveSupportChannel('https://help.spcambo.example:8443/desk')?.label)
      .toBe('help.spcambo.example:8443/desk')
  })
})

describe('a value that cannot reach anyone', () => {
  it('refuses a scheme that is not http, https or mailto', () => {
    // The whole point of the allow-list: this value comes from a deployment
    // environment file and is bound to an href.
    expect(resolveSupportChannel('javascript:alert(document.cookie)')).toBeNull()
    expect(resolveSupportChannel('data:text/html,<script>alert(1)</script>')).toBeNull()
    expect(resolveSupportChannel('file:///etc/passwd')).toBeNull()
    expect(resolveSupportChannel('ftp://spcambo.example')).toBeNull()
  })

  it('refuses a host with no scheme, rather than guessing https', () => {
    // Guessing would be a coin flip on an internal-only help desk served over http.
    expect(resolveSupportChannel('t.me/spcambo')).toBeNull()
    expect(resolveSupportChannel('spcambo.example/support')).toBeNull()
  })

  it('refuses a chat handle with no platform', () => {
    expect(resolveSupportChannel('@spcambo')).toBeNull()
    expect(resolveSupportChannel('Telegram: @spcambo')).toBeNull()
  })
})

/**
 * The strict release check.
 *
 * A channel that is set and unusable fails identically to one that was never set —
 * no link renders — so the operator would believe support was reachable while every
 * surface quietly went back to naming no channel at all.
 */
describe('supportChannelProblem', () => {
  it('says nothing about a channel that works', () => {
    expect(supportChannelProblem('help@spcambo.example')).toBeNull()
    expect(supportChannelProblem('https://t.me/spcambo')).toBeNull()
  })

  it('names the value and the variable for one that does not', () => {
    const problem = supportChannelProblem('t.me/spcambo')

    expect(problem).toContain('t.me/spcambo')
    expect(problem).toContain('NUXT_PUBLIC_SUPPORT_URL')
    expect(problem).toContain('leave it unset')
  })

  it('reports an unsafe scheme rather than letting it through as decoration', () => {
    expect(supportChannelProblem('javascript:alert(1)')).toContain('NUXT_PUBLIC_SUPPORT_URL')
  })
})
