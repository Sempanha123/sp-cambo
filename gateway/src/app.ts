import { createHash } from "node:crypto";
import { once } from "node:events";
import Fastify, { type FastifyInstance, type FastifyReply, type FastifyRequest } from "fastify";
import { customerKey } from "./auth.js";
import { GatewayError, writeError } from "./errors.js";
import { mergeUsage, prepare, upstreamBody, usageFromJson } from "./protocol.js";
import type { ControlPlane, Fetch, GatewayConfig, InferencePath, RateStore, Usage } from "./types.js";
import { INFERENCE_PATHS } from "./types.js";

export type Dependencies = { controlPlane: ControlPlane; rateStore: RateStore; fetchImpl?: Fetch };

export function buildApp(config: GatewayConfig, dependencies: Dependencies): FastifyInstance {
  const app = Fastify({ logger: false, bodyLimit: config.maxBodyBytes });
  const fetchImpl = dependencies.fetchImpl ?? globalThis.fetch;

  app.addContentTypeParser("application/json", { parseAs: "string" }, (_request, body, done) => done(null, body));
  app.addHook("onClose", async () => dependencies.rateStore.close());
  app.get("/health", async () => ({ data: { status: "ok" } }));
  app.get("/v1/models", async (request) => {
    const key = customerKey(request); await dependencies.rateStore.admit(admissionIdentity(key), 60);
    return models(await dependencies.controlPlane.inspect(key));
  });
  app.get("/v1/key/status", async (request) => {
    const key = customerKey(request); await dependencies.rateStore.admit(admissionIdentity(key), 60);
    return status(await dependencies.controlPlane.inspect(key));
  });

  for (const path of INFERENCE_PATHS) {
    app.post(path, async (request, reply) => inference(path, request, reply));
  }

  app.setErrorHandler((error, request, reply) => {
    if (reply.sent) return;
    const known = error instanceof GatewayError ? error : fastifyError(error as Error & { statusCode?: number; code?: string });
    writeError(reply, known, request.url.startsWith("/v1/messages"));
  });

  async function inference(path: InferencePath, request: FastifyRequest, reply: FastifyReply): Promise<unknown> {
    const key = customerKey(request);
    await dependencies.rateStore.admit(admissionIdentity(key), 120);
    const raw = typeof request.body === "string" ? request.body : JSON.stringify(request.body ?? {});
    const bytes = Buffer.byteLength(raw);
    const prepared = prepare(path, raw, config.defaultMaxOutputTokens);
    const inspection = await dependencies.controlPlane.inspect(key);
    const keyCap = inspection.limits.max_request_bytes;
    if (bytes > config.maxBodyBytes || (keyCap !== null && bytes > keyCap)) throw new GatewayError(413, "request_too_large", "The request exceeds the allowed size.");
    const outputCap = inspection.limits.max_output_tokens;
    if (outputCap !== null && prepared.requestedMaxOutput > outputCap) throw new GatewayError(400, "max_output_tokens_exceeded", "The requested output exceeds the API key limit.");
    const estimatedTotal = prepared.estimatedInput + prepared.requestedMaxOutput;
    const lease = await dependencies.rateStore.acquire(inspection.key_id, inspection.limits, estimatedTotal);
    let reservationId: string | null = null;
    try {
      const preflight = await dependencies.controlPlane.preflight({
        customer_key: key, public_model: prepared.publicModel, estimated_input_tokens: prepared.estimatedInput,
        requested_max_output_tokens: prepared.requestedMaxOutput, request_bytes: bytes, request_id: prepared.requestId,
        request_fingerprint: prepared.fingerprint, endpoint: path,
      });
      reservationId = preflight.reservation_id;
      const controller = new AbortController();
      const onDisconnect = (): void => controller.abort("client_disconnect");
      request.raw.once("aborted", onDisconnect);
      reply.raw.once("close", onDisconnect);
      const upstreamTimeoutMs = Math.min(
        Math.max(preflight.upstream_timeout_ms || config.upstreamTimeoutMs, 1000),
        600_000,
      );
      const timeout = setTimeout(() => controller.abort("upstream_timeout"), upstreamTimeoutMs);
      let upstream: Response;
      try {
        const internalModel = preflight.internal_model;
        const routeVersion = preflight.route_version;
        const upstreamOrigin = preflight.upstream_origin.replace(/\/+$/, "");

        upstream = await fetchImpl(`${upstreamOrigin}${path}`, {
          method: "POST",
          headers: {
            ...upstreamHeaders(request, preflight.upstream_credential, preflight.correlation_id),
            "x-route-revision": preflight.route_revision_id ?? "",
            "x-route-version": routeVersion?.toString() ?? "",
          },
          body: upstreamBody(path, prepared, internalModel, preflight.max_output_tokens),
          signal: controller.signal,
        });
      } catch {
        const reason = controller.signal.reason === "client_disconnect" ? "client_disconnect" : "upstream_timeout";
        await reconcileBestEffort(reservationId, reason);
        throw new GatewayError(reason === "client_disconnect" ? 499 : 503, reason === "client_disconnect" ? "client_disconnected" : "upstream_unavailable", reason === "client_disconnect" ? "The client disconnected." : "The inference service is temporarily unavailable.");
      } finally {
        clearTimeout(timeout);
        request.raw.off("aborted", onDisconnect);
        reply.raw.off("close", onDisconnect);
      }
      if (!upstream.ok) {
        if (upstream.status >= 500 || upstream.status === 408 || upstream.status === 429) {
          await dependencies.controlPlane.reconcile(reservationId, "usage_unavailable");
        } else {
          await dependencies.controlPlane.release(reservationId);
        }
        return proxyError(reply, upstream, path);
      }
      if (prepared.streaming && upstream.body) return await stream(reply, upstream, reservationId, path, preflight.correlation_id);
      return await json(reply, upstream, reservationId, path);
    } finally { await lease.release(); }
  }

  async function json(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath): Promise<unknown> {
    const text = await upstream.text();
    let parsed: unknown;
    try { parsed = JSON.parse(text); } catch {
      await dependencies.controlPlane.reconcile(reservationId, "usage_unavailable");
      throw new GatewayError(502, "upstream_invalid_response", "The inference service returned an invalid response.");
    }
    const usage = usageFromJson(parsed, path);
    if (!usage) {
      await dependencies.controlPlane.reconcile(reservationId, "usage_unavailable");
      throw new GatewayError(502, "billing_settlement_pending", "Usage settlement is pending reconciliation.");
    }
    copyResponseHeaders(reply, upstream);
    reply.status(upstream.status).send(parsed);
    try {
      await dependencies.controlPlane.settle(reservationId, { ...usage, duration_ms: 0 });
    } catch {
      await reconcileBestEffort(reservationId, "settlement_failed");
    }
    return reply;

  }

  async function stream(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestId: string): Promise<void> {
    reply.hijack();
    reply.raw.statusCode = upstream.status;
    reply.raw.setHeader("content-type", upstream.headers.get("content-type") ?? "text/event-stream");
    reply.raw.setHeader("cache-control", "no-store");
    reply.raw.setHeader("x-request-id", requestId);
    const started = Date.now(); let usage: Usage | null = null; let buffer = ""; let bytesSent = false;
    const reader = upstream.body!.getReader(); const decoder = new TextDecoder();
    try {
      while (true) {
        const { value, done } = await reader.read(); if (done) break;
        bytesSent = true;
        buffer += decoder.decode(value, { stream: true });
        const boundary = Math.max(buffer.lastIndexOf("\n\n"), buffer.lastIndexOf("\r\n\r\n"));
        if (boundary !== -1) {
          const delimiterLength = buffer.startsWith("\r\n\r\n", boundary) ? 4 : 2;
          usage = parseSse(buffer.slice(0, boundary + delimiterLength), usage, path);
          buffer = buffer.slice(boundary + delimiterLength);
        }
        if (!reply.raw.write(value)) await once(reply.raw, "drain");
      }
      buffer += decoder.decode();
      if (buffer !== "") usage = parseSse(buffer, usage, path);
    } catch {
      if (!reply.raw.destroyed) reply.raw.destroy();
      await reconcileBestEffort(reservationId, bytesSent ? "upstream_disconnect" : "upstream_timeout");
      return;
    }

    reply.raw.end();
    if (!usage) {
      await reconcileBestEffort(reservationId, bytesSent ? "usage_unavailable" : "upstream_disconnect");
      return;
    }
    try {
      await dependencies.controlPlane.settle(reservationId, { ...usage, duration_ms: Date.now() - started });
    } catch {
      await reconcileBestEffort(reservationId, "settlement_failed");
    }
  }

  async function reconcileBestEffort(reservationId: string, reason: string): Promise<void> {
    try { await dependencies.controlPlane.reconcile(reservationId, reason); } catch { /* Stale recovery preserves the reservation if billing is unavailable. */ }
  }

  return app;
}

function admissionIdentity(key: string): string {
  // Never place the customer credential itself in limiter keys or logs.
  return createHash("sha256").update(key).digest("hex");
}

function upstreamHeaders(request: FastifyRequest, apiKey: string, correlation: string): Record<string, string> {
  const headers: Record<string, string> = { authorization: `Bearer ${apiKey}`, "x-api-key": apiKey, "content-type": "application/json", accept: "application/json, text/event-stream", "x-request-id": correlation };
  const anthropicVersion = request.headers["anthropic-version"];
  if (typeof anthropicVersion === "string" && /^[0-9-]{10}$/.test(anthropicVersion)) headers["anthropic-version"] = anthropicVersion;
  return headers;
}

function parseSse(buffer: string, current: Usage | null, path: InferencePath): Usage | null {
  for (const event of buffer.replaceAll("\r\n", "\n").split("\n\n")) for (const line of event.split("\n")) if (line.startsWith("data:")) {
    const data = line.slice(5).trim(); if (data === "[DONE]") continue;
    try { current = mergeUsage(current, usageFromJson(JSON.parse(data), path)); } catch { /* incomplete/invalid event */ }
  }
  return current;
}

async function proxyError(_reply: FastifyReply, upstream: Response, path: InferencePath): Promise<never> {
  // Drain the private upstream body without ever treating it as a public error
  // contract. OmniRoute/provider diagnostics can contain routes, model IDs,
  // hosts, or credentials and must not cross this boundary.
  try { await upstream.body?.cancel(); } catch { /* response is already terminal */ }
  const serverFailure = upstream.status >= 500 || upstream.status === 408 || upstream.status === 429;
  throw new GatewayError(
    serverFailure ? 503 : upstream.status,
    serverFailure ? "upstream_unavailable" : "upstream_rejected",
    path.startsWith("/v1/messages") ? "The upstream request was rejected." : "The inference request was rejected.",
    upstream.status === 429 ? { "retry-after": "1" } : {},
  );
}

function copyResponseHeaders(reply: FastifyReply, upstream: Response): void {
  for (const name of ["content-type", "request-id", "x-request-id", "anthropic-ratelimit-requests-limit", "anthropic-ratelimit-tokens-limit"]) {
    const value = upstream.headers.get(name); if (value) reply.header(name, value);
  }
  reply.header("cache-control", "no-store");
}

function models(data: Awaited<ReturnType<ControlPlane["inspect"]>>): object { return { object: "list", data: data.allowed_models.map((model) => ({ id: model.id, object: "model", created: 0, owned_by: "sp-cambo", display_name: model.display_name, capabilities: model.capabilities })) }; }
function status(data: Awaited<ReturnType<ControlPlane["inspect"]>>): object { return { valid: true, status: data.status, expires_at: data.expires_at, allowed_model_aliases: data.allowed_models.map((m) => m.id), token_quota_remaining: data.balances.token_quota_remaining, credit_remaining: data.balances.credit_remaining, limits: data.limits, service_status: data.service_status }; }
function fastifyError(error: Error & { statusCode?: number; code?: string }): GatewayError {
  if (error.statusCode === 413) return new GatewayError(413, "request_too_large", "The request exceeds the allowed size.");
  if (error.statusCode === 415) return new GatewayError(415, "unsupported_media_type", "The request content type is not supported.");
  if (error.statusCode !== undefined && error.statusCode >= 400 && error.statusCode < 500) return new GatewayError(error.statusCode, "invalid_request", "The request is invalid.");
  return new GatewayError(500, "server_error", "An unexpected server error occurred.");
}
