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
  AdminModelAlias,
  AdminOverview,
  AdminPackage,
  AdminPackageInput,
  AdminPromotion,
  AdminPromotionInput,
  AdminRedeemCode,
  AdminRedeemCodeInput,
  AdminRedeemCodeUpdateInput,
  AdminProvider,
  AdminProviderModel,
  AdminProviderAlias,
  AdminSystemHealth,
  ModelAliasPricingInput,
  PackageProfitability,
  ProviderActiveConnectionUpdateInput,
  ProviderConnectionRevision,
  ProviderConnectionRevisionInput,
  ProviderConnectionStatusUpdateInput,
  ProviderModelInput,
  ProviderAliasInput
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
  ApiKeyCreated,
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
  TelegramLinkToken
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
        retry: 0
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

  return {
    request,
    ensureCsrfCookie,

    /** Implemented: GET /api/v1/health */
    health: () => request<HealthResponse>('/health', { anonymous: true }),

    auth: {
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
      createApiKey: (input: {
        label: string
        allowed_model_aliases?: string[]
        expires_at?: string | null
      }) => request<ApiKeyCreated>('/me/api-keys', { method: 'POST', body: { ...input } }),
      testApiKey: (id: string) => request<ApiKeyStatusReport>(`/me/api-keys/${apiSegment(id)}/status`),
      rotateApiKey: (id: string) => request<ApiKeyCreated>(`/me/api-keys/${apiSegment(id)}/rotate`, { method: 'POST' }),
      setApiKeyStatus: (id: string, status: 'ACTIVE' | 'DISABLED' | 'REVOKED') =>
        request<ApiKeySummary>(`/me/api-keys/${apiSegment(id)}/status`, { method: 'PATCH', body: { status } }),
      activity: (query?: { limit?: number, model?: string, key_id?: string }) =>
        request<RequestActivity[]>('/me/activity', { collection: true, query }),
      usageSummary: (query?: { from?: string, to?: string, bucket?: 'hour' | 'day' }) =>
        request<UsageSummary>('/me/usage/summary', { query }),
      playgroundQuota: () => request<{ limit: number, remaining: number, reset_at: string, enabled: boolean }>('/me/playground/quota'),
      runPlayground: (input: { model: string, protocol: 'messages' | 'responses' | 'chat_completions', system_prompt?: string | null, prompt: string, max_output_tokens: number, temperature?: number | null }) =>
        request<{ response: unknown, request_id: string, quota: { limit: number, remaining: number, reset_at: string, enabled: boolean } }>('/me/playground/run', { method: 'POST', body: { ...input } }),
      redeemCode: (input: { code: string, idempotency_key: string }) =>
        request<{ entitlement_id: string, package_name: string, billing_mode: 'TOKEN_QUOTA' | 'CREDIT_BALANCE', units: string, expires_at: string | null, allowed_model_aliases: string[] }>('/redeem-codes/redeem', { method: 'POST', body: { ...input } }),
      telegram: () => request<TelegramAccountStatus>('/me/telegram'),
      createTelegramLinkToken: () => request<TelegramLinkToken>('/me/telegram/link-token', { method: 'POST' }),
      unlinkTelegram: () => request<TelegramAccountStatus>('/me/telegram', { method: 'DELETE' })
    },

    /** Requested contract — orders, promotions and KHQR payment. */
    orders: {
      list: () => request<Order[]>('/orders', { collection: true }),
      get: (id: string) => request<Order>(`/orders/${apiSegment(id)}`),
      create: (input: { package_slug: string, quantity?: number, promotion_code?: string, idempotency_key: string }) =>
        request<Order>('/orders', { method: 'POST', body: { ...input } }),
      previewPromotion: (input: { package_slug: string, quantity?: number, promotion_code: string }) =>
        request<PromotionPreview>('/promotions/preview', { method: 'POST', body: { ...input } }),
      createPayment: (orderId: string) =>
        request<PaymentAttempt>(`/orders/${apiSegment(orderId)}/payment`, { method: 'POST' }),
      paymentStatus: (orderId: string) => request<PaymentAttempt>(`/orders/${apiSegment(orderId)}/payment`),
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
      request<PublicApiKeyStatus>('/keys/check', { method: 'POST', body: params, anonymous: true }),

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
      overview: () => request<AdminOverview>('/admin/overview'),
      systemHealth: () => request<AdminSystemHealth>('/admin/system-health'),

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
      deleteProvider: (id: string) =>
        request<{ success: boolean }>(`/admin/providers/${apiSegment(id)}`, { method: 'DELETE' }),
      /** List all connection revisions for a provider */
      providerConnectionRevisions: (providerId: string) =>
        request<ProviderConnectionRevision[]>(`/admin/providers/${apiSegment(providerId)}/connection-revisions`, { collection: true }),
      /** Create a new connection revision */
      createProviderConnectionRevision: (providerId: string, input: ProviderConnectionRevisionInput) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions`, { method: 'POST', body: { ...input } }),
      updateProviderConnectionRevision: (providerId: string, revisionId: string, input: ProviderConnectionRevisionInput) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}`, { method: 'PUT', body: { ...input } }),
      deleteProviderConnectionRevision: (providerId: string, revisionId: string) =>
        request<{ success: boolean }>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}`, { method: 'DELETE' }),
      /** Update the active connection revision for a provider */
      updateProviderActiveConnection: (providerId: string, input: ProviderActiveConnectionUpdateInput) =>
        request<AdminProvider>(`/admin/providers/${apiSegment(providerId)}/active-connection-revision`, { method: 'PUT', body: { ...input } }),
      /** Probe a connection revision */
      probeProviderConnectionRevision: (providerId: string, revisionId: string) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}/probe`, { method: 'POST' }),
      /** Update the status of a connection revision */
      updateProviderConnectionRevisionStatus: (providerId: string, revisionId: string, input: ProviderConnectionStatusUpdateInput) =>
        request<ProviderConnectionRevision>(`/admin/providers/${apiSegment(providerId)}/connection-revisions/${apiSegment(revisionId)}/status`, { method: 'PATCH', body: { ...input } }),
      /** List all private models for a provider */
      providerModels: (providerId: string) =>
        request<AdminProviderModel[]>(`/admin/providers/${apiSegment(providerId)}/models`, { collection: true }),
      /** Create a new private model for a provider */
      createProviderModel: (providerId: string, input: ProviderModelInput) =>
        request<AdminProviderModel>(`/admin/providers/${apiSegment(providerId)}/models`, { method: 'POST', body: { ...input } }),
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
