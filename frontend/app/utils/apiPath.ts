/**
 * Control-plane URL construction.
 *
 * The browser only ever addresses the Laravel control plane, and every dynamic
 * part of a path is an identifier that arrived from the API or from the address
 * bar. Both facts are enforced here rather than at each call site.
 */

/**
 * Encodes a value for use as a single path segment.
 *
 * Identifiers reach the API layer from route parameters, so an id is untrusted
 * input: without encoding, a value such as `../me` would silently address a
 * different endpoint than the caller named.
 */
export function apiSegment(value: string | number): string {
  return encodeURIComponent(String(value))
}

/**
 * Origin of the control plane, used for the Sanctum CSRF endpoint, which sits
 * outside the versioned API prefix.
 *
 * Returns an empty string for a same-origin relative base such as `/api/v1`,
 * which is what `$fetch` needs in order to stay on the current origin.
 */
export function controlPlaneOrigin(apiBaseUrl: string): string {
  const base = (apiBaseUrl ?? '').trim()

  try {
    return new URL(base).origin
  } catch {
    return base.replace(/\/api\/v1\/?$/, '')
  }
}
