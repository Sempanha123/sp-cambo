import { describe, expect, it } from 'vitest'
import {
  PASSWORD_MIN_LENGTH,
  meetsPasswordPolicy,
  passwordChecklist,
  passwordStrength
} from '~/utils/password'

/**
 * These helpers gate form submission, so they must mirror the control plane's
 * rules exactly. Being *stricter* than the server is a defect too: it blocks a
 * password Laravel would have accepted.
 *
 * Server rules mirrored here:
 *   registration          -> min:12
 *   change/reset/reseller -> Password::min(12)->letters()->mixedCase()->numbers()->symbols()
 *
 * None of the values below is a real credential.
 */
const STRONG = 'Str0ng!Passphrase'

describe('passwordChecklist levels', () => {
  it('mirrors registration with a length rule only', () => {
    const rules = passwordChecklist('a'.repeat(PASSWORD_MIN_LENGTH), 'basic')

    expect(rules).toHaveLength(1)
    expect(rules[0]?.met).toBe(true)
  })

  it('mirrors the four rules the control plane enforces elsewhere', () => {
    expect(passwordChecklist(STRONG)).toHaveLength(4)
  })

  it('defaults to the strong policy rather than the lenient one', () => {
    expect(passwordChecklist('short')).toEqual(passwordChecklist('short', 'strong'))
  })

  it('reports each unmet rule independently', () => {
    const rules = passwordChecklist('alllowercaseletters')

    expect(rules.map(rule => rule.met)).toEqual([true, false, false, false])
  })
})

describe('meetsPasswordPolicy length', () => {
  it(`rejects ${PASSWORD_MIN_LENGTH - 1} characters and accepts ${PASSWORD_MIN_LENGTH}`, () => {
    expect(meetsPasswordPolicy('Ab1!' + 'x'.repeat(PASSWORD_MIN_LENGTH - 5))).toBe(false)
    expect(meetsPasswordPolicy('Ab1!' + 'x'.repeat(PASSWORD_MIN_LENGTH - 4))).toBe(true)
  })

  it('counts an astral character once, as PHP mb_strlen does', () => {
    // 11 code points of padding plus one emoji: 12 characters, not 13 UTF-16 units.
    expect(meetsPasswordPolicy(`Ab1!xxxxxxx😀`)).toBe(true)
  })

  it('accepts a long password with no complexity under the registration policy', () => {
    expect(meetsPasswordPolicy('a'.repeat(20), 'basic')).toBe(true)
    expect(meetsPasswordPolicy('a'.repeat(20))).toBe(false)
  })
})

describe('meetsPasswordPolicy complexity', () => {
  it('accepts a password satisfying every rule', () => {
    expect(meetsPasswordPolicy(STRONG)).toBe(true)
  })

  it('requires both cases, not merely letters', () => {
    expect(meetsPasswordPolicy('str0ng!passphrase')).toBe(false)
    expect(meetsPasswordPolicy('STR0NG!PASSPHRASE')).toBe(false)
  })

  it('requires a digit', () => {
    expect(meetsPasswordPolicy('Strong!Passphrase')).toBe(false)
  })

  it('requires a symbol', () => {
    expect(meetsPasswordPolicy('Str0ngPassphrase')).toBe(false)
  })

  /**
   * Laravel's `symbols()` matches \p{P}, and `_` is \p{Pc} — connector
   * punctuation. Treating it as a word character would reject a password the
   * control plane accepts.
   */
  it('counts an underscore as a symbol, matching \\p{Pc}', () => {
    expect(meetsPasswordPolicy('Str0ng_Passphrase')).toBe(true)
  })

  it('counts a currency or maths sign as a symbol', () => {
    expect(meetsPasswordPolicy('Str0ng€Passphrase')).toBe(true)
    expect(meetsPasswordPolicy('Str0ng+Passphrase')).toBe(true)
  })

  it('accepts non-Latin letters as letters with mixed case', () => {
    expect(meetsPasswordPolicy('Καλημέρα1!κόσμε')).toBe(true)
  })
})

describe('passwordStrength', () => {
  it('shows nothing at all for an empty field', () => {
    expect(passwordStrength('')).toBeNull()
  })

  it('reports Strong exactly when the server would accept it', () => {
    const strength = passwordStrength(STRONG)

    expect(strength).toEqual({ value: 100, label: 'Strong', color: 'success' })
    expect(meetsPasswordPolicy(STRONG)).toBe(true)
  })

  it('never reports Strong for a password the server would reject', () => {
    for (const candidate of ['alllowercaseletters', 'Str0ngPassphrase', 'Ab1!', 'a'.repeat(30)]) {
      expect(passwordStrength(candidate)?.label).not.toBe('Strong')
    }
  })

  it('escalates Weak to Fair as more rules are met', () => {
    expect(passwordStrength('alllowercaseletters')?.label).toBe('Weak')
    expect(passwordStrength('Alllowercaseletters1')?.label).toBe('Fair')
  })

  it('keeps the bar visible even at the weakest reading', () => {
    expect(passwordStrength('a')?.value).toBeGreaterThan(0)
  })
})
