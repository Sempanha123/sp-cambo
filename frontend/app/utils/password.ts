/**
 * Password policy hints.
 *
 * The control plane is the only authority on whether a password is acceptable;
 * these helpers exist so a customer is told the rule *before* submitting rather
 * than discovering it in a 422. Each level therefore mirrors a specific server
 * rule set exactly — never a stricter or more lenient invention.
 *
 * `basic` mirrors registration (`min:12`).
 * `strong` mirrors password change, password reset and reseller-managed customer
 * creation (`Password::min(12)->letters()->mixedCase()->numbers()->symbols()`).
 *
 * The two differ because the backend rules differ; that inconsistency is
 * enforced by the current account policy.
 */

export type PasswordPolicyLevel = 'basic' | 'strong'

export const PASSWORD_MIN_LENGTH = 12

export interface PasswordRule {
  label: string
  met: boolean
}

/*
 * Each predicate mirrors the Unicode class Laravel's `Password` rule uses, so
 * the checklist can never be stricter than the server. Getting this wrong in
 * either direction is a real defect: stricter blocks a password the control
 * plane would accept, looser promises one it will reject.
 *
 * letters()   -> \p{L}
 * mixedCase() -> \p{Ll} and \p{Lu}
 * numbers()   -> \p{N}
 * symbols()   -> \p{Z}, \p{S} or \p{P}  (note: `_` is \p{Pc}, so it counts)
 */
const hasLetters = (value: string) => /\p{L}/u.test(value)
const hasMixedCase = (value: string) => /\p{Ll}/u.test(value) && /\p{Lu}/u.test(value)
const hasNumber = (value: string) => /\p{N}/u.test(value)
const hasSymbol = (value: string) => /[\p{Z}\p{S}\p{P}]/u.test(value)

/** Code points, matching PHP's `mb_strlen`, so astral characters count as one. */
const characterCount = (value: string) => [...value].length

/** The exact server rules for a level, each marked met or unmet. */
export function passwordChecklist(value: string, level: PasswordPolicyLevel = 'strong'): PasswordRule[] {
  const rules: PasswordRule[] = [
    { label: `At least ${PASSWORD_MIN_LENGTH} characters`, met: characterCount(value) >= PASSWORD_MIN_LENGTH }
  ]

  if (level === 'strong') {
    rules.push(
      { label: 'Upper and lower case letters', met: hasLetters(value) && hasMixedCase(value) },
      { label: 'At least one number', met: hasNumber(value) },
      { label: 'At least one symbol', met: hasSymbol(value) }
    )
  }

  return rules
}

/** True when every rule for the level is satisfied. */
export function meetsPasswordPolicy(value: string, level: PasswordPolicyLevel = 'strong'): boolean {
  return passwordChecklist(value, level).every(rule => rule.met)
}

export interface PasswordStrength {
  /** Share of the level's rules satisfied, as a percentage. */
  value: number
  label: 'Weak' | 'Fair' | 'Strong'
  color: 'error' | 'warning' | 'success'
}

/**
 * Progress towards the policy. Null for an empty field, so nothing is shown
 * before the customer has typed anything.
 *
 * "Strong" means *accepted by the server*, not a guess about entropy — reaching
 * 100% is exactly the condition under which the request will not be rejected for
 * password strength.
 */
export function passwordStrength(value: string, level: PasswordPolicyLevel = 'strong'): PasswordStrength | null {
  if (!value) {
    return null
  }

  const rules = passwordChecklist(value, level)
  const met = rules.filter(rule => rule.met).length
  const percent = Math.round((met / rules.length) * 100)

  if (met === rules.length) {
    return { value: 100, label: 'Strong', color: 'success' }
  }

  return met <= rules.length / 2
    ? { value: Math.max(percent, 10), label: 'Weak', color: 'error' }
    : { value: percent, label: 'Fair', color: 'warning' }
}
