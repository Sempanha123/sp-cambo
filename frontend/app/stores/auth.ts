import { defineStore } from 'pinia'
import type { AuthenticatedUser, LoginInput, RegisterInput, SpErrorCode, GoogleCallbackInput, GoogleLinkCallbackInput } from '~/types/api'

/**
 * Browser session state for the SP Cambo control plane.
 *
 * This is the customer's *login* session. Customer inference API keys are a
 * separate credential type and are never stored here.
 */
export const useAuthStore = defineStore('auth', () => {
  const api = useSpApi()
  const token = useSessionToken()
  const cookieMode = useCookieSessionMode()
  const sessionExpiredAt = useSessionExpiredSignal()

  const user = ref<AuthenticatedUser | null>(null)
  const initialized = ref(false)
  const pending = ref(false)
  const verificationPending = ref(false)
  const errorMessage = ref<string | null>(null)

  const errorCode = ref<SpErrorCode | null>(null)
  const fieldErrors = ref<Record<string, string[]>>({})

  /** In cookie mode there is no readable credential, so `user` alone decides. */
  const authenticated = computed(() => user.value !== null && (cookieMode.value || token.value !== null))

  const resetErrors = () => {
    errorMessage.value = null
    errorCode.value = null
    fieldErrors.value = {}
  }

  const captureError = (cause: unknown) => {
    const error = toSpApiError(cause)

    errorMessage.value = error.message
    errorCode.value = error.code
    fieldErrors.value = error.errors

    return error
  }

  const clearSession = () => {
    user.value = null

    if (!cookieMode.value) {
      token.value = null
    }
  }

  /**
   * Install a freshly-issued authoritative session.
   *
   * Important for OAuth: app initialization can discover an expired *old*
   * credential while Google is returning to the callback page. That failure
   * raises sessionExpiredAt. A successful Google callback is newer authority
   * than that stale signal, so clear the signal before publishing the new user.
   */
  const applySession = (session: { user: AuthenticatedUser, token?: string | null }) => {
    sessionExpiredAt.value = 0
    user.value = session.user

    if (!cookieMode.value && session.token) {
      token.value = session.token
    }

    initialized.value = true
  }

  /** Loads the authenticated user once per app lifecycle. */
  const initialize = async () => {
    if (initialized.value) {
      return
    }

    initialized.value = true

    if (!cookieMode.value && !token.value) {
      return
    }

    try {
      const response = await api.auth.me()
      user.value = response.user
    } catch (cause) {
      const error = toSpApiError(cause)

      if (error.isSessionExpired) {
        clearSession()
        return
      }

      // Network and server faults are transient: keep the credential and allow
      // the next navigation to retry instead of silently signing the user out.
      initialized.value = false
    }
  }

  /** Re-reads the authoritative user record, e.g. after a profile change. */
  const refresh = async () => {
    if (!authenticated.value) {
      return
    }

    try {
      const response = await api.auth.me()
      user.value = response.user
    } catch (cause) {
      const error = toSpApiError(cause)

      if (error.isSessionExpired) {
        clearSession()
      }
    }
  }

  const setUser = (next: AuthenticatedUser) => {
    user.value = next
  }

  const sendRegistrationCode = async (email: string) => {
    verificationPending.value = true
    resetErrors()

    try {
      return await api.auth.sendRegistrationCode({ email: email.trim().toLowerCase() })
    } catch (cause) {
      captureError(cause)
      return null
    } finally {
      verificationPending.value = false
    }
  }

  const register = async (input: RegisterInput) => {
    pending.value = true
    resetErrors()

    try {
      applySession(await api.auth.register(input))

      // A referral captured before registration should be attached immediately
      // after the authoritative login session exists.
      await useReferralAttribution().claimIfPossible()

      return true
    } catch (cause) {
      captureError(cause)
      return false
    } finally {
      pending.value = false
    }
  }

  const login = async (input: LoginInput) => {
    pending.value = true
    resetErrors()

    try {
      applySession(await api.auth.login(input))
      await useReferralAttribution().claimIfPossible()

      return true
    } catch (cause) {
      captureError(cause)
      return false
    } finally {
      pending.value = false
    }
  }

  const logout = async () => {
    const hadCredential = cookieMode.value || token.value !== null

    try {
      if (hadCredential) {
        await api.auth.logout()
      }
    } catch {
      // The local session is cleared regardless: the credential may already have
      // been revoked or expired server-side.
    } finally {
      clearSession()
      sessionExpiredAt.value = 0
      initialized.value = true
      resetErrors()
    }
  }

  const loginWithGoogle = async () => {
    return false
  }

  const handleGoogleCallback = async (input: GoogleCallbackInput) => {
    pending.value = true
    resetErrors()

    try {
      const response = await api.google.callback(input)
      applySession(response)
      await useReferralAttribution().claimIfPossible()

      return true
    } catch (cause) {
      captureError(cause)
      return false
    } finally {
      pending.value = false
    }
  }

  const linkGoogleAccount = async (input: GoogleLinkCallbackInput) => {
    pending.value = true
    resetErrors()

    try {
      await api.google.link(input)
      return true
    } catch (cause) {
      captureError(cause)
      return false
    } finally {
      pending.value = false
    }
  }

  /** Called when the control plane rejects the credential mid-session. */
  const handleSessionExpired = () => {
    clearSession()
    initialized.value = true
  }

  return {
    user,
    token,
    initialized,
    pending,
    verificationPending,
    errorMessage,
    errorCode,
    fieldErrors,
    authenticated,
    sessionExpiredAt,
    initialize,
    refresh,
    applySession,
    setUser,
    sendRegistrationCode,
    register,
    login,
    logout,
    clearSession,
    handleSessionExpired,
    resetErrors,
    loginWithGoogle,
    handleGoogleCallback,
    linkGoogleAccount
  }
})
