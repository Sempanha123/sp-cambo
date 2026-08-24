export const INFERENCE_PATHS = [
  "/v1/messages",
  "/v1/messages/count_tokens",
  "/v1/responses",
  "/v1/chat/completions",
] as const;

export type InferencePath = (typeof INFERENCE_PATHS)[number];

export type GatewayConfig = {
  host: string;
  port: number;
  controlPlaneBaseUrl: string;
  internalSecret: string;
  omniRouteBaseUrl: string;
  omniRouteApiKey: string;
  rateStore: "memory" | "redis";
  redisUrl: string | null;
  maxBodyBytes: number;
  defaultMaxOutputTokens: number;
  upstreamTimeoutMs: number;
  controlPlaneTimeoutMs: number;
};

export type Limits = {
  requests_per_minute: number | null;
  tokens_per_minute: number | null;
  concurrency: number | null;
  max_request_bytes: number | null;
  max_output_tokens: number | null;
};

export type AllowedModel = {
  id: string;
  display_name: string;
  capabilities: Record<string, unknown>;
  limits: Record<string, unknown>;
};

export type InspectData = {
  key_id: string;
  status: "ACTIVE";
  expires_at: string | null;
  allowed_models: AllowedModel[];
  limits: Limits;
  balances: {
    token_quota_remaining: string;
    credit_remaining: string;
    version: number;
  };
  service_status: string;
};

export type PreflightData = {
  reservation_id: string;
  public_model: string;
  internal_model: string;
  reserved_units: string;
  billing_mode: "TOKEN_QUOTA" | "CREDIT_BALANCE";
  max_output_tokens: number;
  correlation_id: string;
  route_revision_id: string | null;
  route_version: number | null;
  /** Private route material supplied only by the authenticated control plane. */
  upstream_origin: string;
  upstream_credential: string;
  upstream_timeout_ms: number;
};

export type Usage = {
  input_tokens: number;
  output_tokens: number;
  cache_read_tokens: number;
  cache_write_tokens: number;
  reasoning_tokens: number;
};

export type RateLease = {
  release(): Promise<void>;
};

export interface RateStore {
  acquire(keyId: string, limits: Limits, estimatedTokens: number): Promise<RateLease>;
  admit(identity: string, requestsPerMinute: number): Promise<void>;
  close(): Promise<void>;
}

export interface ControlPlane {
  inspect(customerKey: string): Promise<InspectData>;
  preflight(input: {
    customer_key: string;
    public_model: string;
    estimated_input_tokens: number;
    requested_max_output_tokens: number;
    request_bytes: number;
    request_id: string;
    request_fingerprint: string;
    endpoint: InferencePath;
  }): Promise<PreflightData>;
  settle(reservationId: string, usage: Usage & { duration_ms: number }): Promise<void>;
  release(reservationId: string): Promise<void>;
  reconcile(reservationId: string, reason: string): Promise<void>;
}

export type Fetch = typeof globalThis.fetch;
