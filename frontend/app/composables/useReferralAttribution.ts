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

  /**
   * Attach the captured referral once an authenticated account exists.
   *
   * Only terminal attribution failures should consume the cookie. A stale login
   * session, network outage, rate limit, or temporary server error must keep the
   * code so the user can complete Google OAuth/login and retry safely.
   */
  const claimIfPossible = async () => {
    const code = normalize(cookie.value)
    if (!code) return false

    const api = useSpApi()

    try {
      await api.referrals.claim(code)
      clear()
      return true
    } catch (cause) {
      const error = toSpApiError(cause)

      // Validation/conflict means the server has authoritatively decided that
      // this code cannot be attached (invalid, self-referral, already attached,
      // or first purchase already completed). Do not retry those forever.
      if (error.isValidation || error.isConflict) {
        clear()
      }

      // Keep attribution for 401/419, 429, network failures and 5xx responses.
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
