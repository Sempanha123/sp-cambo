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
  const errorMessage = ref<string | null>(null)
  /**
   * Machine code behind `errorMessage`, kept so a surface can react to *which*
   * failure it was rather than matching on copy. `account_suspended` is the reason
   * this exists: it is the one sign-in failure the customer cannot fix themselves,
   * and the form offers the support channel for it — see `AuthCard`.
   */
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

  const applySession = (session: { user: AuthenticatedUser, token?: string | null }) => {
    user.value = session.user

    if (!cookieMode.value && session.token) {
      token.value = session.token
    }
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

  /**
   * Accepts an authoritative user record the control plane just returned, so a
   * successful profile update is reflected without a second round-trip. Only
   * ever called with a server response — never with locally composed values.
   */
  const setUser = (next: AuthenticatedUser) => {
    user.value = next
  }

  const register = async (input: RegisterInput) => {
    pending.value = true
    resetErrors()

    try {
      applySession(await api.auth.register(input))
      initialized.value = true

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
      initialized.value = true

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
      initialized.value = true
      resetErrors()
    }
  }

  /**
   * Initiate Google OAuth login flow
   */
  const loginWithGoogle = async () => {
    // The actual redirect is handled by the GoogleLoginButton component
    // This method is for programmatic use if needed
    return false
  }

  /**
   * Handle Google OAuth callback
   */
  const handleGoogleCallback = async (input: GoogleCallbackInput) => {
    pending.value = true
    resetErrors()

    try {
      const response = await api.google.callback(input)
      applySession(response)
      initialized.value = true

      return true
    } catch (cause) {
      captureError(cause)

      return false
    } finally {
      pending.value = false
    }
  }

  /**
   * Link Google account to existing user
   */
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
    errorMessage,
    errorCode,
    fieldErrors,
    authenticated,
    sessionExpiredAt,
    initialize,
    refresh,
    applySession,
    setUser,
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
