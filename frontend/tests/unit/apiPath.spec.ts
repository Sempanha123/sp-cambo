import { describe, expect, it } from 'vitest'
import { apiSegment, controlPlaneOrigin } from '~/utils/apiPath'

describe('apiSegment', () => {
  it('leaves an ordinary identifier untouched', () => {
    expect(apiSegment('01JB2YQ7X9KJ4W8ZC5M3T6V0AB')).toBe('01JB2YQ7X9KJ4W8ZC5M3T6V0AB')
    expect(apiSegment('ord_1234-5678')).toBe('ord_1234-5678')
  })

  it('encodes a slash so an id from the address bar cannot address another route', () => {
    // `/orders/${id}/payment` with this id would otherwise become
    // `/orders/../me/payment`, i.e. a request the caller never wrote.
    expect(apiSegment('../me')).toBe('..%2Fme')
    expect(apiSegment('a/b')).toBe('a%2Fb')
  })

  it('encodes characters that would otherwise change the request', () => {
    expect(apiSegment('a?b=1')).toBe('a%3Fb%3D1')
    expect(apiSegment('a#b')).toBe('a%23b')
    expect(apiSegment('a b')).toBe('a%20b')
    expect(apiSegment('a&b')).toBe('a%26b')
  })

  it('accepts a numeric id', () => {
    expect(apiSegment(42)).toBe('42')
  })

  it('never emits a character that could break out of the segment', () => {
    for (const value of ['../../me', 'x/y?z#w', '%2e%2e/x', 'a\\b']) {
      expect(apiSegment(value)).not.toMatch(/[/?#]/)
    }
  })
})

describe('controlPlaneOrigin', () => {
  it('reduces an absolute API base to its origin', () => {
    expect(controlPlaneOrigin('https://api.spcambo.example/api/v1')).toBe('https://api.spcambo.example')
    expect(controlPlaneOrigin('https://api.spcambo.example:8443/api/v1')).toBe('https://api.spcambo.example:8443')
  })

  it('stays on the current origin for a relative base', () => {
    expect(controlPlaneOrigin('/api/v1')).toBe('')
    expect(controlPlaneOrigin('/api/v1/')).toBe('')
  })

  it('tolerates whitespace and a missing value from the environment', () => {
    expect(controlPlaneOrigin('  https://api.spcambo.example/api/v1  ')).toBe('https://api.spcambo.example')
    expect(controlPlaneOrigin('')).toBe('')
  })

  it('does not carry the versioned prefix into the CSRF request', () => {
    for (const base of ['https://api.spcambo.example/api/v1', '/api/v1']) {
      expect(controlPlaneOrigin(base)).not.toContain('/api/v1')
    }
  })
})
