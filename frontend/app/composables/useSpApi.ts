import type {
  ApiEnvelope,
  AuthResponse,
  AuthenticatedUser,
  GoogleCallbackInput,
  GoogleLinkCallbackInput,
  HealthResponse,
  LoginInput,
  PasswordChangeInput,
  PasswordResetInput,
  RegisterInput,
  SessionSummary,
  PublicApiKeyStatus
} from '~/types/api'
import type {
  AdminAuditLog,
  AdminAccessApiKey,
  AdminAccessModelAlias,
  AdminAccessApiKeyCreated,
  AdminAccessEntitlement,
  AdminCustomerAccess,
  AdminUsageRequest,
  AdminModelAlias,
  AdminOperationsOverview,
  AdminRecoveryAction,
  AdminRecoveryResponse,
  AdminReconciliationReservation,
  AdminOverview,
  AdminPackage,
  AdminPackageInput,
  AdminPromotion,
  AdminPromotionInput,
  AdminRedeemCode,
  AdminRedeemCodeInput,
  AdminRedeemCodeUpdateInput,
  AdminPlaygroundSettings,
  AdminProvider,
  AdminProviderModel,
  AdminProviderAlias,
  AdminSystemHealth,
  AdminTelegramStoreOverview,
  ModelAliasPricingInput,
  PackageProfitability,
  ProviderActiveConnectionUpdateInput,
  ProviderConnectionProbeResult,
  ProviderConnectionRevision,
  ProviderConnectionRevisionInput,
  ProviderConnectionRevisionUpdateInput,
  ProviderConnectionStatusUpdateInput,
  ProviderModelInput,
  ProviderAliasInput,
  DiscoveredProviderModel,
  ProviderModelImportResult
} from '~/types/admin'
import type {
  ResellerAllocation,
  ResellerAllocationInput,
  ResellerCustomer,
  ResellerCustomerInput,
  ResellerCustomerKey,
  ResellerCustomerKeyCreated,
  ResellerCustomerStatusUpdateInput,
  ResellerManagementKey,
  ResellerManagementKeyCreated,
  ResellerManagementScope
} from '~/types/reseller'
import type {
  ReferralDashboard,
  ReferralResolution,
  AdminReferralOverview,
  AdminReferralSettings
} from '~/types/referrals'
import type {
  ApiKeyCreated,
  ApiKeyDetails, ApiKeyUsageSummary,
  ApiKeyStatusReport,
  ApiKeySummary,
  BalanceSummary,
  EntitlementLot,
  ExternalIdentity,
  Order,
  PaymentAttempt,
  PromotionPreview,
  PublicModel,
  PublicPackage,
  RequestActivity,
  SystemStatus,
  UsageSummary,
  TelegramAccountStatus,
  TelegramLinkToken,
  PlaygroundQuota,
  PlaygroundChat,
  PlaygroundChatSummary
} from '~/types/commerce'

interface SpRequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: Record<string, unknown>
  query?: Record<string, string | number | boolean | undefined>
  headers?: Record<string, string>
  /**
   * True for list/collection routes. A 404 there means the control plane has not
   * shipped the endpoint, not that a specific record is missing.
   */
  collection?: boolean
  /** Skip the credential for genuinely public routes. */
  anonymous?: boolean
  /** Fail visibly instead of leaving a navigation/loading state hanging forever. */
  timeout?: number
}

/**
 * Single entry point for every browser call to the SP Cambo control plane.
 *
 * The browser only ever talks to the Laravel control plane (`/api/v1`). It never
 * calls the inference gateway or OmniRoute, and it holds no provider secret.
 *
 * Server rendering may address the same control plane through a private origin
 * (`NUXT_INTERNAL_API_BASE_URL`) so a public page does not depend on the container
 * being able to reach its own public hostname. See `resolveApiBaseUrl`.
 *
 * Every group below is implemented server-side. A route that nonetheless answers
 * 404 on a collection is reported as `endpoint_unavailable`, which consuming pages
 * render as an honest unavailable state rather than placeholder commercial data —
 * so a deployment running an older control plane degrades instead of inventing
 * prices, balances or usage.
 */
export function useSpApi() {
  const config = useRuntimeConfig()
  const token = useSessionToken()
  const cookieMode = useCookieSessionMode()
  const sessionExpiredAt = useSessionExpiredSignal()

  const baseURL = resolveApiBaseUrl({
    publicBaseUrl: config.public.apiBaseUrl,
    // Private runtime config exists only on the server; undefined in the browser.
    internalBaseUrl: import.meta.server ? (config.internalApiBaseUrl as string | undefined) : undefined,
    server: import.meta.server
  })

  /**
   * Origin of the control plane, used for the Sanctum CSRF endpoint.
   *
   * Derived from the public base rather than the resolved one: the CSRF cookie is
   * only ever fetched by a browser, and it must be scoped to the host the browser
   * will send it back to.
   */
  const csrfOrigin = computed(() => controlPlaneOrigin(config.public.apiBaseUrl))

  const csrfReady = useState<boolean>('sp.csrf-ready', () => false)

  /** Cookie-session mode requires a CSRF cookie before the first stateful request. */
  const ensureCsrfCookie = async () => {
    if (!cookieMode.value || csrfReady.value || import.meta.server) {
      return
    }

    try {
      await $fetch('/sanctum/csrf-cookie', {
        baseURL: csrfOrigin.value,
        credentials: 'include'
      })
      csrfReady.value = true
    } catch {
      // Leave csrfReady false so the next stateful request retries.
    }
  }

  const request = async <T>(path: string, options: SpRequestOptions = {}): Promise<T> => {
    const method = options.method ?? 'GET'
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...options.headers
    }

    if (cookieMode.value) {
      if (method !== 'GET') {
        await ensureCsrfCookie()
      }

      const xsrf = useCookie<string | null>('XSRF-TOKEN', { default: () => null }).value

      if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf
      }
    } else if (!options.anonymous && token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    try {
      const response = await $fetch<ApiEnvelope<T>>(path, {
        baseURL,
        method,
        headers,
        body: options.body,
        query: options.query,
        credentials: cookieMode.value ? 'include' : 'same-origin',
        retry: 0,
        timeout: options.timeout
      })

      return response.data
    } catch (error) {
      const spError = toSpApiError(error, options.collection ?? false)

      if (cookieMode.value && spError.code === 'csrf_token_mismatch') {
        // The browser may retain `sp.csrf-ready` after Laravel's session/XSRF
        // cookies expire or are cleared. Force the next write to bootstrap a
        // fresh, matching CSRF cookie and session pair.
        csrfReady.value = false
      }

      // Only treat a rejection as an expired session when a credential was sent.
      if (spError.isSessionExpired && !options.anonymous && (cookieMode.value || token.value)) {
        sessionExpiredAt.value = Date.now()
      }

      throw spError
    }
  }


  const streamPlayground = async (
    input: {
      model: string
      protocol: 'messages' | 'responses' | 'chat_completions'
      system_prompt?: string | null
      prompt?: string | null
      messages?: Array<{ role: 'user' | 'assistant', content: string }>
      max_output_tokens: number
      temperature?: number | null
      funding_source?: 'daily' | 'balance'
    },
    handlers: {
      onMeta?: (data: { request_id?: string, protocol?: string, streaming?: boolean }) => void
      onDelta?: (text: string) => void
      onDone?: (data: { request_id?: string, protocol?: string, event_count?: number, text_length?: number, finish_reason?: string | null, response?: unknown }) => void
    } = {},
    signal?: AbortSignal
  ): Promise<void> => {
    const headers: Record<string, string> = {
      Accept: 'text/event-stream',
      'Content-Type': 'application/json'
    }

    if (cookieMode.value) {
      await ensureCsrfCookie()
      const xsrf = useCookie<string | null>('XSRF-TOKEN', { default: () => null }).value
      if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
    } else if (token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    const url = `${String(baseURL).replace(/\/+$/, '')}/me/playground/stream`
    let response: Response
    try {
      response = await fetch(url, {
        method: 'POST',
        headers,
        body: JSON.stringify(input),
        credentials: cookieMode.value ? 'include' : 'same-origin',
        signal
      })
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') throw error
      throw toSpApiError({ status: 0, data: { code: 'network_unreachable' } })
    }

    if (!response.ok) {
      let payload: Record<string, unknown> = {}
      try { payload = await response.json() as Record<string, unknown> } catch { /* non-JSON failure */ }
      const spError = toSpApiError({ status: response.status, data: payload })
      if (cookieMode.value && spError.code === 'csrf_token_mismatch') csrfReady.value = false
      if (spError.isSessionExpired && (cookieMode.value || token.value)) sessionExpiredAt.value = Date.now()
      throw spError
    }

    if (!response.body) {
      throw toSpApiError({ status: 503, data: { code: 'playground_unavailable', message: 'The Playground stream did not return a readable response.' } })
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''

    const handleFrame = (frame: string) => {
      let event = 'message'
      const dataLines: string[] = []
      for (const rawLine of frame.replaceAll('\r\n', '\n').split('\n')) {
        if (rawLine.startsWith('event:')) event = rawLine.slice(6).trim()
        else if (rawLine.startsWith('data:')) dataLines.push(rawLine.slice(5).trimStart())
      }
      if (!dataLines.length) return

      let data: Record<string, unknown>
      try { data = JSON.parse(dataLines.join('\n')) as Record<string, unknown> } catch { return }

      if (event === 'meta') {
        handlers.onMeta?.(data as { request_id?: string, protocol?: string, streaming?: boolean })
        return
      }
      if (event === 'delta') {
        if (typeof data.text === 'string' && data.text !== '') handlers.onDelta?.(data.text)
        return
      }
      if (event === 'done') {
        handlers.onDone?.(data as { request_id?: string, protocol?: string, event_count?: number, text_length?: number, finish_reason?: string | null, response?: unknown })
        return
      }
      if (event === 'error') {
        const code = typeof data.code === 'string' ? data.code : 'playground_run_failed'
        const message = typeof data.message === 'string' ? data.message : 'The Playground stream was interrupted.'
        throw toSpApiError({ status: 502, data: { code, message } })
      }
    }

    const drainFrames = (flush = false) => {
      while (true) {
        const normalized = buffer.replaceAll('\r\n', '\n')
        const boundary = normalized.indexOf('\n\n')
        if (boundary === -1) break
        const frame = normalized.slice(0, boundary + 2)
        const consumedNormalized = boundary + 2

        // CRLF is two bytes for each newline pair while the normalized copy uses
        // one. Rebuild the remainder from the normalized representation because
        // SSE payloads are UTF-8 text and the delimiter itself carries no data.
        buffer = normalized.slice(consumedNormalized)
        handleFrame(frame)
      }
      if (flush && buffer.trim()) {
        const frame = buffer
        buffer = ''
        handleFrame(frame)
      }
    }

    try {
      while (true) {
        const { value, done } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })
        drainFrames()
      }
      buffer += decoder.decode()
      drainFrames(true)
    } finally {
      reader.releaseLock()
    }
  }

  return {
    request,
    ensureCsrfCookie,

    /** Implemented: GET /api/v1/health */
    health: () => request<HealthResponse>('/health', { anonymous: true }),

    auth: {
      /** Implemented: POST /api/v1/auth/register/code */
      sendRegistrationCode: (input: { email: string }) => request<{ message: string, expires_in: number, resend_after: number }>('/auth/register/code', {
        method: 'POST',
        body: { ...input },
        anonymous: true
      }),
      /** Implemented: POST /api/v1/auth/register */
      register: (input: RegisterInput) => request<AuthResponse>('/auth/register', {
        method: 'POST',
        body: { ...input },
        anonymous: true
      }),
      /** Implemented: POST /api/v1/auth/login */
      login: (input: LoginInput) => request<AuthResponse>('/auth/login', {
        method: 'POST',
        body: { ...input },
        anonymous: true
      }),
      /** Implemented: POST /api/v1/auth/logout */
      logout: () => request<{ message?: string }>('/auth/logout', { method: 'POST' }),
      /** Implemented: GET /api/v1/me */
      me: () => request<{ user: AuthenticatedUser }>('/me'),
      /**
       * Implemented: POST /api/v1/auth/forgot-password
       *
       * Deliberately neutral: the same response is returned whether or not the
       * address exists, so the UI must never imply that an account was found.
       */
      forgotPassword: (input: { email: string }) => request<{ message?: string }>('/auth/forgot-password', {
        method: 'POST',
        body: { ...input },
        anonymous: true
      }),
      /** Implemented: POST /api/v1/auth/reset-password — revokes every session on success. */
      resetPassword: (input: PasswordResetInput) => request<{ message?: string }>('/auth/reset-password', {
        method: 'POST',
        body: { ...input },
        anonymous: true
      }),
      /** Implemented: GET /api/v1/auth/google/redirect — returns Google OAuth URL */
      googleRedirect: (params?: { intent?: 'login' | 'link', domain?: string }) =>
        request<{ url: string }>('/auth/google/redirect', {
          method: 'GET',
          query: params,
          anonymous: params?.intent !== 'link'
        })
    },

    /** Requested contract — public catalog. */
    catalog: {
      models: () => request<PublicModel[]>('/catalog/models', {
        collection: true,
        anonymous: true
      }),
      packages: () => request<PublicPackage[]>('/catalog/packages', {
        collection: true,
        anonymous: true
      })
    },

    referrals: {
      resolve: (code: string) => request<ReferralResolution>(`/referrals/${apiSegment(code)}`, { anonymous: true }),
      dashboard: () => request<ReferralDashboard>('/me/referrals'),
      claim: (referralCode: string) => request<{ claimed: boolean, referred_at: string | null }>('/me/referrals/claim', {
        method: 'POST',
        body: { referral_code: referralCode }
      })
    },

    /** Requested contract — authenticated customer account. */
    account: {
      /** Implemented: PATCH /api/v1/me */
      updateProfile: (input: { name: string }) =>
        request<{ user: AuthenticatedUser }>('/me', { method: 'PATCH', body: { ...input } }),
      /**
       * Implemented: POST /api/v1/me/password
       *
       * Verifies the current password server-side and revokes every *other*
       * bearer session, keeping this browser signed in.
       */
      changePassword: (input: PasswordChangeInput) =>
        request<{ message?: string }>('/me/password', { method: 'POST', body: { ...input } }),
      /** Implemented: GET /api/v1/me/sessions */
      sessions: () => request<SessionSummary[]>('/me/sessions', { collection: true }),
      /** Implemented: DELETE /api/v1/me/sessions/{id} — owned sessions only. */
      revokeSession: (id: string) =>
        request<{ revoked: boolean }>(`/me/sessions/${apiSegment(id)}`, { method: 'DELETE' }),
      /** Implemented: GET /api/v1/me/external-identities */
      identities: () => request<ExternalIdentity[]>('/me/external-identities', { collection: true }),
      /** Implemented: DELETE /api/v1/me/external-identities/{id} */
      unlinkIdentity: (id: string) =>
        request<{ success: boolean }>(`/me/external-identities/${apiSegment(id)}`, { method: 'DELETE' }),
      balance: () => request<BalanceSummary>('/me/balance'),
      entitlements: () => request<EntitlementLot[]>('/me/entitlements', { collection: true }),
      apiKeys: () => request<ApiKeySummary[]>('/me/api-keys', { collection: true }),
      apiKeyDetails: (id: string) => request<ApiKeyDetails>(`/me/api-keys/${apiSegment(id)}`, { timeout: 6_000 }),
      apiKeyFunding: (id: string) => request<Pick<ApiKeyDetails, 'balance_source' | 'token_quota_remaining' | 'credit_balances' | 'funding' | 'funding_status' | 'funding_message' | 'funding_diagnostic_id' | 'server_time'>>(`/me/api-keys/${apiSegment(id)}/funding`, { timeout: 10_000 }),
      apiKeyUsageSummary: (id: string, query?: { from?: string, to?: string, bucket?: 'hour' | 'day' }) =>
        request<ApiKeyUsageSummary>(`/me/usage/keys/${apiSegment(id)}/summary`, { query }),
      createApiKey: (input: {
        label: string
        allowed_model_aliases?: string[]
        expires_at?: string | null
      }) => request<ApiKeyCreated>('/me/api-keys', { method: 'POST', body: { ...input } }),
      testApiKey: (id: string) => request<ApiKeyStatusReport>(`/me/api-keys/${apiSegment(id)}/status`),
      revealApiKey: (id: string) => request<ApiKeyCreated>(`/me/api-keys/${apiSegment(id)}/reveal`, { method: 'POST' }),
      rotateApiKey: (id: string) => request<ApiKeyCreated>(`/me/api-keys/${apiSegment(id)}/rotate`, { method: 'POST' }),
      setApiKeyStatus: (id: string, status: 'ACTIVE' | 'DISABLED' | 'REVOKED') =>
        request<ApiKeySummary>(`/me/api-keys/${apiSegment(id)}/status`, { method: 'PATCH', body: { status } }),
      activity: (query?: { limit?: number, model?: string, key_id?: string }) =>
        request<RequestActivity[]>('/me/activity', { collection: true, query }),
      usageSummary: (query?: { from?: string, to?: string, bucket?: 'hour' | 'day' }) =>
        request<UsageSummary>('/me/usage/summary', { query }),
      playgroundQuota: () => request<PlaygroundQuota>('/me/playground/quota'),
      runPlayground: (input: { model: string, protocol: 'messages' | 'responses' | 'chat_completions', system_prompt?: string | null, prompt?: string | null, messages?: Array<{ role: 'user' | 'assistant', content: string }>, max_output_tokens: number, temperature?: number | null, funding_source?: 'daily' | 'balance' }) =>
        request<{ response: unknown, message: string, request_id: string, quota: PlaygroundQuota }>('/me/playground/run', { method: 'POST', body: { ...input } }),
      streamPlayground,
      playgroundChats: (query?: { limit?: number }) =>
        request<PlaygroundChatSummary[]>('/me/playground/chats', { collection: true, query }),
      playgroundChat: (id: number | string) =>
        request<PlaygroundChat>(`/me/playground/chats/${apiSegment(String(id))}`),
      createPlaygroundChat: (input: { title?: string | null, model_alias?: string | null, system_prompt?: string | null, messages: Array<{ role: 'user' | 'assistant', content: string }> }) =>
        request<PlaygroundChat>('/me/playground/chats', { method: 'POST', body: { ...input } }),
      syncPlaygroundChat: (input: { client_key: string, title?: string | null, model_alias?: string | null, system_prompt?: string | null, messages: Array<{ role: 'user' | 'assistant', content: string }> }) =>
        request<PlaygroundChat>('/me/playground/chats/sync', { method: 'PUT', body: { ...input } }),
      updatePlaygroundChat: (id: number | string, input: { title?: string | null, model_alias?: string | null, system_prompt?: string | null, messages?: Array<{ role: 'user' | 'assistant', content: string }> }) =>
        request<PlaygroundChat>(`/me/playground/chats/${apiSegment(String(id))}`, { method: 'PUT', body: { ...input } }),
      deletePlaygroundChat: (id: number | string) =>
        request<{ deleted: boolean, id: number }>(`/me/playground/chats/${apiSegment(String(id))}`, { method: 'DELETE' }),
      clearPlaygroundChats: () =>
        request<{ deleted: number }>('/me/playground/chats', { method: 'DELETE' }),
      redeemCode: (input: { code: string, idempotency_key: string }) =>
        request<{ entitlement_id: string, package_name: string, billing_mode: 'TOKEN_QUOTA' | 'CREDIT_BALANCE', units: string, expires_at: string | null, allowed_model_aliases: string[] }>('/redeem-codes/redeem', { method: 'POST', body: { ...input } }),
      telegram: () => request<TelegramAccountStatus>('/me/telegram'),
      createTelegramLinkToken: () => request<TelegramLinkToken>('/me/telegram/link-token', { method: 'POST' }),
      unlinkTelegram: () => request<TelegramAccountStatus>('/me/telegram', { method: 'DELETE' })
    },

    /** Requested contract — orders, promotions and KHQR payment. */
    orders: {
      list: () => request<Order[]>('/orders', { collection: true }),
      hide: (id: string) => request<{ hidden: boolean, order_id: string }>(`/orders/${apiSegment(id)}`, { method: 'DELETE' }),
      clearHistory: () => request<{ hidden_count: number }>('/orders/history', { method: 'DELETE' }),
      get: (id: string) => request<Order>(`/orders/${apiSegment(id)}`),
      create: (input: { package_slug: string, quantity?: number, promotion_code?: string, idempotency_key: string }) =>
        request<Order>('/orders', { method: 'POST', body: { ...input } }),
      previewPromotion: (input: { package_slug: string, quantity?: number, promotion_code: string }) =>
        request<PromotionPreview>('/promotions/preview', { method: 'POST', body: { ...input } }),
      createPayment: (orderId: string) =>
        request<PaymentAttempt>(`/orders/${apiSegment(orderId)}/payment`, { method: 'POST' }),
      paymentStatus: (orderId: string) => request<PaymentAttempt>(`/orders/${apiSegment(orderId)}/payment`),
      autoCheckPayment: (orderId: string) =>
        request<PaymentAttempt>(`/orders/${apiSegment(orderId)}/payment/auto-check`, { method: 'POST' }),
      /**
       * "I paid" asks the backend to re-check Bakong immediately. It never
       * asserts that payment succeeded — only the backend can decide that.
       */
      requestVerification: (orderId: string) =>
        request<PaymentAttempt>(`/orders/${apiSegment(orderId)}/payment/verify`, { method: 'POST' })
    },

    /** Requested contract — public service status. */
    status: {
      system: () => request<SystemStatus>('/status', { anonymous: true, collection: true })
    },

    /** Public API key checker — no authentication required. */
    checkApiKey: (params: { api_key: string }) =>
      request<PublicApiKeyStatus>('/keys/check', { method: 'POST', body: params, anonymous: true, timeout: 12_000 }),

    google: {
      redirect: (params?: { intent?: 'login' | 'link', domain?: string }) =>
        request<{ url: string }>('/auth/google/redirect', {
          method: 'GET',
          query: params,
          anonymous: params?.intent !== 'link'
        }),
      callback: (input: GoogleCallbackInput) => request<AuthResponse>('/auth/google/callback', {
        method: 'POST',
        body: input
      }),
      link: (input: GoogleLinkCallbackInput) => request<{ success: boolean, message?: string, identity_id?: string }>('/auth/google/link', {
        method: 'POST',
        body: input
      })
    },

    /**
     * Implemented — admin analytics and catalogue management.
     *
     * `overview` and `systemHealth` require `admin.view`; every catalogue route
     * requires `catalog.manage`. The two are separate permissions, so a session
     * holding one may still receive 403 `forbidden` from the other.
     */
    admin: {
      referrals: () => request<AdminReferralOverview>('/admin/referrals'),
      updateReferralSettings: (input: AdminReferralSettings & { reason: string }) =>
        request<AdminReferralSettings>('/admin/referrals/settings', { method: 'PUT', body: { ...input } }),
      overview: () => request<AdminOverview>('/admin/overview'),
      systemHealth: () => request<AdminSystemHealth>('/admin/system-health'),
      auditLogs: (query?: { action?: string, subject_type?: string, actor_user_id?: number, limit?: number }) =>
        request<AdminAuditLog[]>('/admin/audit-logs', { collection: true, query }),
      operations: () => request<AdminOperationsOverview>('/admin/operations'),
      reconciliationReservations: (query?: { limit?: number }) =>
        request<AdminReconciliationReservation[]>('/admin/operations/reconciliation-reservations', { collection: true, query }),
      accessCustomers: (query?: { q?: string, limit?: number }) =>
        request<AdminCustomerAccess[]>('/admin/access/customers', { collection: true, query }),
      updateAccessCustomerStatus: (id: string, input: { status: 'ACTIVE' | 'SUSPENDED' | 'DISABLED', reason: string }) =>
        request<{ id: string, status: string }>(`/admin/access/customers/${apiSegment(id)}/status`, { method: 'PATCH', body: { ...input } }),
      accessApiKeys: (query?: { q?: string, status?: string, limit?: number }) =>
        request<AdminAccessApiKey[]>('/admin/access/api-keys', { collection: true, query }),
      accessModelAliases: () => request<AdminAccessModelAlias[]>('/admin/access/model-aliases', { collection: true }),
      issueAccessApiKey: (input: {
        user_id: number
        label: string
        allowed_model_alias_ids: number[]
        expires_at?: string | null
        requests_per_minute?: number | null
        tokens_per_minute?: number | null
        concurrency_limit?: number | null
        max_request_bytes?: number | null
        max_output_tokens?: number | null
        reason: string
      }) => request<AdminAccessApiKeyCreated>('/admin/access/api-keys', { method: 'POST', body: { ...input } }),
      updateAccessApiKeyStatus: (id: string, input: { status: 'ACTIVE' | 'DISABLED' | 'REVOKED', reason: string }) =>
        request<AdminAccessApiKey>(`/admin/access/api-keys/${apiSegment(id)}/status`, { method: 'PATCH', body: { ...input } }),
      accessEntitlements: (query?: { q?: string, status?: string, limit?: number }) =>
        request<AdminAccessEntitlement[]>('/admin/access/entitlements', { collection: true, query }),
      expireAccessEntitlement: (id: string, reason: string) =>
        request<{ id: string, status: string, expires_at: string | null }>(`/admin/access/entitlements/${apiSegment(id)}/expire`, { method: 'POST', body: { reason } }),
      accessUsage: (query?: { q?: string, state?: string, limit?: number }) =>
        request<AdminUsageRequest[]>('/admin/access/usage', { collection: true, query }),
      runRecovery: (input: { action: AdminRecoveryAction, batch?: number, reason: string }) =>
        request<AdminRecoveryResponse>('/admin/operations/recover', { method: 'POST', body: { ...input } }),
      verifyPaymentAttempt: (id: string, reason: string) =>
        request<{ id: string, status: string, order_id: string }>(`/admin/operations/payments/${apiSegment(id)}/verify`, { method: 'POST', body: { reason } }),
      retryTelegramPurchase: (id: string, reason: string) =>
        request<{ id: string, status: string, delivered_at: string | null }>(`/admin/operations/telegram-purchases/${apiSegment(id)}/retry`, { method: 'POST', body: { reason } }),
      releaseReconciliationReservation: (id: string, reason: string, confirmation: 'CONFIRMED NO UPSTREAM USAGE') =>
        request<{ id: string, status: string, settled_units: string }>(`/admin/operations/reservations/${apiSegment(id)}/release-confirmed`, { method: 'POST', body: { reason, confirmation } }),
      telegramStore: () => request<AdminTelegramStoreOverview>('/admin/telegram-store'),
      sendTelegramAnnouncement: (input: { title: string, body: string, package_id?: string | null }) =>
        request<{ id: string, status: string, message: string }>('/admin/telegram-store/announcements', { method: 'POST', body: { ...input } }),
      retryTelegramAnnouncementFailures: (id: string, reason: string) =>
        request<{ id: string, requeued: number, message: string }>(`/admin/telegram-store/announcements/${apiSegment(id)}/retry-failed`, { method: 'POST', body: { reason } }),
      retryTelegramPurchaseAlert: (id: string, reason: string) =>
        request<{ id: string, requeued: boolean, message: string }>(`/admin/telegram-store/purchase-alerts/${apiSegment(id)}/retry`, { method: 'POST', body: { reason } }),

      /** Every package, including ones hidden from the public catalogue. */
      packages: () => request<AdminPackage[]>('/admin/packages', { collection: true }),
      /**
       * Creates a package and its model scope in one transaction.
       *
       * Publishing (`enabled` *and* `customer_visible`) is refused with 409
       * `profitability_review_required` unless the margin is established or
       * `profitability_override_reason` is set. The whole write rolls back, so a
       * refused publication leaves no half-created package behind.
       */
      createPackage: (input: AdminPackageInput) =>
        request<AdminPackage>('/admin/packages', { method: 'POST', body: { ...input } }),
      /**
       * Full replacement, not a patch: every field is validated as required, so a
       * partial body is rejected rather than merged. `packages()` returns all of
       * them for exactly this reason.
       */
      updatePackage: (id: string, input: AdminPackageInput) =>
        request<AdminPackage>(`/admin/packages/${apiSegment(id)}`, { method: 'PUT', body: { ...input } }),
      addPackageStock: (id: string, quantity: number, reason: string) =>
        request<AdminPackage>(`/admin/packages/${apiSegment(id)}/stock`, { method: 'POST', body: { quantity, reason } }),
      /**
       * Margin analysis for one package.
       *
       * Also embedded in each row of `packages()`, so this is only needed to
       * re-check a single package after its aliases' upstream costs change.
       */
      packageProfitability: (id: string) =>
        request<PackageProfitability>(`/admin/packages/${apiSegment(id)}/profitability`),

      /**
       * Alias discovery: public names, publication state and exact rates.
       *
       * The `id` of each row is what `allowed_model_alias_ids` on a package write
       * takes. Provider identity and internal routes are not part of the response.
       */
      modelAliases: () => request<AdminModelAlias[]>('/admin/model-aliases', { collection: true }),
      /**
       * Replaces one alias's pricing record and records the reason in the audit trail.
       *
       * Both the customer sell rates and the upstream cost rates live here; the
       * upstream side is what package profitability is computed from, and only when
       * `upstream_cost_verified_at` is set.
       */
      updateModelAliasPricing: (id: string, input: ModelAliasPricingInput) =>
        request<AdminModelAlias>(`/admin/model-aliases/${apiSegment(id)}/pricing`, {
          method: 'PUT',
          body: { ...input }
        }),

      promotions: () => request<AdminPromotion[]>('/admin/promotions', { collection: true }),
      createPromotion: (input: AdminPromotionInput) =>
        request<AdminPromotion>('/admin/promotions', { method: 'POST', body: { ...input } }),
      /**
       * Full replacement, not a patch: the control plane validates every field as
       * required, so a partial body is rejected rather than merged.
       */
      updatePromotion: (id: string, input: AdminPromotionInput) =>
        request<AdminPromotion>(`/admin/promotions/${apiSegment(id)}`, { method: 'PUT', body: { ...input } }),

      playgroundSettings: () => request<AdminPlaygroundSettings>('/admin/playground-settings'),
      updatePlaygroundSettings: (input: AdminPlaygroundSettings) =>
        request<AdminPlaygroundSettings>('/admin/playground-settings', { method: 'PUT', body: { ...input } }),

      redeemCodes: () => request<AdminRedeemCode[]>('/admin/redeem-codes', { collection: true }),
      createRedeemCode: (input: AdminRedeemCodeInput) =>
        request<AdminRedeemCode>('/admin/redeem-codes', { method: 'POST', body: { ...input } }),
      updateRedeemCode: (id: string, input: AdminRedeemCodeUpdateInput) =>
        request<AdminRedeemCode>(`/admin/redeem-codes/${apiSegment(id)}`, { method: 'PUT', body: { ...input } }),

      /** List all providers */
      providers: () => request<AdminProvider[]>('/admin/providers', { collection: true }),
      /** Create a new provider */
      createProvider: (input: { name: string, slug: string, enabled: boolean }) =>
        request<AdminProvider>('/admin/providers', { method: 'POST', body: { ...input } }),
      /** Update a provider */
      updateProvider: (id: string, input: { name: string, slug: string, enabled: boolean }) =>
        request<AdminProvider>(`/admin/providers/${apiSegment(id)}`, { method: 'PUT', body: { ...input } }),
      /** Delete a provider */
      deleteProvider: (id: string, cascade = false) =>
        request<{ success: boolean, cascade?: boolean, deleted_aliases?: number }>(`/admin/providers/${apiSegment(id)}${cascade ? '?cascade=1' : ''}`, { method: 'DELETE' }),
      /** List all connection revisions for a provider */
      providerConnectionRevisions: (providerId: string) =>
        request<ProviderConnectionRevision[]>(`/admin/providers/${apiSegment(providerId)}/connection-revisions`, { collection: true }),
      /** Create a new connection revision */
      createProviderConnectionRevision: (providerId: string, input: ProviderConnectionRevisionInput) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions`, { method: 'POST', body: { ...input } }),
      updateProviderConnectionRevision: (providerId: string, revisionId: string, input: ProviderConnectionRevisionUpdateInput) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}`, { method: 'PUT', body: { ...input } }),
      deleteProviderConnectionRevision: (providerId: string, revisionId: string) =>
        request<{ success: boolean, hidden?: boolean, hard_deleted?: boolean }>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}`, { method: 'DELETE' }),
      /** Update the active connection revision for a provider */
      updateProviderActiveConnection: (providerId: string, input: ProviderActiveConnectionUpdateInput) =>
        request<AdminProvider>(`/admin/providers/${apiSegment(providerId)}/active-connection-revision`, { method: 'PUT', body: { ...input } }),
      /** Probe a connection revision */
      probeProviderConnectionRevision: (providerId: string, revisionId: string) =>
        request<ProviderConnectionProbeResult>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}/probe`, { method: 'POST' }),
      /** Update the status of a connection revision */
      updateProviderConnectionRevisionStatus: (providerId: string, revisionId: string, input: ProviderConnectionStatusUpdateInput) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}/status`, { method: 'PATCH', body: { ...input } }),
      /** List all private models for a provider */
      providerModels: (providerId: string) =>
        request<AdminProviderModel[]>(`/admin/providers/${apiSegment(providerId)}/models`, { collection: true }),
      /** Create a new private model for a provider */
      createProviderModel: (providerId: string, input: ProviderModelInput) =>
        request<AdminProviderModel>(`/admin/providers/${apiSegment(providerId)}/models`, { method: 'POST', body: { ...input } }),
      /** Discover model ids advertised by the provider's active READY connection. */
      discoverProviderModels: (providerId: string) =>
        request<DiscoveredProviderModel[]>(`/admin/providers/${apiSegment(providerId)}/models/discover`, { method: 'POST', collection: true }),
      /** Import selected discovered model ids and optionally create hidden public aliases. */
      importProviderModels: (providerId: string, modelIds: string[], createPublicAliases = false) =>
        request<ProviderModelImportResult>(`/admin/providers/${apiSegment(providerId)}/models/import`, {
          method: 'POST',
          body: { model_ids: modelIds, create_public_aliases: createPublicAliases }
        }),
      /** Update a private model for a provider */
      updateProviderModel: (providerId: string, modelId: string, input: ProviderModelInput) =>
        request<AdminProviderModel>(`/admin/providers/${apiSegment(providerId)}/models/${apiSegment(modelId)}`, { method: 'PUT', body: { ...input } }),
      /** Delete a private model */
      deleteProviderModel: (providerId: string, modelId: string) =>
        request<{ success: boolean }>(`/admin/providers/${apiSegment(providerId)}/models/${apiSegment(modelId)}`, { method: 'DELETE' }),
      /** List all public aliases for a provider */
      providerAliases: (providerId: string) =>
        request<AdminProviderAlias[]>(`/admin/providers/${apiSegment(providerId)}/aliases`, { collection: true }),
      /** Create a new public alias for a provider */
      createProviderAlias: (providerId: string, input: ProviderAliasInput) =>
        request<AdminProviderAlias>(`/admin/providers/${apiSegment(providerId)}/aliases`, { method: 'POST', body: { ...input } }),
      /** Update a public alias for a provider */
      updateProviderAlias: (providerId: string, aliasId: string, input: ProviderAliasInput) =>
        request<AdminProviderAlias>(`/admin/providers/${apiSegment(providerId)}/aliases/${apiSegment(aliasId)}`, { method: 'PUT', body: { ...input } }),
      /** Delete a public alias */
      deleteProviderAlias: (providerId: string, aliasId: string) =>
        request<{ success: boolean }>(`/admin/providers/${apiSegment(providerId)}/aliases/${apiSegment(aliasId)}`, { method: 'DELETE' }),
      /** Publish a provider alias for sale after explicit commercial-resale confirmation. */
      publishProviderAlias: (providerId: string, aliasId: string, input: { confirm_commercial_resale: boolean }) =>
        request<AdminProviderAlias>(`/admin/providers/${apiSegment(providerId)}/aliases/${apiSegment(aliasId)}/publish`, { method: 'POST', body: input }),
      /** Map a public alias to a private model */
      mapAliasToModel: (providerId: string, aliasId: string, modelId: string) =>
        request<{ success: boolean }>(`/admin/providers/${apiSegment(providerId)}/aliases/${apiSegment(aliasId)}/map-model`, { method: 'POST', body: { model_id: modelId } })
    },

    /**
     * Implemented — reseller management. Requires the `reseller.manage`
     * permission. Every route is tenant-isolated; another reseller's ids 404.
     */
    reseller: {
      customers: () => request<ResellerCustomer[]>('/reseller/customers', { collection: true }),
      createCustomer: (input: ResellerCustomerInput) =>
        request<ResellerCustomer>('/reseller/customers', { method: 'POST', body: { ...input } }),
      updateCustomerStatus: (customerId: string, input: ResellerCustomerStatusUpdateInput) =>
        request<ResellerCustomer>(`/reseller/customers/${apiSegment(customerId)}/status`, {
          method: 'PATCH',
          body: { ...input }
        }),
      allocate: (customerId: string, input: ResellerAllocationInput) =>
        request<ResellerAllocation>(`/reseller/customers/${apiSegment(customerId)}/allocations`, {
          method: 'POST',
          body: { ...input }
        }),
      customerKeys: (customerId: string) =>
        request<ResellerCustomerKey[]>(`/reseller/customers/${apiSegment(customerId)}/api-keys`, { collection: true }),
      createCustomerKey: (customerId: string, input: {
        label: string
        allowed_model_aliases?: string[]
        expires_at?: string | null
      }) => request<ResellerCustomerKeyCreated>(`/reseller/customers/${apiSegment(customerId)}/api-keys`, {
        method: 'POST',
        body: { ...input }
      }),
      revokeCustomerKey: (customerId: string, keyId: string) =>
        request<ResellerCustomerKey>(
          `/reseller/customers/${apiSegment(customerId)}/api-keys/${apiSegment(keyId)}/revoke`,
          { method: 'POST' }
        ),
      managementKeys: () =>
        request<ResellerManagementKey[]>('/reseller/management-keys', { collection: true }),
      createManagementKey: (input: {
        label: string
        scopes: ResellerManagementScope[]
        expires_at?: string | null
      }) => request<ResellerManagementKeyCreated>('/reseller/management-keys', {
        method: 'POST',
        body: { ...input }
      }),
      revokeManagementKey: (id: string) =>
        request<ResellerManagementKey>(`/reseller/management-keys/${apiSegment(id)}/revoke`, { method: 'POST' })
    }
  }
}
