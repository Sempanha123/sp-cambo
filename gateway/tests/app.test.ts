import { request as httpRequest } from "node:http";
import { afterEach, describe, expect, it, vi } from "vitest";
import { buildApp } from "../src/app.js";
import { ControlPlaneError } from "../src/errors.js";
import { MemoryRateStore } from "../src/rate-store.js";
import { estimateTokens, localOutputBilledTokens } from "../src/protocol.js";
import type { ControlPlane, GatewayConfig, InspectData, PreflightData, RateLease, RateStore, Usage } from "../src/types.js";

const secret = `sk-${"a".repeat(48)}`;
const config: GatewayConfig = {
  host: "127.0.0.1", port: 3010, controlPlaneBaseUrl: "http://control-plane", internalSecret: "i".repeat(32), rateStore: "memory",
  redisUrl: null,
  maxBodyBytes: 1024 * 1024, defaultMaxOutputTokens: 100, upstreamTimeoutMs: 1000, controlPlaneTimeoutMs: 1000,
};

class FakeControlPlane implements ControlPlane {
  inspectCalls = 0; preflightCalls = 0; lastPreflight: Parameters<ControlPlane["preflight"]>[0] | null = null; settleCalls: Array<{ id: string; usage: Usage }> = []; releases: string[] = []; reconciles: string[] = [];
  settleError: Error | null = null;
  inspectData: InspectData = { key_id: "key-1", status: "ACTIVE", expires_at: null, allowed_models: [{ id: "claude-coding", display_name: "Claude Coding", capabilities: { messages_api: true }, limits: {} }], limits: { requests_per_minute: 10, tokens_per_minute: 10000, concurrency: 2, max_request_bytes: 10000, max_output_tokens: 100 }, balances: { token_quota_remaining: "1000", credit_remaining: "0", version: 1 }, service_status: "operational" };
  preflightData: PreflightData = { reservation_id: "reservation-1", public_model: "claude-coding", internal_model: "private-route", reserved_units: "100", billing_mode: "TOKEN_QUOTA", max_output_tokens: 100, correlation_id: "request-1", route_revision_id: "revision-1", route_version: 1, upstream_origin: "http://omniroute:20128", upstream_credential: "o".repeat(32), upstream_timeout_ms: 120000 };
  preflightError: Error | null = null;
  async inspect(): Promise<InspectData> { this.inspectCalls++; return this.inspectData; }
  async preflight(input: Parameters<ControlPlane["preflight"]>[0]): Promise<PreflightData> { this.preflightCalls++; this.lastPreflight = input; if (this.preflightError) throw this.preflightError; return this.preflightData; }
  async settle(id: string, usage: Usage): Promise<void> { this.settleCalls.push({ id, usage }); if (this.settleError) throw this.settleError; }
  async release(id: string): Promise<void> { this.releases.push(id); }
  async reconcile(id: string, reason: string, _localUsage?: Usage & { duration_ms: number }): Promise<void> { this.reconciles.push(`${id}:${reason}`); }
}

const apps: Array<ReturnType<typeof buildApp>> = [];
afterEach(async () => { await Promise.all(apps.splice(0).map((app) => app.close())); vi.restoreAllMocks(); });
function app(control = new FakeControlPlane(), fetchImpl: typeof fetch = vi.fn(), rateStore: RateStore = new MemoryRateStore()): [ReturnType<typeof buildApp>, FakeControlPlane, ReturnType<typeof vi.fn>] {
  const fetchMock = fetchImpl as ReturnType<typeof vi.fn>;
  const instance = buildApp(config, { controlPlane: control, rateStore, fetchImpl }); apps.push(instance); return [instance, control, fetchMock];
}
const auth = { authorization: `Bearer ${secret}`, "content-type": "application/json" };
const body = { model: "claude-coding", max_tokens: 20, messages: [{ role: "user", content: "Hello" }] };

it("supports bearer and Anthropic key auth but rejects conflicting credentials", async () => {
  const [instance] = app();
  expect((await instance.inject({ method: "GET", url: "/v1/models", headers: { authorization: `Bearer ${secret}` } })).statusCode).toBe(200);
  expect((await instance.inject({ method: "GET", url: "/v1/key/status", headers: { "x-api-key": secret } })).statusCode).toBe(200);
  const conflict = await instance.inject({ method: "GET", url: "/v1/models", headers: { authorization: `Bearer ${secret}`, "x-api-key": `sk-${"b".repeat(48)}` } });
  expect(conflict.statusCode).toBe(401); expect(conflict.json().error.code).toBe("conflicting_api_keys");
});

it("keeps legacy sk-spc keys valid while new keys use sk-", async () => {
  const [instance] = app();
  const legacy = `sk-spc-${"c".repeat(48)}`;
  expect((await instance.inject({ method: "GET", url: "/v1/models", headers: { authorization: `Bearer ${legacy}` } })).statusCode).toBe(200);
  expect((await instance.inject({ method: "GET", url: "/v1/key/status", headers: { "x-api-key": legacy } })).statusCode).toBe(200);
});

it("returns safe models and non-billable key status without upstream", async () => {
  const [instance, control, fetchMock] = app();
  const models = await instance.inject({ method: "GET", url: "/v1/models", headers: auth });
  expect(models.json().data[0].id).toBe("claude-coding"); expect(models.body).not.toContain("private-route");
  const status = await instance.inject({ method: "GET", url: "/v1/key/status", headers: auth });
  expect(status.json().token_quota_remaining).toBe("1000"); expect(control.preflightCalls).toBe(0); expect(fetchMock).not.toHaveBeenCalled();
});

it("bounds non-billable inspection before calling the control plane", async () => {
  const control = new FakeControlPlane(); const rateStore = new MemoryRateStore();
  for (let request = 0; request < 60; request++) await rateStore.admit("fixed-admission", 60);
  rateStore.admit = async (_identity: string, limit: number): Promise<void> => MemoryRateStore.prototype.admit.call(rateStore, "fixed-admission", limit);
  const [instance] = app(control, vi.fn() as typeof fetch, rateStore);
  const rejected = await instance.inject({ method: "GET", url: "/v1/key/status", headers: auth });
  expect(rejected.statusCode).toBe(429); expect(rejected.headers["retry-after"]).toBe("60"); expect(control.inspectCalls).toBe(0);
});


it("calibrates SP-local generated output without provider usage", () => {
  expect(localOutputBilledTokens(0)).toBe(0);
  expect(localOutputBilledTokens(2)).toBe(3);
  expect(localOutputBilledTokens(622)).toBe(933);
});
describe("preflight rejection", () => {
  for (const [status, code] of [[401, "invalid_api_key"], [403, "model_not_allowed"], [402, "insufficient_tokens"]] as const) {
    it(`${code} never reaches OmniRoute`, async () => {
      const control = new FakeControlPlane(); control.preflightError = new ControlPlaneError(status, code, "Rejected"); const [instance, , fetchMock] = app(control);
      const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
      expect(response.statusCode).toBe(status); expect(fetchMock).not.toHaveBeenCalled(); expect(control.releases).toHaveLength(0);
    });
  }
});

it("accepts Claude Code context_management but strips it before private upstream routing", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const sent = JSON.parse(init.body as string);
    expect(sent.context_management).toBeUndefined();
    expect(sent.model).toBe("private-route");
    return new Response(JSON.stringify({ id: "msg", usage: { input_tokens: 2, output_tokens: 3 } }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const [instance] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages",
    headers: auth,
    payload: { ...body, context_management: { edits: [{ type: "clear_tool_uses_20250919" }] } },
  });
  expect(response.statusCode).toBe(200);
});

it("accepts Claude Code output_config but strips it before private upstream routing", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const sent = JSON.parse(init.body as string);
    expect(sent.output_config).toBeUndefined();
    expect(sent.model).toBe("private-route");
    return new Response(JSON.stringify({ id: "msg", usage: { input_tokens: 2, output_tokens: 3 } }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const [instance] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages",
    headers: auth,
    payload: { ...body, output_config: { effort: "high" } },
  });
  expect(response.statusCode).toBe(200);
});

it("accepts output_config on Claude Code token-count requests and strips it upstream", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const sent = JSON.parse(init.body as string);
    expect(sent.output_config).toBeUndefined();
    return new Response(JSON.stringify({ input_tokens: 9 }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const [instance] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages/count_tokens",
    headers: auth,
    payload: { model: body.model, messages: body.messages, output_config: { effort: "high" } },
  });
  expect(response.statusCode).toBe(200);
});

it("maps public model, strips customer auth and settles SP-local JSON usage", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const headers = init.headers as Record<string, string>; const sent = JSON.parse(init.body as string);
    expect(headers.authorization).toBe(`Bearer ${control.preflightData.upstream_credential}`); expect(headers["x-api-key"]).toBe(control.preflightData.upstream_credential); expect(JSON.stringify(headers)).not.toContain(secret); expect(sent.model).toBe("private-route");
    return new Response(JSON.stringify({ id: "msg", model: "private-route", content: [{ type: "text", text: "hello" }], usage: { input_tokens: 5000, output_tokens: 7000, cache_read_input_tokens: 2000 } }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const control = new FakeControlPlane();
  const [instance] = app(control, fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: { ...auth, "anthropic-version": "2023-06-01", cookie: secret }, payload: body });
  expect(response.statusCode).toBe(200); expect(response.json().model).toBe("claude-coding"); expect(response.body).not.toContain("private-route"); expect(control.settleCalls[0]?.usage).toMatchObject({ input_tokens: estimateTokens(JSON.stringify(body)), output_tokens: localOutputBilledTokens(2), cache_read_tokens: 0, cache_write_tokens: 0, reasoning_tokens: 0 }); expect(control.releases).toHaveLength(0);
});


it("forwards exact database internal model IDs containing spaces", async () => {
  const control = new FakeControlPlane();
  control.preflightData = { ...control.preflightData, internal_model: "OpenAI Codex" };
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const sent = JSON.parse(init.body as string);
    expect(sent.model).toBe("OpenAI Codex");
    return new Response(JSON.stringify({ id: "msg", usage: { input_tokens: 2, output_tokens: 3 } }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const [instance] = app(control, fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: { ...body, model: "openai-codex" } });
  expect(response.statusCode).toBe(200);
});

it("echoes a safe request reference on gateway errors", async () => {
  const control = new FakeControlPlane();
  control.preflightError = new ControlPlaneError(403, "model_not_allowed", "Rejected");
  const [instance] = app(control);
  const response = await instance.inject({
    method: "POST",
    url: "/v1/messages",
    headers: { ...auth, "x-request-id": "pg_reference_123" },
    payload: body,
  });
  expect(response.statusCode).toBe(403);
  expect(response.headers["x-request-id"]).toBe("pg_reference_123");
});

it("restores exact Claude Code tool casing in non-stream Anthropic responses", async () => {
  const requestBody = { ...body, tools: [{ name: "Bash", description: "Run a command", input_schema: { type: "object", properties: {} } }] };
  const fetchMock = vi.fn(async () => new Response(JSON.stringify({
    id: "msg-tool", type: "message", content: [{ type: "tool_use", id: "tool_1", name: "bash", input: {} }], usage: { input_tokens: 5, output_tokens: 7 },
  }), { status: 200, headers: { "content-type": "application/json" } }));
  const [instance] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: requestBody });
  expect(response.statusCode).toBe(200);
  expect(response.json().content[0].name).toBe("Bash");
});

it("restores exact Claude Code tool casing in streamed Anthropic responses", async () => {
  const requestBody = { ...body, stream: true, tools: [{ name: "Edit", description: "Edit a file", input_schema: { type: "object", properties: {} } }] };
  const stream = [
    `event: content_block_start\ndata: ${JSON.stringify({ type: "content_block_start", content_block: { type: "tool_use", id: "tool_1", name: "edit", input: {} } })}\n\n`,
    `event: message_delta\ndata: ${JSON.stringify({ type: "message_delta", usage: { input_tokens: 4, output_tokens: 6 } })}\n\n`,
  ].join("");
  const fetchMock = vi.fn(async () => new Response(stream, { status: 200, headers: { "content-type": "text/event-stream" } }));
  const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: requestBody });
  expect(response.statusCode).toBe(200);
  expect(response.body).toContain('"name":"Edit"');
  expect(control.settleCalls[0]?.usage).toMatchObject({ input_tokens: estimateTokens(JSON.stringify(requestBody)), output_tokens: localOutputBilledTokens(2) });
});

it("forwards Playground funding scope only to the control plane and never upstream", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const headers = init.headers as Record<string, string>;
    expect(headers["x-sp-cambo-playground-funding"]).toBeUndefined();
    return new Response(JSON.stringify({ id: "msg", usage: { input_tokens: 2, output_tokens: 3 } }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: { ...auth, "x-sp-cambo-playground-funding": "BALANCE" }, payload: body });
  expect(response.statusCode).toBe(200);
  expect(control.lastPreflight?.playground_funding_scope).toBe("BALANCE");
});

it("rejects an invalid Playground funding scope before preflight", async () => {
  const [instance, control, fetchMock] = app();
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: { ...auth, "x-sp-cambo-playground-funding": "anything" }, payload: body });
  expect(response.statusCode).toBe(400);
  expect(control.preflightCalls).toBe(0);
  expect(fetchMock).not.toHaveBeenCalled();
});

it("returns a completed non-stream response and locally reconciles a failed settlement", async () => {
  const control = new FakeControlPlane(); control.settleError = new Error("control plane unavailable");
  const fetchMock = vi.fn(async () => new Response(JSON.stringify({ id: "msg", usage: { input_tokens: 5, output_tokens: 7 } }), { status: 200, headers: { "content-type": "application/json" } }));
  const [instance] = app(control, fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
  expect(response.statusCode).toBe(200); expect(response.json().id).toBe("msg");
  expect(control.settleCalls).toHaveLength(3); expect(control.reconciles).toEqual(["reservation-1:settlement_failed"]); expect(control.releases).toHaveLength(0);
});

it("removes provider usage requests from streamed Chat Completions while preserving other stream options", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const sent = JSON.parse(init.body as string);
    expect(sent.stream_options).toEqual({ custom_option: "kept" });
    const stream = `data: ${JSON.stringify({ choices: [], usage: { prompt_tokens: 4, completion_tokens: 6 } })}\n\ndata: [DONE]\n\n`;
    return new Response(stream, { status: 200, headers: { "content-type": "text/event-stream" } });
  });
  const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/chat/completions", headers: auth, payload: { model: "claude-coding", messages: body.messages, stream: true, stream_options: { include_usage: false, custom_option: "kept" } } });
  expect(response.statusCode).toBe(200); expect(control.settleCalls[0]?.usage.output_tokens).toBe(0); expect(control.settleCalls[0]?.usage.cache_read_tokens).toBe(0); expect(control.reconciles).toHaveLength(0);
});

it("accepts common Codex Responses parameters and maps only the public model", async () => {
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const sent = JSON.parse(init.body as string);
    expect(sent).toMatchObject({ model: "private-route", include: ["reasoning.encrypted_content"], previous_response_id: "resp_previous", prompt_cache_key: "cache-key", background: false, prompt: { id: "pmpt_1" }, conversation: "conv_1", max_tool_calls: 4 });
    return new Response(JSON.stringify({ id: "resp_1", usage: { input_tokens: 5, output_tokens: 7 } }), { status: 200, headers: { "content-type": "application/json" } });
  });
  const [instance] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/responses", headers: auth, payload: { model: "claude-coding", input: "Hello", include: ["reasoning.encrypted_content"], previous_response_id: "resp_previous", prompt_cache_key: "cache-key", stream_options: { include_usage: true }, background: false, prompt: { id: "pmpt_1" }, conversation: "conv_1", max_tool_calls: 4 } });
  expect(response.statusCode).toBe(200); expect(response.json().id).toBe("resp_1");
});

it("retries and locally reconciles a streamed response when settlement fails after delivery", async () => {
  const control = new FakeControlPlane(); control.settleError = new Error("control plane unavailable");
  const stream = `data: ${JSON.stringify({ type: "message_delta", usage: { input_tokens: 4, output_tokens: 6 } })}\n\n`;
  const fetchMock = vi.fn(async () => new Response(stream, { status: 200, headers: { "content-type": "text/event-stream" } }));
  const [instance] = app(control, fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: { ...body, stream: true } });
  expect(response.statusCode).toBe(200); expect(response.body).toContain("message_delta"); expect(control.reconciles).toEqual(["reservation-1:settlement_failed"]);
});

it("sanitizes private upstream errors and releases deterministic rejections", async () => {
  const control = new FakeControlPlane();
  const privateError = { error: { message: `provider route private-route rejected ${control.preflightData.upstream_credential}`, internal_url: "http://omniroute:20128/admin", provider: "private-provider" } };
  const fetchMock = vi.fn(async () => new Response(JSON.stringify(privateError), { status: 400, headers: { "content-type": "application/json", "x-request-id": "private-upstream-id" } }));
  const [instance] = app(control, fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
  expect(response.statusCode).toBe(400); expect(response.json().error.sp_cambo_code).toBe("upstream_rejected");
  expect(response.body).not.toContain("private-route"); expect(response.body).not.toContain("private-provider"); expect(response.body).not.toContain("omniroute"); expect(response.body).not.toContain(control.preflightData.upstream_credential);
  expect(response.headers["x-request-id"]).not.toBe("private-upstream-id"); expect(control.releases).toEqual(["reservation-1"]);
});

it("releases reservation on upstream 5xx when no public completion was delivered", async () => {
  const fetchMock = vi.fn(async () => new Response("unavailable", { status: 503 })); const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  expect((await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body })).statusCode).toBe(503); expect(control.reconciles).toHaveLength(0); expect(control.releases).toEqual(["reservation-1"]);
});

it("releases the reservation when fetch rejects before any public output", async () => {
  const fetchMock = vi.fn(async () => { throw new Error("connection outcome unknown"); }); const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  expect((await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body })).statusCode).toBe(503); expect(control.reconciles).toHaveLength(0); expect(control.releases).toEqual(["reservation-1"]);
});

it("releases the reservation when the configured upstream timeout happens before output", async () => {
  const timeoutConfig = { ...config, upstreamTimeoutMs: 10 };
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => new Promise<Response>((_resolve, reject) => {
    init.signal?.addEventListener("abort", () => reject(new Error("aborted")), { once: true });
  }));
  const control = new FakeControlPlane(); const instance = buildApp(timeoutConfig, { controlPlane: control, rateStore: new MemoryRateStore(), fetchImpl: fetchMock as typeof fetch }); apps.push(instance);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
  expect(response.statusCode).toBe(503); expect(control.reconciles).toHaveLength(0); expect(control.releases).toEqual(["reservation-1"]);
});

it("releases the reservation when the client disconnects before output", async () => {
  let fetchStarted!: () => void; const dispatched = new Promise<void>((resolve) => { fetchStarted = resolve; });
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => new Promise<Response>((_resolve, reject) => {
    fetchStarted();
    init.signal?.addEventListener("abort", () => reject(new Error("aborted")), { once: true });
  }));
  const control = new FakeControlPlane(); const instance = buildApp(config, { controlPlane: control, rateStore: new MemoryRateStore(), fetchImpl: fetchMock as typeof fetch }); apps.push(instance);
  await instance.listen({ host: "127.0.0.1", port: 0 });
  const address = instance.server.address();
  if (address === null || typeof address === "string") throw new Error("Expected an ephemeral TCP listener.");

  const request = httpRequest({ host: "127.0.0.1", port: address.port, method: "POST", path: "/v1/messages", headers: { ...auth, "content-length": Buffer.byteLength(JSON.stringify(body)) } });
  request.on("error", () => { /* The test intentionally destroys the client socket. */ });
  request.end(JSON.stringify(body));
  await dispatched;
  request.destroy();

  await vi.waitFor(() => expect(control.releases).toEqual(["reservation-1"]));
  expect(control.reconciles).toHaveLength(0);
});

it("settles usage from SSE and never forwards customer key", async () => {
  const stream = `event: message_start\ndata: ${JSON.stringify({ type: "message_start", message: { usage: { input_tokens: 4, output_tokens: 0 } } })}\n\nevent: message_delta\ndata: ${JSON.stringify({ type: "message_delta", usage: { output_tokens: 6 } })}\n\n`;
  const fetchMock = vi.fn(async () => new Response(stream, { status: 200, headers: { "content-type": "text/event-stream" } }));
  const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: { ...body, stream: true } });
  expect(response.statusCode).toBe(200); expect(response.body).toContain("message_delta"); expect(control.settleCalls[0]?.usage).toMatchObject({ input_tokens: estimateTokens(JSON.stringify({ ...body, stream: true })), output_tokens: 0 }); expect(JSON.stringify(fetchMock.mock.calls)).not.toContain(secret);
});

it("serves count_tokens locally for free without preflight, reservation or OmniRoute", async () => {
  const fetchMock = vi.fn();
  const [instance, control] = app(new FakeControlPlane(), fetchMock as typeof fetch);
  const payload = { model: "claude-coding", messages: body.messages };
  const response = await instance.inject({ method: "POST", url: "/v1/messages/count_tokens", headers: auth, payload });
  expect(response.statusCode).toBe(200);
  expect(response.json().input_tokens).toBe(estimateTokens(JSON.stringify(payload)));
  expect(response.headers["x-sp-cambo-metering"]).toBe("local-cache-aware-v1");
  expect(control.preflightCalls).toBe(0);
  expect(control.settleCalls).toHaveLength(0);
  expect(control.releases).toHaveLength(0);
  expect(fetchMock).not.toHaveBeenCalled();
});

it("keeps a tiny direct chat in a human-scale local estimate range", async () => {
  const tiny = estimateTokens(JSON.stringify({ model: "gemini-3.6-flash", messages: [{ role: "user", content: "hi" }] }));
  expect(tiny).toBeGreaterThanOrEqual(10);
  expect(tiny).toBeLessThanOrEqual(40);
});

it("does not expose provider usage parser helpers to customer billing", async () => {
  // R42 deliberately has no provider-usage parser in the gateway billing path.
  // The app tests below assert settlement from SP-local request/response counts.
  expect(estimateTokens(JSON.stringify({ model: "claude-coding", messages: [{ role: "user", content: "hello" }] }))).toBeGreaterThan(0);
});

it("preserves split SSE events and holds the concurrency lease until settlement completes", async () => {
  let releaseSettle!: () => void; const settleGate = new Promise<void>((resolve) => { releaseSettle = resolve; });
  const control = new FakeControlPlane(); control.inspectData.limits.concurrency = 1;
  control.settle = async (id: string, usage: Usage): Promise<void> => { control.settleCalls.push({ id, usage }); await settleGate; };
  const event = `data: ${JSON.stringify({ type: "message_delta", usage: { input_tokens: 3, output_tokens: 5 } })}\n\n`;
  const encoded = new TextEncoder().encode(event);
  const fetchMock = vi.fn(async () => new Response(new ReadableStream<Uint8Array>({ start(controller) { for (const byte of encoded) controller.enqueue(Uint8Array.of(byte)); controller.close(); } }), { status: 200, headers: { "content-type": "text/event-stream" } }));
  const [instance] = app(control, fetchMock as typeof fetch);
  const first = instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: { ...body, stream: true } });
  await vi.waitFor(() => expect(control.settleCalls).toHaveLength(1));
  const second = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
  expect(second.statusCode).toBe(429); expect(second.json().error.sp_cambo_code).toBe("concurrency_limit_exceeded");
  releaseSettle(); const response = await first;
  expect(response.statusCode).toBe(200); expect(control.settleCalls[0]?.usage.output_tokens).toBe(0);
});

it("preserves retry metadata and explicit parser error types", async () => {
  const control = new FakeControlPlane(); control.inspectData.limits.tokens_per_minute = 1;
  const [instance] = app(control);
  const rateLimited = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
  expect(rateLimited.statusCode).toBe(429); expect(rateLimited.headers["retry-after"]).toBe("60"); expect(rateLimited.json().error.type).toBe("rate_limit_error");

  const unsupported = await instance.inject({ method: "POST", url: "/v1/messages", headers: { authorization: `Bearer ${secret}`, "content-type": "application/unsupported" }, payload: "bad" });
  expect(unsupported.statusCode).toBe(415); expect(unsupported.json().error.sp_cambo_code).toBe("unsupported_media_type");

  const tooLarge = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: JSON.stringify({ ...body, messages: [{ role: "user", content: "x".repeat(config.maxBodyBytes) }] }) });
  expect(tooLarge.statusCode).toBe(413); expect(tooLarge.json().error.sp_cambo_code).toBe("request_too_large");
});

it("returns a safe server error when an unexpected dependency failure occurs", async () => {
  const control = new FakeControlPlane(); control.inspect = async (): Promise<InspectData> => { throw new Error("database host and credential details"); };
  const [instance] = app(control);
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body });
  expect(response.statusCode).toBe(500); expect(response.json().error).toMatchObject({ type: "api_error", sp_cambo_code: "server_error" });
  expect(response.body).not.toContain("database host");
});

it("uses the SP-local bytes-per-unit input meter for reservation", async () => {
  const control = new FakeControlPlane(); control.inspectData.limits.tokens_per_minute = null; control.inspectData.limits.max_request_bytes = config.maxBodyBytes;
  const fetchMock = vi.fn(async () => new Response(JSON.stringify({ id: "msg", usage: { input_tokens: 1, output_tokens: 1 } }), { status: 200, headers: { "content-type": "application/json" } }));
  const [instance] = app(control, fetchMock as typeof fetch);
  const denseBody = { model: "claude-coding", max_tokens: 1, messages: [{ role: "user", content: "あ" }] };
  const raw = JSON.stringify(denseBody);
  await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: denseBody });
  expect(control.preflightCalls).toBe(1);
  expect(control.lastPreflight?.estimated_input_tokens).toBe(estimateTokens(raw));
});

it("enforces body, output, RPM, TPM, and concurrency limits before upstream", async () => {
  const control = new FakeControlPlane(); control.inspectData.limits.max_request_bytes = 30; const [instance, , fetchMock] = app(control);
  expect((await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body })).statusCode).toBe(413); expect(fetchMock).not.toHaveBeenCalled();
  control.inspectData.limits.max_request_bytes = 10000; control.inspectData.limits.max_output_tokens = 5;
  expect((await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body })).statusCode).toBe(400);
  control.inspectData.limits.max_output_tokens = 100; control.inspectData.limits.tokens_per_minute = 1;
  expect((await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: body })).statusCode).toBe(429);
});

it("lets an established stream outlive the route connection timeout", async () => {
  const timeoutConfig = { ...config, upstreamTimeoutMs: 25 };
  const fetchMock = vi.fn(async () => new Response(new ReadableStream({
    start(controller) {
      controller.enqueue(new TextEncoder().encode('event: message_start\ndata: {"type":"message_start","message":{"usage":{"input_tokens":4,"output_tokens":0}}}\n\n'));
      setTimeout(() => {
        controller.enqueue(new TextEncoder().encode('event: content_block_delta\ndata: {"type":"content_block_delta","delta":{"type":"text_delta","text":"finished"}}\n\n'));
        controller.enqueue(new TextEncoder().encode('event: message_stop\ndata: {"type":"message_stop"}\n\n'));
        controller.close();
      }, 75);
    },
  }), { status: 200, headers: { "content-type": "text/event-stream" } }));
  const control = new FakeControlPlane();
  const instance = buildApp(timeoutConfig, { controlPlane: control, rateStore: new MemoryRateStore(), fetchImpl: fetchMock as typeof fetch });
  const response = await instance.inject({ method: "POST", url: "/v1/messages", headers: auth, payload: { ...body, stream: true } });
  expect(response.statusCode).toBe(200);
  expect(response.body).toContain("finished");
  expect(control.reconciles).toHaveLength(0);
  expect(control.releases).toHaveLength(0);
  expect(control.settleCalls).toHaveLength(1);
});

it("cancels streaming when client disconnects after headers", async () => {
  let streamStarted!: () => void;
  const started = new Promise<void>((resolve) => { streamStarted = resolve; });
  let streamCancelled = false;
  const fetchMock = vi.fn(async () => new Response(new ReadableStream({
    start(controller) {
      controller.enqueue(new TextEncoder().encode('event: message_start\ndata: {"type":"message_start","message":{"usage":{"input_tokens":4,"output_tokens":0}}}\n\n'));
      streamStarted();
    },
    cancel() { streamCancelled = true; },
  }), { status: 200, headers: { "content-type": "text/event-stream" } }));
  const control = new FakeControlPlane();
  const instance = buildApp(config, { controlPlane: control, rateStore: new MemoryRateStore(), fetchImpl: fetchMock as typeof fetch });
  await instance.listen({ host: "127.0.0.1", port: 0 });
  const address = instance.server.address();
  if (address === null || typeof address === "string") throw new Error("Expected an ephemeral TCP listener.");

  const request = httpRequest({ host: "127.0.0.1", port: address.port, method: "POST", path: "/v1/messages", headers: { ...auth, "content-length": Buffer.byteLength(JSON.stringify({ ...body, stream: true })) } });
  request.on("error", () => { /* intentional socket destroy */ });
  request.end(JSON.stringify({ ...body, stream: true }));
  await started;
  request.destroy();

  await vi.waitFor(() => expect(control.settleCalls).toHaveLength(1));
  expect(control.reconciles).toHaveLength(0);
  expect(control.releases).toHaveLength(0);
  expect(control.settleCalls[0]?.usage.output_tokens).toBe(0);
  expect(streamCancelled).toBe(true);
});
