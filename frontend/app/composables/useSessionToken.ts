/**
 * Browser session credential.
 *
 * In `bearer` mode the control-plane token is held in a first-party cookie with
 * hardened flags and a bounded lifetime. The token must stay readable by
 * JavaScript in this mode because it is sent as an `Authorization` header.
 *
 * In `cookie` mode nothing is stored here: the Laravel Sanctum session cookie is
 * HttpOnly and the browser sends it automatically. Only enable that mode when the backend exposes the matching first-party Sanctum session flow.
 */
const SESSION_COOKIE = 'sp-cambo.session'

/** Bounded credential lifetime so an abandoned browser does not hold a token indefinitely. */
const SESSION_MAX_AGE_SECONDS = 60 * 60 * 12

export function useSessionToken() {
  const cookie = useCookie<string | null>(SESSION_COOKIE, {
    default: () => null,
    sameSite: 'strict',
    secure: !import.meta.dev,
    path: '/',
    maxAge: SESSION_MAX_AGE_SECONDS
  })

  // `useCookie()` refs created in separate composables are not a good place to
  // coordinate an OAuth hand-off. Keep one Nuxt state value as the live source
  // of truth and mirror writes to the persistent cookie.
  const live = useState<string | null>('sp.session-token', () => cookie.value)

  return computed<string | null>({
    get: () => live.value,
    set: (value) => {
      live.value = value
      cookie.value = value
    }
  })
}

/** True when the browser talks to the control plane over a first-party session cookie. */
export function useCookieSessionMode() {
  const config = useRuntimeConfig()

  return computed(() => config.public.sessionMode === 'cookie')
}

/**
 * Signal raised when the control plane rejects the current credential. The app
 * shell watches this to clear local state and route the customer to sign in.
 */
export function useSessionExpiredSignal() {
  return useState<number>('sp.session-expired-at', () => 0)
}
