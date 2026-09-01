import { afterEach, expect, it, vi } from "vitest";
import { buildApp } from "../src/app.js";
import { MemoryRateStore } from "../src/rate-store.js";
import type {
  ControlPlane,
  GatewayConfig,
  InspectData,
  PreflightData,
  RouteData,
  Usage,
} from "../src/types.js";

const secret = `sk-${"r".repeat(48)}`;

const config: GatewayConfig = {
  host: "127.0.0.1",
  port: 3010,
  controlPlaneBaseUrl: "http://control-plane",
  internalSecret: "i".repeat(32),
  rateStore: "memory",
  redisUrl: null,
  maxBodyBytes: 1024 * 1024,
  defaultMaxOutputTokens: 100,
  upstreamTimeoutMs: 1000,
  controlPlaneTimeoutMs: 1000,
};

class RoutePoolControlPlane implements ControlPlane {
  reroutes: Array<{ id: string; failure_code: string; upstream_status?: number }> = [];
  routeSuccesses: string[] = [];
  releases: string[] = [];
  settles: string[] = [];

  inspectData: InspectData = {
    key_id: "key-route-pool",
    status: "ACTIVE",
    expires_at: null,
    allowed_models: [{
      id: "claude-coding",
      display_name: "Claude Coding",
      capabilities: { messages_api: true },
      limits: {},
    }],
    limits: {
      requests_per_minute: 100,
      tokens_per_minute: 100000,
      concurrency: 10,
      max_request_bytes: 100000,
      max_output_tokens: 100,
    },
    balances: {
      token_quota_remaining: "100000",
      credit_remaining: "0",
      version: 1,
    },
    service_status: "operational",
  };

  preflightData: PreflightData = {
    reservation_id: "reservation-route-pool",
    public_model: "claude-coding",
    internal_model: "private-route-a",
    reserved_units: "100",
    billing_mode: "TOKEN_QUOTA",
    max_output_tokens: 100,
    correlation_id: "request-route-pool",
    route_revision_id: "revision-a",
    route_version: 1,
    upstream_origin: "http://omniroute-a:20128",
    upstream_credential: "a".repeat(32),
    upstream_timeout_ms: 1000,
  };

  nextRoute: RouteData = {
    internal_model: "private-route-b",
    route_revision_id: "revision-b",
    route_version: 2,
    upstream_origin: "http://omniroute-b:20128",
    upstream_credential: "b".repeat(32),
    upstream_timeout_ms: 1000,
  };

  async inspect(): Promise<InspectData> {
    return this.inspectData;
  }

  async preflight(): Promise<PreflightData> {
    return this.preflightData;
  }

  async reroute(
    reservationId: string,
    input: { failure_code: string; upstream_status?: number },
  ): Promise<RouteData> {
    this.reroutes.push({ id: reservationId, ...input });
    return this.nextRoute;
  }

  async routeSuccess(reservationId: string): Promise<void> {
    this.routeSuccesses.push(reservationId);
  }

  async settle(reservationId: string, _usage: Usage & { duration_ms: number }): Promise<void> {
    this.settles.push(reservationId);
  }

  async release(reservationId: string): Promise<void> {
    this.releases.push(reservationId);
  }

  async reconcile(): Promise<void> {}
}

const apps: Array<ReturnType<typeof buildApp>> = [];

afterEach(async () => {
  await Promise.all(apps.splice(0).map((app) => app.close()));
  vi.restoreAllMocks();
});

const auth = {
  authorization: `Bearer ${secret}`,
  "content-type": "application/json",
};

const body = {
  model: "claude-coding",
  max_tokens: 20,
  messages: [{ role: "user", content: "Hello" }],
};

it("fails over from a retryable 503 before public output starts", async () => {
  const control = new RoutePoolControlPlane();
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response(
      JSON.stringify({ error: "private route unavailable" }),
      { status: 503, headers: { "content-type": "application/json" } },
    ))
    .mockResolvedValueOnce(new Response(
      JSON.stringify({
        id: "msg-route-b",
        content: [{ type: "text", text: "served by route b" }],
        usage: { input_tokens: 10, output_tokens: 5 },
      }),
      { status: 200, headers: { "content-type": "application/json" } },
    ));

  const instance = buildApp(config, {
    controlPlane: control,
    rateStore: new MemoryRateStore(),
    fetchImpl: fetchMock as typeof fetch,
  });
  apps.push(instance);

  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages",
    headers: auth,
    payload: body,
  });

  expect(response.statusCode).toBe(200);
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(String(fetchMock.mock.calls[0]?.[0])).toContain("omniroute-a");
  expect(String(fetchMock.mock.calls[1]?.[0])).toContain("omniroute-b");
  expect(control.reroutes).toEqual([{
    id: "reservation-route-pool",
    failure_code: "upstream_http_503",
    upstream_status: 503,
  }]);
  expect(control.releases).toHaveLength(0);
  expect(control.settles).toContain("reservation-route-pool");
});

it("fails over after a connection failure before headers", async () => {
  const control = new RoutePoolControlPlane();
  const fetchMock = vi.fn()
    .mockRejectedValueOnce(new Error("connect failed"))
    .mockResolvedValueOnce(new Response(
      JSON.stringify({
        id: "msg-route-b",
        content: [{ type: "text", text: "ok" }],
        usage: { input_tokens: 4, output_tokens: 2 },
      }),
      { status: 200, headers: { "content-type": "application/json" } },
    ));

  const instance = buildApp(config, {
    controlPlane: control,
    rateStore: new MemoryRateStore(),
    fetchImpl: fetchMock as typeof fetch,
  });
  apps.push(instance);

  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages",
    headers: auth,
    payload: body,
  });

  expect(response.statusCode).toBe(200);
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(control.reroutes[0]).toMatchObject({
    id: "reservation-route-pool",
    failure_code: "upstream_connect_error",
  });
});
