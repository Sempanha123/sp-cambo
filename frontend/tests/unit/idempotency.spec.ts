import { afterEach, describe, expect, it, vi } from 'vitest'
import { newIdempotencyKey } from '~/utils/idempotency'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('newIdempotencyKey', () => {
  it('prefixes the key so a log line says what it deduplicates', () => {
    expect(newIdempotencyKey('alloc').startsWith('alloc-')).toBe(true)
    expect(newIdempotencyKey().startsWith('sp-')).toBe(true)
  })

  it('does not repeat itself, so two deliberate transfers stay two transfers', () => {
    const keys = new Set(Array.from({ length: 500 }, () => newIdempotencyKey('alloc')))

    expect(keys.size).toBe(500)
  })

  /**
   * A key that another caller could guess or reproduce would let them replay
   * someone else's transfer, so there has to be real entropy behind it — at least
   * the 122 bits of a v4 UUID.
   */
  it('carries enough entropy to be unguessable', () => {
    const random = newIdempotencyKey('alloc').slice('alloc-'.length).replace(/-/g, '')

    expect(random.length).toBeGreaterThanOrEqual(32)
    expect(random).toMatch(/^[0-9a-f]+$/)
  })

  it('falls back to getRandomValues where randomUUID is not exposed', () => {
    // `crypto.randomUUID` is only defined in a secure context.
    vi.stubGlobal('crypto', { getRandomValues: globalThis.crypto.getRandomValues.bind(globalThis.crypto) })

    expect(newIdempotencyKey('alloc')).toMatch(/^alloc-[0-9a-f]{32}$/)
  })

  /**
   * Silently substituting `Math.random` would produce a key that looks fine and
   * cannot be relied on to deduplicate, so the caller is told instead — the
   * allocation form turns this into an explanation rather than a broken submit.
   */
  it('refuses to invent a key without Web Crypto rather than using Math.random', () => {
    vi.stubGlobal('crypto', {})

    expect(() => newIdempotencyKey('alloc')).toThrow(/cryptographic randomness/i)
  })
})
