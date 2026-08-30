export function useReferralAttribution() {
  const cookie = useCookie<string | null>('sp_referral', {
    sameSite: 'lax',
    path: '/',
    maxAge: 60 * 60 * 24 * 30
  })

  const normalize = (value: unknown): string | null => {
    if (typeof value !== 'string') return null
    const code = value.trim().toUpperCase()
    return /^[A-Z0-9_-]{4,32}$/.test(code) ? code : null
  }

  const capture = (value: unknown, days = 30) => {
    const code = normalize(value)
    if (!code) return null
    cookie.value = code
    // Nuxt's cookie maxAge is fixed at composable creation; the program default
    // is intentionally 30 days. Server-side eligibility remains authoritative.
    void days
    return code
  }

  const clear = () => {
    cookie.value = null
  }

  const claimIfPossible = async () => {
    const code = normalize(cookie.value)
    if (!code) return false
    const api = useSpApi()
    try {
      await api.referrals.claim(code)
      clear()
      return true
    } catch {
      // Invalid, self, already claimed, or first purchase already completed.
      // Clear stale attribution so it does not retry on every login.
      clear()
      return false
    }
  }

  return {
    code: computed(() => normalize(cookie.value)),
    capture,
    clear,
    claimIfPossible
  }
}
