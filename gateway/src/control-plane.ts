import { ControlPlaneError, GatewayError } from "./errors.js";
import type { ControlPlane, Fetch, GatewayConfig, InferencePath, InspectData, PreflightData, RouteData, Usage } from "./types.js";

export class HttpControlPlane implements ControlPlane {
  constructor(private readonly config: GatewayConfig, private readonly fetchImpl: Fetch = globalThis.fetch) {}

  async inspect(customerKey: string): Promise<InspectData> {
    return this.call<InspectData>("/internal/gateway/inspect", { customer_key: customerKey });
  }

  async preflight(input: {
    customer_key: string;
    public_model: string;
    estimated_input_tokens: number;
    estimated_cache_read_tokens: number;
    requested_max_output_tokens: number;
    request_bytes: number;
    request_id: string;
    request_fingerprint: string;
    endpoint: InferencePath;
    playground_funding_scope?: "DAILY" | "BALANCE";
  }): Promise<PreflightData> {
    return this.call<PreflightData>("/internal/gateway/preflight", input);
  }

  async reroute(
    reservationId: string,
    input: { failure_code: string; upstream_status?: number },
  ): Promise<RouteData> {
    return this.call<RouteData>(
      `/internal/gateway/reservations/${encodeURIComponent(reservationId)}/reroute`,
      input,
    );
  }

  async routeSuccess(reservationId: string): Promise<void> {
    await this.call(
      `/internal/gateway/reservations/${encodeURIComponent(reservationId)}/route-success`,
      {},
    );
  }

  async routeFailure(
    reservationId: string,
    input: { failure_code: string; upstream_status?: number },
  ): Promise<void> {
    await this.call(
      `/internal/gateway/reservations/${encodeURIComponent(reservationId)}/route-failure`,
      input,
    );
  }

  async state(reservationId: string, state: "CONNECTING" | "STREAMING"): Promise<void> {
    await this.call(`/internal/gateway/reservations/${encodeURIComponent(reservationId)}/state`, { state });
  }

  async settle(reservationId: string, usage: Usage & { duration_ms: number }): Promise<void> {
    await this.call(`/internal/gateway/reservations/${encodeURIComponent(reservationId)}/settle`, usage);
  }

  async release(reservationId: string): Promise<void> {
    await this.call(`/internal/gateway/reservations/${encodeURIComponent(reservationId)}/release`, {});
  }

  async reconcile(reservationId: string, reason: string, localUsage?: Usage & { duration_ms: number }): Promise<void> {
    await this.call(`/internal/gateway/reservations/${encodeURIComponent(reservationId)}/reconcile`, {
      reason,
      ...(localUsage ? { local_usage: localUsage } : {}),
    });
  }

  private async call<T>(path: string, body: unknown): Promise<T> {
    let response: Response;
    try {
      response = await this.fetchImpl(`${this.config.controlPlaneBaseUrl}/api/v1${path}`, {
        method: "POST",
        headers: {
          authorization: `Bearer ${this.config.internalSecret}`,
          "content-type": "application/json",
          accept: "application/json",
        },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(this.config.controlPlaneTimeoutMs),
      });
    } catch {
      throw new GatewayError(503, "billing_unavailable", "The billing service is temporarily unavailable.");
    }

    const parsed = await safeJson(response);
    if (!response.ok) {
      const record = isRecord(parsed) ? parsed : {};
      const status = response.status >= 500 ? 503 : response.status;
      const code = response.status >= 500
        ? "billing_unavailable"
        : safeString(record.code, "request_rejected");
      const message = response.status >= 500
        ? "The billing service is temporarily unavailable."
        : safeString(record.message, "The request was rejected.");
      throw new ControlPlaneError(status, code, message);
    }

    if (!isRecord(parsed) || !("data" in parsed)) {
      throw new GatewayError(503, "billing_unavailable", "The billing service returned an invalid response.");
    }

    return parsed.data as T;
  }
}

async function safeJson(response: Response): Promise<unknown> {
  try { return await response.json(); } catch { return null; }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function safeString(value: unknown, fallback: string): string {
  return typeof value === "string" && value.length <= 500 ? value : fallback;
}
