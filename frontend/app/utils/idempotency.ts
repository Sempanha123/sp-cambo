/**
 * Idempotency keys for write requests that must not be replayed into duplicates.
 *
 * The control plane deduplicates certain writes — a reseller allocation, for one —
 * on a caller-supplied key. The key has to survive a retry after a dropped
 * response, which is the whole point: the same key returns the original result
 * instead of performing the operation twice.
 *
 * `crypto.randomUUID` is only defined in a secure context, so it cannot be relied
 * on alone. The fallback draws from `crypto.getRandomValues`, which is available
 * wherever the Web Crypto API is. Neither path uses `Math.random`, because a key
 * that collides between two resellers would let one see the other's transfer.
 */
export function newIdempotencyKey(prefix = 'sp'): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `${prefix}-${crypto.randomUUID()}`
  }

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const bytes = crypto.getRandomValues(new Uint8Array(16))
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')

    return `${prefix}-${hex}`
  }

  throw new Error('No cryptographic randomness is available to mint an idempotency key.')
}
