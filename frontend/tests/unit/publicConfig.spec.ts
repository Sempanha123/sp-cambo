import { describe, expect, it } from 'vitest'
import {
  isAbsoluteHttpUrl,
  isLoopbackUrl,
  publicConfigProblems,
  resolveApiBaseUrl,
  resolvePublicEndpoints
} from '~/utils/publicConfig'
import { buildCliSnippets } from '~/utils/cliSnippets'

/**
 * These assertions stand in for a production build check.
 *
 * The failure they exist to prevent is silent: Nuxt populates a public runtime
 * property only from an exactly matching `NUXT_PUBLIC_*` name, so a deployment
 * template using `NUXT_PUBLIC_INFERENCE_ROOT` rather than
 * `NUXT_PUBLIC_INFERENCE_ROOT_URL` produces a successful build whose customer
 * setup instructions point at the customer's own machine.
 */

const FIT: Parameters<typeof publicConfigProblems>[0] = {
  apiBaseUrl: 'https://api.spcambo.example/api/v1',
  inferenceRootUrl: 'https://api.spcambo.example',
  siteUrl: 'https://spcambo.example'
}

describe('isLoopbackUrl', () => {
  it('recognises every development host that cannot serve a customer', () => {
    expect(isLoopbackUrl('http://localhost:3000')).toBe(true)
    expect(isLoopbackUrl('http://127.0.0.1:8000/api/v1')).toBe(true)
    expect(isLoopbackUrl('http://127.1.2.3:8787')).toBe(true)
    expect(isLoopbackUrl('http://[::1]:3000')).toBe(true)
    expect(isLoopbackUrl('http://0.0.0.0:3000')).toBe(true)
    expect(isLoopbackUrl('http://api.localhost')).toBe(true)
  })

  it('accepts a real public host', () => {
    expect(isLoopbackUrl('https://api.spcambo.example')).toBe(false)
  })

  it('does not treat an unparseable value as loopback', () => {
    // publicConfigProblems reports it as "not absolute" instead, which is the
    // accurate complaint; claiming it is loopback would mislead the operator.
    expect(isLoopbackUrl('not a url')).toBe(false)
  })
})

describe('isAbsoluteHttpUrl', () => {
  it('requires an http or https origin', () => {
    expect(isAbsoluteHttpUrl('https://api.spcambo.example')).toBe(true)
    expect(isAbsoluteHttpUrl('http://nginx/api/v1')).toBe(true)
    expect(isAbsoluteHttpUrl('/api/v1')).toBe(false)
    expect(isAbsoluteHttpUrl('ws://api.spcambo.example')).toBe(false)
    expect(isAbsoluteHttpUrl('')).toBe(false)
  })
})

describe('publicConfigProblems', () => {
  it('passes a fit production configuration', () => {
    expect(publicConfigProblems(FIT)).toEqual([])
  })

  it('rejects the loopback inference root a misnamed build argument leaves behind', () => {
    const problems = publicConfigProblems({ ...FIT, inferenceRootUrl: 'http://127.0.0.1:8787' })

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('inferenceRootUrl')
    expect(problems[0]).toContain('NUXT_PUBLIC_INFERENCE_ROOT_URL')
  })

  it('rejects a localhost canonical origin', () => {
    const problems = publicConfigProblems({ ...FIT, siteUrl: 'http://localhost:3000' })

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('NUXT_PUBLIC_SITE_URL')
  })

  it('rejects an empty value rather than accepting a blank endpoint', () => {
    expect(publicConfigProblems({ ...FIT, apiBaseUrl: '   ' })[0]).toContain('is empty')
  })

  it('rejects a relative control-plane base, which SSR cannot resolve', () => {
    expect(publicConfigProblems({ ...FIT, apiBaseUrl: '/api/v1' })[0])
      .toContain('not an absolute http(s) URL')
  })

  it('rejects an inference root that already ends in /v1', () => {
    // Anthropic clients append /v1/messages, so this would request /v1/v1/messages.
    const problems = publicConfigProblems({ ...FIT, inferenceRootUrl: 'https://api.spcambo.example/v1' })

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('ends in /v1')
  })

  it('reports every unfit endpoint at once', () => {
    expect(publicConfigProblems({
      apiBaseUrl: 'http://127.0.0.1:8000/api/v1',
      inferenceRootUrl: 'http://127.0.0.1:8787',
      siteUrl: 'http://localhost:3000'
    })).toHaveLength(3)
  })
})

describe('generated CLI snippets under a fit configuration', () => {
  it('advertises the configured public gateway and never the loopback default', () => {
    const built = buildCliSnippets({ inferenceRootUrl: FIT.inferenceRootUrl })

    // `codexShell` carries only the key: its endpoint lives in `codexConfig`,
    // so it is checked for loopback leakage but not for the endpoint itself.
    const withEndpoint = [
      built.claudeCodeShell,
      built.claudeCodePowerShell,
      built.codexConfig,
      built.curlMessages,
      built.pythonAnthropic,
      built.nodeAnthropic,
      built.openAiPython
    ]

    for (const snippet of withEndpoint) {
      expect(snippet).toContain(FIT.inferenceRootUrl)
    }

    for (const snippet of [...withEndpoint, built.codexShell]) {
      expect(snippet).not.toContain('127.0.0.1')
      expect(snippet).not.toContain('localhost')
      expect(snippet).not.toContain('8787')
    }
  })
})

describe('resolveApiBaseUrl', () => {
  const publicBaseUrl = 'https://api.spcambo.example/api/v1'
  const internalBaseUrl = 'http://nginx/api/v1'

  it('uses the private origin for server rendering', () => {
    expect(resolveApiBaseUrl({ publicBaseUrl, internalBaseUrl, server: true })).toBe(internalBaseUrl)
  })

  it('never uses the private origin in the browser, where it does not resolve', () => {
    expect(resolveApiBaseUrl({ publicBaseUrl, internalBaseUrl, server: false })).toBe(publicBaseUrl)
  })

  it('falls back to the public origin when no private one is configured', () => {
    expect(resolveApiBaseUrl({ publicBaseUrl, server: true })).toBe(publicBaseUrl)
    expect(resolveApiBaseUrl({ publicBaseUrl, internalBaseUrl: '  ', server: true })).toBe(publicBaseUrl)
    expect(resolveApiBaseUrl({ publicBaseUrl, internalBaseUrl: null, server: true })).toBe(publicBaseUrl)
  })
})

/**
 * What the release check actually reads.
 *
 * The check ran against `nuxt.options.runtimeConfig.public`, which still holds the
 * literals from `nuxt.config.ts` while the config is being resolved — Nuxt applies
 * `NUXT_PUBLIC_*` when Nitro boots. So a correctly configured production image was
 * reported as pointing at loopback and the build failed unconditionally: the guard
 * rejected every configuration rather than only the broken ones.
 *
 * Both halves are pinned here, because either one silently undoes the guard: read
 * the environment and a real misconfiguration must still be caught; read the
 * fallback and a correct deployment must still pass.
 */
describe('resolvePublicEndpoints', () => {
  /** The development literals in `nuxt.config.ts`, which must never ship. */
  const CONFIGURED = {
    apiBaseUrl: 'http://127.0.0.1:8000/api/v1',
    inferenceRootUrl: 'http://127.0.0.1:8787',
    siteUrl: 'http://localhost:3000'
  }

  const PRODUCTION_ENV = {
    NUXT_PUBLIC_API_BASE_URL: 'https://api.spcambo.example/api/v1',
    NUXT_PUBLIC_INFERENCE_ROOT_URL: 'https://api.spcambo.example',
    NUXT_PUBLIC_SITE_URL: 'https://spcambo.example'
  }

  it('prefers what the deployment set over the development default', () => {
    expect(resolvePublicEndpoints(PRODUCTION_ENV, CONFIGURED)).toEqual({
      apiBaseUrl: 'https://api.spcambo.example/api/v1',
      inferenceRootUrl: 'https://api.spcambo.example',
      siteUrl: 'https://spcambo.example'
    })
  })

  it('passes the release check for a correctly configured deployment', () => {
    expect(publicConfigProblems(resolvePublicEndpoints(PRODUCTION_ENV, CONFIGURED))).toEqual([])
  })

  it('still catches the misnamed variable the check exists for', () => {
    const problems = publicConfigProblems(resolvePublicEndpoints({
      ...PRODUCTION_ENV,
      // The near-miss: correct value, wrong name, so Nuxt would ignore it.
      NUXT_PUBLIC_INFERENCE_ROOT_URL: undefined,
      NUXT_PUBLIC_INFERENCE_ROOT: 'https://api.spcambo.example'
    }, CONFIGURED))

    expect(problems).toHaveLength(1)
    expect(problems[0]).toContain('inferenceRootUrl')
    expect(problems[0]).toContain('NUXT_PUBLIC_INFERENCE_ROOT_URL')
  })

  it('reports every unset endpoint rather than only the first', () => {
    expect(publicConfigProblems(resolvePublicEndpoints({}, CONFIGURED))).toHaveLength(3)
  })

  it('treats a blank variable as unset, so it is reported rather than passed as empty', () => {
    const resolved = resolvePublicEndpoints({ ...PRODUCTION_ENV, NUXT_PUBLIC_SITE_URL: '   ' }, CONFIGURED)

    expect(resolved.siteUrl).toBe(CONFIGURED.siteUrl)
    expect(publicConfigProblems(resolved)).toHaveLength(1)
  })

  it('trims a value a deployment template left padded', () => {
    const resolved = resolvePublicEndpoints({
      ...PRODUCTION_ENV,
      NUXT_PUBLIC_SITE_URL: ' https://spcambo.example '
    }, CONFIGURED)

    expect(resolved.siteUrl).toBe('https://spcambo.example')
  })

  it('yields an empty string, not "undefined", when neither source has a value', () => {
    // String(undefined) would produce a value that passes an emptiness check and
    // then fails as an unparseable URL, reporting the wrong problem.
    expect(resolvePublicEndpoints({}, {})).toEqual({
      apiBaseUrl: '',
      inferenceRootUrl: '',
      siteUrl: ''
    })
    expect(publicConfigProblems(resolvePublicEndpoints({}, {}))).toEqual([
      'apiBaseUrl is empty. Set NUXT_PUBLIC_API_BASE_URL.',
      'inferenceRootUrl is empty. Set NUXT_PUBLIC_INFERENCE_ROOT_URL.',
      'siteUrl is empty. Set NUXT_PUBLIC_SITE_URL.'
    ])
  })
})
