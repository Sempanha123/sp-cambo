import type { WatchSource } from 'vue'
import type { SpErrorCode } from '~/types/api'

interface SerializedSpError {
  code: SpErrorCode
  status: number
  message: string
  errors: Record<string, string[]>
}

export interface UseSpResourceOptions {
  /** Fetch during SSR. Disable for authenticated data that needs a browser credential. */
  server?: boolean
  immediate?: boolean
  /** Do not block route navigation while the client fetch is running. */
  lazy?: boolean
  watch?: Array<WatchSource<unknown> | object>
}

/**
 * Async control-plane resource with first-class "not available yet" handling.
 *
 * The frontend must never fabricate commercial data. When an endpoint is missing
 * or unreachable, `unavailable` becomes true so the page can say so plainly
 * instead of rendering invented models, prices or balances.
 *
 * The error is stored in Nuxt state as a serialisable snapshot so an SSR failure
 * survives hydration instead of silently becoming an empty state.
 */
export async function useSpResource<T>(
  key: string,
  fetcher: () => Promise<T>,
  options: UseSpResourceOptions = {}
) {
  const errorState = useState<SerializedSpError | null>(`sp-resource-error:${key}`, () => null)

  // IMPORTANT: `useAsyncData()` is a promise-like AsyncData handle. Awaiting an
  // idle handle (`immediate: false`) can suspend a client-side route forever:
  // the page's `onMounted()` hook is supposed to call `refresh()`, but mounting
  // cannot happen while setup is waiting for an execution that was explicitly
  // disabled. Keep idle resources non-blocking, while preserving the existing
  // awaited behaviour for normal/SSR resources.
  const asyncDataHandle = useAsyncData<T | null>(
    key,
    async () => {
      errorState.value = null

      try {
        return await fetcher()
      } catch (cause) {
        const spError = toSpApiError(cause)

        errorState.value = {
          code: spError.code,
          status: spError.status,
          message: spError.message,
          errors: spError.errors
        }

        return null
      }
    },
    {
      server: options.server ?? true,
      immediate: options.immediate ?? true,
      lazy: options.lazy ?? false,
      watch: options.watch,
      default: () => null
    }
  )

  // `lazy: true` has the same navigation contract as an idle resource: the
  // destination page must render immediately while the request resolves in the
  // background. Awaiting a lazy handle keeps Nuxt's previous route mounted,
  // which makes the URL/sidebar change while the old page remains visible.
  // On the browser, authenticated dashboard resources must never hold a route
  // transition open. This matters especially on Windows local development where a
  // long-lived Playground stream can occupy the single PHP `artisan serve` worker.
  // The destination page should render its loading state immediately, then fill in
  // as soon as the control-plane request can run. SSR/public resources keep the
  // original awaited behaviour.
  const nonBlockingClientResource = import.meta.client && options.server === false
  const asyncData = nonBlockingClientResource || options.immediate === false || options.lazy === true
    ? asyncDataHandle
    : await asyncDataHandle

  const error = computed(() => {
    const snapshot = errorState.value

    if (!snapshot) {
      return null
    }

    return new SpApiError(snapshot)
  })

  const loading = computed(() => asyncData.status.value === 'pending')
  const unavailable = computed(() => error.value?.isUnavailable ?? false)

  /**
   * The endpoint exists and answered, and the answer is "no".
   *
   * Covers both 403 codes the control plane returns — `forbidden` (missing
   * permission) and `account_suspended` (the account itself is barred). Kept
   * separate from `failed` because neither is a fault and neither can be retried
   * away; offering a retry button would be a lie. `error.code` distinguishes them
   * for copy.
   */
  const forbidden = computed(() => error.value?.code === 'forbidden' || error.value?.code === 'account_suspended')
  const failed = computed(() => error.value !== null && !unavailable.value && !forbidden.value)

  /** True on the very first load, when there is nothing to show yet. */
  const initialLoading = computed(() => loading.value && asyncData.data.value === null)

  const isEmpty = computed(() => {
    const value = asyncData.data.value

    if (value === null || value === undefined) {
      return false
    }

    return Array.isArray(value) && value.length === 0
  })

  return {
    data: asyncData.data,
    status: asyncData.status,
    refresh: asyncData.refresh,
    clear: asyncData.clear,
    error,
    loading,
    initialLoading,
    unavailable,
    forbidden,
    failed,
    isEmpty
  }
}
