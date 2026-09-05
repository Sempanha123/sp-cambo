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

const secret = `sk-${"a".repeat(48)}`;

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

class Control implements ControlPlane {
  rerouteReasons: string[] = [];
  settles: Array<{ id: string; usage: Usage & { duration_ms: number } }> = [];

  async inspect(): Promise<InspectData> {
    return {
      key_id: "tool-integrity-key",
      status: "ACTIVE",
      expires_at: null,
      allowed_models: [{
        id: "claude-coding",
        display_name: "Claude Coding",
        capabilities: { messages_api: true },
        limits: {},
      }],
      limits: {
        requests_per_minute: 20,
        tokens_per_minute: 100000,
        concurrency: 2,
        max_request_bytes: 1024 * 1024,
        max_output_tokens: 100000,
      },
      balances: {
        token_quota_remaining: "1000000",
        credit_remaining: "0",
        version: 1,
      },
      service_status: "operational",
    };
  }

  async preflight(): Promise<PreflightData> {
    return {
      reservation_id: "reservation-one",
      public_model: "claude-coding",
      internal_model: "route-one-model",
      reserved_units: "100",
      billing_mode: "TOKEN_QUOTA",
      max_output_tokens: 100000,
      correlation_id: "request-one",
      route_revision_id: "revision-one",
      route_version: 1,
      upstream_origin: "http://route-one",
      upstream_credential: "o".repeat(32),
      upstream_timeout_ms: 120000,
    };
  }

  async reroute(
    _reservationId: string,
    input: { failure_code: string; upstream_status?: number },
  ): Promise<RouteData> {
    this.rerouteReasons.push(input.failure_code);

    return {
      internal_model: "route-two-model",
      route_revision_id: "revision-two",
      route_version: 2,
      upstream_origin: "http://route-two",
      upstream_credential: "p".repeat(32),
      upstream_timeout_ms: 120000,
    };
  }

  async settle(
    id: string,
    usage: Usage & { duration_ms: number },
  ): Promise<void> {
    this.settles.push({ id, usage });
  }

  async release(): Promise<void> {}
  async reconcile(): Promise<void> {}
}

const apps: Array<ReturnType<typeof buildApp>> = [];

afterEach(async () => {
  await Promise.all(apps.splice(0).map((instance) => instance.close()));
  vi.restoreAllMocks();
});

function data(value: unknown): string {
  return `data: ${JSON.stringify(value)}\n\n`;
}

it("fails over before malformed streamed Write JSON reaches Claude Code", async () => {
  const control = new Control();

  const validRaw = JSON.stringify({
    file_path: "index.html",
    content: "<!DOCTYPE html>\n<h1>fallback valid</h1>",
  });

  const brokenStream = [
    data({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "bad_tool",
        name: "write",
        input: {},
      },
    }),
    data({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: validRaw.slice(0, -4),
      },
    }),
    data({
      type: "content_block_stop",
      index: 0,
    }),
    data({
      type: "message_stop",
    }),
  ].join("");

  const goodStream = [
    data({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "good_tool",
        name: "write",
        input: {},
      },
    }),
    data({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: validRaw,
      },
    }),
    data({
      type: "content_block_stop",
      index: 0,
    }),
    data({
      type: "message_stop",
    }),
  ].join("");

  const fetchMock = vi.fn(async (url: string | URL | Request) => {
    const target = String(url);

    if (target.startsWith("http://route-one/")) {
      return new Response(brokenStream, {
        status: 200,
        headers: { "content-type": "text/event-stream" },
      });
    }

    if (target.startsWith("http://route-two/")) {
      return new Response(goodStream, {
        status: 200,
        headers: { "content-type": "text/event-stream" },
      });
    }

    throw new Error(`Unexpected upstream URL: ${target}`);
  });

  const instance = buildApp(config, {
    controlPlane: control,
    rateStore: new MemoryRateStore(),
    fetchImpl: fetchMock as typeof fetch,
  });

  apps.push(instance);

  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages",
    headers: {
      authorization: `Bearer ${secret}`,
      "content-type": "application/json",
      "anthropic-version": "2023-06-01",
    },
    payload: {
      model: "claude-coding",
      max_tokens: 1000,
      stream: true,
      messages: [{
        role: "user",
        content: "Write index.html",
      }],
      tools: [{
        name: "Write",
        description: "Write a file",
        input_schema: {
          type: "object",
          properties: {
            file_path: { type: "string" },
            content: { type: "string" },
          },
          required: ["file_path", "content"],
        },
      }],
    },
  });

  expect(response.statusCode).toBe(200);
  expect(fetchMock).toHaveBeenCalledTimes(2);

  // The existing generic pre-public-output retry path reports this as an
  // upstream disconnect. The important property is that bad bytes were held.
  expect(control.rerouteReasons).toEqual(["upstream_disconnect"]);

  expect(response.body).toContain('"name":"Write"');
  expect(response.body).toContain("fallback valid");
  expect(response.body).toContain("good_tool");
  expect(response.body).not.toContain("bad_tool");
  expect(control.settles).toHaveLength(1);
});
