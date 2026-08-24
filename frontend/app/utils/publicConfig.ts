/**
 * Build-time validation of the public endpoints baked into the browser bundle.
 *
 * Three of these values are embedded at build time and cannot be corrected at
 * runtime, so a naming mistake in a deployment template ships to customers:
 *
 * - `inferenceRootUrl` is copied verbatim into Claude Code and Codex setup
 *   snippets. If it retains the development loopback default, a customer who
 *   follows the documentation points their CLI at their own machine.
 * - `apiBaseUrl` is every control-plane call the browser makes.
 * - `siteUrl` becomes the server-rendered canonical link.
 *
 * Nuxt only populates a `runtimeConfig.public.*` property from an exactly
 * matching `NUXT_PUBLIC_*` variable; a near-miss such as
 * `NUXT_PUBLIC_INFERENCE_ROOT` is silently ignored and leaves the development
 * fallback in place. That failure is invisible in the built output, so it is
 * asserted at build time instead — see the `ready` hook in `nuxt.config.ts`, which
 * reads the environment through `resolvePublicEndpoints` rather than the resolved
 * config, for the reason explained there.
 *
 * These functions are pure and unit-tested in `tests/unit/publicConfig.spec.ts`.
 */

export interface PublicEndpointConfig {
  apiBaseUrl: string
  inferenceRootUrl: string
  siteUrl: string
}

/**
 * Each public endpoint and the single variable name Nuxt will populate it from.
 *
 * Nuxt derives the name mechanically from the property, so there is exactly one
 * spelling that works per endpoint and no near-miss is accepted.
 */
export const PUBLIC_ENDPOINT_ENV = {
  apiBaseUrl: 'NUXT_PUBLIC_API_BASE_URL',
  inferenceRootUrl: 'NUXT_PUBLIC_INFERENCE_ROOT_URL',
  siteUrl: 'NUXT_PUBLIC_SITE_URL'
} as const satisfies Record<keyof PublicEndpointConfig, string>

/**
 * Hosts that only ever address the machine the code is running on.
 *
 * `0.0.0.0` is included because it is a bind address rather than a destination:
 * as a URL it is not reachable from a customer's browser.
 */
const LOOPBACK_HOSTNAMES = new Set(['localhost', '0.0.0.0', '::1', '[::1]'])

function hostnameOf(value: string): string | null {
  try {
    return new URL(value.trim()).hostname.toLowerCase()
  } catch {
    return null
  }
}

/** True when the URL can only be reached from the machine that serves it. */
export function isLoopbackUrl(value: string): boolean {
  const hostname = hostnameOf(value)

  if (hostname === null) {
    return false
  }

  return LOOPBACK_HOSTNAMES.has(hostname)
    || hostname.startsWith('127.')
    || hostname.endsWith('.localhost')
}

/** True for an absolute `http`/`https` URL. A relative path is not one. */
export function isAbsoluteHttpUrl(value: string): boolean {
  try {
    const { protocol } = new URL(value.trim())

    return protocol === 'http:' || protocol === 'https:'
  } catch {
    return false
  }
}

/**
 * Chooses the control-plane origin for one request.
 *
 * During server rendering the frontend container may not be able to reach its own
 * public hostname: that requires public DNS, TLS and NAT hairpin from inside the
 * private network. A deployment therefore supplies `NUXT_INTERNAL_API_BASE_URL`
 * (for example `http://nginx/api/v1`) which is used *only* on the server. The
 * browser always uses the public base, because the internal origin does not
 * resolve outside the deployment network.
 *
 * Falls back to the public base when no internal origin is configured, which is
 * the correct behaviour for local development where both are reachable.
 */
export function resolveApiBaseUrl(input: {
  publicBaseUrl: string
  internalBaseUrl?: string | null
  server: boolean
}): string {
  const internal = input.internalBaseUrl?.trim()

  if (input.server && internal) {
    return internal
  }

  return input.publicBaseUrl
}

/**
 * The public endpoints a built deployment will actually serve.
 *
 * When the `ready` hook runs, `nuxt.options.runtimeConfig.public` still holds the
 * literals written in `nuxt.config.ts`: Nuxt applies `NUXT_PUBLIC_*` when Nitro
 * boots, not while the config is being resolved. A build-time assertion that reads
 * only that object therefore sees the loopback development defaults no matter what
 * the deployment supplied, and fails every single time — including for a correctly
 * configured production image, which is how this was found.
 *
 * So the environment is consulted first, exactly as Nitro will at runtime, and the
 * configured literal is the fallback. The failure the check exists to catch still
 * fails: a template that writes `NUXT_PUBLIC_INFERENCE_ROOT` leaves the correctly
 * named variable unset, so the loopback default is what gets checked and reported.
 */
export function resolvePublicEndpoints(
  env: Record<string, string | undefined>,
  configured: Record<string, unknown>
): PublicEndpointConfig {
  const resolve = (property: keyof PublicEndpointConfig): string => {
    const supplied = env[PUBLIC_ENDPOINT_ENV[property]]?.trim()

    if (supplied) {
      return supplied
    }

    const fallback = configured[property]

    return typeof fallback === 'string' ? fallback : ''
  }

  return {
    apiBaseUrl: resolve('apiBaseUrl'),
    inferenceRootUrl: resolve('inferenceRootUrl'),
    siteUrl: resolve('siteUrl')
  }
}

/**
 * Everything wrong with a set of public endpoints, as operator-readable lines.
 *
 * Returns an empty array when the configuration is fit to serve customers. The
 * checks are deliberately about reachability and contract rather than style: each
 * one corresponds to a way the deployment has actually been observed to break.
 */
export function publicConfigProblems(config: PublicEndpointConfig): string[] {
  const problems: string[] = []

  const entries: Array<{ name: keyof PublicEndpointConfig, value: string }> = [
    { name: 'apiBaseUrl', value: config.apiBaseUrl },
    { name: 'inferenceRootUrl', value: config.inferenceRootUrl },
    { name: 'siteUrl', value: config.siteUrl }
  ]

  for (const entry of entries) {
    const value = (entry.value ?? '').trim()
    const env = PUBLIC_ENDPOINT_ENV[entry.name]

    if (value === '') {
      problems.push(`${entry.name} is empty. Set ${env}.`)
      continue
    }

    if (!isAbsoluteHttpUrl(value)) {
      problems.push(`${entry.name} is not an absolute http(s) URL: ${value}. Set ${env}.`)
      continue
    }

    if (isLoopbackUrl(value)) {
      problems.push(
        `${entry.name} still points at the development loopback host: ${value}. `
        + `Set ${env} to the public address.`
      )
    }
  }

  /*
   * Claude Code and the Anthropic SDKs append `/v1/messages` themselves. A root
   * that already ends in `/v1` produces `/v1/v1/messages`, which reads as an
   * outage rather than a configuration error.
   */
  if (/\/v1\/?$/.test(config.inferenceRootUrl?.trim() ?? '')) {
    problems.push(
      'inferenceRootUrl must be the gateway root, not the OpenAI-compatible base: '
      + `${config.inferenceRootUrl} ends in /v1, and Anthropic clients append /v1/messages themselves.`
    )
  }

  return problems
}
