import { createHash } from "node:crypto";
import { once } from "node:events";
import Fastify, { type FastifyInstance, type FastifyReply, type FastifyRequest } from "fastify";
import { customerKey } from "./auth.js";
import { GatewayError, writeError } from "./errors.js";
import { LocalPromptCache } from "./local-prompt-cache.js";
import { localizeSseUsage, prepare, spLocalOutputTokensFromSse, spLocalUsage, spLocalUsageFromOutputTokens, upstreamBody, withLocalUsage } from "./protocol.js";
import { buildToolNameMap, normalizeToolNames, rewriteSseToolNames, type ToolNameMap } from "./tool-names.js";
import type { ControlPlane, Fetch, GatewayConfig, InferencePath, RateStore } from "./types.js";
import { INFERENCE_PATHS } from "./types.js";

export type Dependencies = { controlPlane: ControlPlane; rateStore: RateStore; fetchImpl?: Fetch };

export function buildApp(config: GatewayConfig, dependencies: Dependencies): FastifyInstance {
  const app = Fastify({ logger: false, bodyLimit: config.maxBodyBytes });
  const fetchImpl = dependencies.fetchImpl ?? globalThis.fetch;
  const promptCache = new LocalPromptCache();

  app.addContentTypeParser("application/json", { parseAs: "string" }, (_request, body, done) => done(null, body));
  app.addHook("onClose", async () => dependencies.rateStore.close());
  app.get("/health", async () => ({ data: { status: "ok", model_routing: "database_internal_model_id", build: "r42-local-cache-metering" } }));
  app.get("/ready", async (_request, reply) => {
    try {
      const response = await fetchImpl(`${config.controlPlaneBaseUrl}/api/v1/health`, {
        method: "GET",
        headers: { accept: "application/json" },
        signal: AbortSignal.timeout(config.controlPlaneTimeoutMs),
      });
      if (!response.ok) {
        reply.code(503);
        return { data: { status: "not_ready", build: "r42-local-cache-metering", control_plane: "unavailable" } };
      }
      return { data: { status: "ready", build: "r42-local-cache-metering", control_plane: "ready", model_routing: "database_internal_model_id" } };
    } catch {
      reply.code(503);
      return { data: { status: "not_ready", build: "r42-local-cache-metering", control_plane: "unavailable" } };
    }
  });
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
    const correlation = request.headers["x-request-id"];
    if (typeof correlation === "string" && /^[A-Za-z0-9._:-]{1,191}$/.test(correlation)) {
      reply.header("x-request-id", correlation);
    }
    const known = error instanceof GatewayError ? error : fastifyError(error as Error & { statusCode?: number; code?: string });
    writeError(reply, known, request.url.startsWith("/v1/messages"));
  });

  async function inference(path: InferencePath, request: FastifyRequest, reply: FastifyReply): Promise<unknown> {
    const requestStartedAt = Date.now();
    const key = customerKey(request);
    await dependencies.rateStore.admit(admissionIdentity(key), 120);
    const raw = typeof request.body === "string" ? request.body : JSON.stringify(request.body ?? {});
    const bytes = Buffer.byteLength(raw);
    const prepared = prepare(path, raw, config.defaultMaxOutputTokens);
    const toolNames = buildToolNameMap(prepared.body);
    const inspection = await dependencies.controlPlane.inspect(key);
    const keyCap = inspection.limits.max_request_bytes;
    if (bytes > config.maxBodyBytes || (keyCap !== null && bytes > keyCap)) throw new GatewayError(413, "request_too_large", "The request exceeds the allowed size.");
    // /v1/messages/count_tokens is a local utility endpoint. It validates the
    // customer key/model but never reserves balance and never calls OmniRoute.
    if (path === "/v1/messages/count_tokens") {
      const allowed = inspection.allowed_models.find((model) => model.id === prepared.publicModel);
      if (!allowed) throw new GatewayError(403, "model_not_allowed", "The model is not allowed for this key.");
      if (allowed.capabilities.messages_api !== true) throw new GatewayError(400, "model_unavailable", "The model does not support this inference protocol.");
      reply.header("x-sp-cambo-metering", "local-cache-aware-v1");
      return reply.status(200).send({ input_tokens: prepared.estimatedInput });
    }

    const localInput = promptCache.measure(
      inspection.key_id,
      path,
      prepared.publicModel,
      prepared.promptSegments,
      prepared.estimatedInput,
    );

    const outputCap = inspection.limits.max_output_tokens;
    if (outputCap !== null && prepared.requestedMaxOutput > outputCap) throw new GatewayError(400, "max_output_tokens_exceeded", "The requested output exceeds the API key limit.");
    const estimatedTotal = prepared.estimatedInput + prepared.requestedMaxOutput;
    const lease = await dependencies.rateStore.acquire(inspection.key_id, inspection.limits, estimatedTotal);
    let reservationId: string | null = null;
    try {
      const playgroundFundingScope = fundingScope(request);
      const preflight = await dependencies.controlPlane.preflight({
        customer_key: key, public_model: prepared.publicModel, estimated_input_tokens: localInput.input_tokens,
        estimated_cache_read_tokens: localInput.cache_read_tokens,
        requested_max_output_tokens: prepared.requestedMaxOutput, request_bytes: bytes, request_id: prepared.requestId,
        request_fingerprint: prepared.fingerprint, endpoint: path,
        ...(playgroundFundingScope ? { playground_funding_scope: playgroundFundingScope } : {}),
      });
      reservationId = preflight.reservation_id;
      void markStateBestEffort(reservationId, "CONNECTING");
      const clientController = new AbortController();
      const onRequestAborted = (): void => clientController.abort("client_disconnect");
      const onResponseClose = (): void => {
        if (!reply.raw.writableEnded) clientController.abort("client_disconnect");
      };
      request.raw.once("aborted", onRequestAborted);
      reply.raw.once("close", onResponseClose);

      let route = {
        internal_model: preflight.internal_model,
        route_revision_id: preflight.route_revision_id,
        route_version: preflight.route_version,
        upstream_origin: preflight.upstream_origin,
        upstream_credential: preflight.upstream_credential,
        upstream_timeout_ms: preflight.upstream_timeout_ms,
      };

      try {
        while (true) {
          const controller = new AbortController();
          const forwardClientAbort = (): void => controller.abort("client_disconnect");

          if (clientController.signal.aborted) {
            controller.abort("client_disconnect");
          } else {
            clientController.signal.addEventListener("abort", forwardClientAbort, { once: true });
          }

          const routeTimeoutMs = route.upstream_timeout_ms || config.upstreamTimeoutMs;
          const upstreamTimeoutMs = Math.min(
            Math.max(routeTimeoutMs, 1000),
            Math.max(config.upstreamTimeoutMs, 1000),
            600_000,
          );
          const timeout = setTimeout(() => controller.abort("upstream_timeout"), upstreamTimeoutMs);

          let upstream: Response;
          try {
            const upstreamOrigin = route.upstream_origin.replace(/\/+$/, "");
            const fetchPromise = fetchImpl(`${upstreamOrigin}${path}`, {
              method: "POST",
              headers: {
                ...upstreamHeaders(request, route.upstream_credential, preflight.correlation_id),
                "x-route-revision": route.route_revision_id ?? "",
                "x-route-version": route.route_version?.toString() ?? "",
              },
              body: upstreamBody(path, prepared, route.internal_model, preflight.max_output_tokens),
              signal: controller.signal,
            });

            upstream = await abortable(fetchPromise, controller.signal);
          } catch {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);

            const reason = abortReason(controller.signal);
            if (reason === "client_disconnect") {
              await releaseBestEffort(reservationId);
              throw operationFailure(reason);
            }

            const next = await rerouteBestEffort(
              reservationId,
              reason === "upstream_timeout" ? "upstream_timeout" : "upstream_connect_error",
            );

            if (next) {
              route = next;
              continue;
            }

            await releaseBestEffort(reservationId);
            throw operationFailure(reason ?? "upstream_connect_error");
          }

          if (!upstream.ok && failoverStatus(upstream.status)) {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);
            try { await upstream.body?.cancel(); } catch { /* current route is finished */ }

            const next = await rerouteBestEffort(
              reservationId,
              `upstream_http_${upstream.status}`,
              upstream.status,
            );

            if (next) {
              route = next;
              continue;
            }

            await releaseBestEffort(reservationId);
            return proxyError(reply, upstream, path);
          }

          if (!upstream.ok) {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);
            await releaseBestEffort(reservationId);
            return proxyError(reply, upstream, path);
          }

          // A successful response header is enough to mark the selected route
          // healthy again. This best-effort telemetry never blocks the customer.
          void routeSuccessBestEffort(reservationId);

          // Once a streaming response has usable headers, the route timeout has
          // served its purpose. Never switch providers after public output starts.
          if (prepared.streaming && upstream.body) {
            clearTimeout(timeout);
          }

          promptCache.remember(
            inspection.key_id,
            path,
            prepared.publicModel,
            prepared.promptSegments,
          );
          void markStateBestEffort(reservationId, "STREAMING");

          try {
            if (prepared.streaming && upstream.body) {
              return await stream(
                reply,
                upstream,
                reservationId,
                path,
                preflight.correlation_id,
                requestStartedAt,
                controller.signal,
                toolNames,
                localInput.input_tokens,
                localInput.cache_read_tokens,
              );
            }

            return await json(
              reply,
              upstream,
              reservationId,
              path,
              requestStartedAt,
              controller.signal,
              toolNames,
              localInput.input_tokens,
              localInput.cache_read_tokens,
            );
          } finally {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);
          }
        }
      } finally {
        request.raw.off("aborted", onRequestAborted);
        reply.raw.off("close", onResponseClose);
      }
    } finally { await lease.release(); }
  }

  async function json(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestStartedAt: number, signal: AbortSignal, toolNames: ToolNameMap, localInputTokens: number, localCacheReadTokens: number): Promise<unknown> {
    let text: string;
    try {
      text = await abortable(upstream.text(), signal);
    } catch {
      const reason = abortReason(signal) ?? "upstream_disconnect";
      await releaseBestEffort(reservationId);
      throw operationFailure(reason);
    }
    let parsed: unknown;
    try { parsed = JSON.parse(text); } catch {
      await releaseBestEffort(reservationId);
      throw new GatewayError(502, "upstream_invalid_response", "The inference service returned an invalid response.");
    }
    const normalizedResponse = normalizeToolNames(parsed, toolNames);
    // R29: customer settlement is measured entirely at the SP Cambo edge.
    // Provider/OmniRoute usage metadata and usage headers are intentionally
    // ignored for billing. They may remain in the proxied response for client
    // compatibility, but they cannot change the customer's SP balance.
    const usage = spLocalUsage(localInputTokens, localCacheReadTokens, normalizedResponse, JSON.stringify(normalizedResponse));
    const publicResponse = withLocalUsage(normalizedResponse, path, usage);
    await settleLocalBestEffort(reservationId, usage, Date.now() - requestStartedAt);
    copyResponseHeaders(reply, upstream);
    reply.header("x-sp-cambo-metering", "local-cache-aware-v1");
    reply.status(upstream.status).send(publicResponse);
    return reply;
  }

  async function stream(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestId: string, requestStartedAt: number, signal: AbortSignal, toolNames: ToolNameMap, localInputTokens: number, localCacheReadTokens: number): Promise<void> {
    reply.hijack();
    reply.raw.statusCode = upstream.status;
    reply.raw.setHeader("content-type", upstream.headers.get("content-type") ?? "text/event-stream");
    reply.raw.setHeader("cache-control", "no-store");
    reply.raw.setHeader("x-request-id", requestId);
    let buffer = ""; let bytesSent = false; let localOutputTokens = 0;
    const reader = upstream.body!.getReader(); const decoder = new TextDecoder();
    const writePublic = async (text: string): Promise<void> => {
      if (text === "") return;
      const publicText = rewriteSseToolNames(text, toolNames);
      bytesSent = true;
      if (!reply.raw.write(publicText)) await abortable(once(reply.raw, "drain"), signal);
    };
    try {
      while (true) {
        const { value, done } = await abortable(reader.read(), signal); if (done) break;
        buffer += decoder.decode(value, { stream: true });
        while (true) {
          const frame = takeSseFrame(buffer);
          if (!frame) break;
          localOutputTokens += spLocalOutputTokensFromSse(frame.complete);
          const localUsage = spLocalUsageFromOutputTokens(localInputTokens, localCacheReadTokens, localOutputTokens);
          await writePublic(localizeSseUsage(frame.complete, path, localUsage));
          buffer = frame.remainder;
        }
      }
      buffer += decoder.decode();
      if (buffer !== "") {
        localOutputTokens += spLocalOutputTokensFromSse(buffer);
        const localUsage = spLocalUsageFromOutputTokens(localInputTokens, localCacheReadTokens, localOutputTokens);
        await writePublic(localizeSseUsage(buffer, path, localUsage));
      }
    } catch {
      void reader.cancel(signal.reason).catch(() => undefined);
      if (!reply.raw.destroyed) reply.raw.destroy();
      const reason = abortReason(signal) ?? (bytesSent ? "upstream_disconnect" : "upstream_timeout");
      if (bytesSent) {
        const partialUsage = spLocalUsageFromOutputTokens(localInputTokens, localCacheReadTokens, localOutputTokens);
        await settleLocalBestEffort(reservationId, partialUsage, Date.now() - requestStartedAt);
      } else {
        await releaseBestEffort(reservationId);
      }
      return;
    }

    const usage = spLocalUsageFromOutputTokens(localInputTokens, localCacheReadTokens, localOutputTokens);
    await settleLocalBestEffort(reservationId, usage, Date.now() - requestStartedAt);
    reply.raw.end();
  }

  async function markStateBestEffort(reservationId: string, state: "CONNECTING" | "STREAMING"): Promise<void> {
    if (!dependencies.controlPlane.state) return;
    try { await dependencies.controlPlane.state(reservationId, state); } catch { /* Observability must never block inference. */ }
  }

  async function settleLocalBestEffort(reservationId: string, usage: ReturnType<typeof spLocalUsageFromOutputTokens>, durationMs: number): Promise<void> {
    // The control plane is local to SP Cambo. Retry short transient failures
    // before placing the reservation into local reconciliation.
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        await dependencies.controlPlane.settle(reservationId, { ...usage, duration_ms: durationMs });
        return;
      } catch {
        if (attempt < 2) await new Promise((resolve) => setTimeout(resolve, 75 * (attempt + 1)));
      }
    }
    try {
      await dependencies.controlPlane.reconcile(reservationId, "settlement_failed", { ...usage, duration_ms: durationMs });
    } catch {
      // If the local control plane itself is down, the reservation remains held
      // for the normal recovery job; no provider usage is needed to resolve it.
    }
  }

  async function rerouteBestEffort(
    reservationId: string,
    failureCode: string,
    upstreamStatus?: number,
  ) {
    if (!dependencies.controlPlane.reroute) return null;

    try {
      return await dependencies.controlPlane.reroute(reservationId, {
        failure_code: failureCode,
        ...(upstreamStatus !== undefined ? { upstream_status: upstreamStatus } : {}),
      });
    } catch {
      // Keep the original upstream failure when no alternate route is available.
      return null;
    }
  }

  async function routeSuccessBestEffort(reservationId: string): Promise<void> {
    if (!dependencies.controlPlane.routeSuccess) return;
    try {
      await dependencies.controlPlane.routeSuccess(reservationId);
    } catch {
      // Route-health telemetry must never block successful inference.
    }
  }

  async function releaseBestEffort(reservationId: string): Promise<void> {
    try { await dependencies.controlPlane.release(reservationId); } catch {
      try { await dependencies.controlPlane.reconcile(reservationId, "usage_unavailable"); } catch { /* local recovery will handle stale hold */ }
    }
  }

  return app;
}


function takeSseFrame(buffer: string): { complete: string; remainder: string } | null {
  const lf = buffer.indexOf("\n\n");
  const crlf = buffer.indexOf("\r\n\r\n");
  let boundary = -1;
  let delimiterLength = 0;
  if (lf !== -1 && (crlf === -1 || lf < crlf)) { boundary = lf; delimiterLength = 2; }
  else if (crlf !== -1) { boundary = crlf; delimiterLength = 4; }
  if (boundary === -1) return null;
  const end = boundary + delimiterLength;
  return { complete: buffer.slice(0, end), remainder: buffer.slice(end) };
}

function fundingScope(request: FastifyRequest): "DAILY" | "BALANCE" | undefined {
  const value = request.headers["x-sp-cambo-playground-funding"];
  if (value === undefined) return undefined;
  if (typeof value !== "string") throw new GatewayError(400, "invalid_request", "The Playground funding scope is invalid.");
  const normalized = value.toUpperCase();
  if (normalized !== "DAILY" && normalized !== "BALANCE") throw new GatewayError(400, "invalid_request", "The Playground funding scope is invalid.");
  return normalized;
}

function abortReason(signal: AbortSignal): "client_disconnect" | "upstream_timeout" | undefined {
  if (signal.reason === "client_disconnect") return "client_disconnect";
  if (signal.reason === "upstream_timeout") return "upstream_timeout";
  return undefined;
}

function abortable<T>(promise: Promise<T>, signal: AbortSignal): Promise<T> {
  if (signal.aborted) return Promise.reject(signal.reason);
  return new Promise<T>((resolve, reject) => {
    const onAbort = (): void => reject(signal.reason);
    signal.addEventListener("abort", onAbort, { once: true });
    promise.then(resolve, reject).finally(() => signal.removeEventListener("abort", onAbort));
  });
}

function failoverStatus(status: number): boolean {
  return status === 408
    || status === 429
    || status === 500
    || status === 502
    || status === 503
    || status === 504;
}

function operationFailure(reason: string): GatewayError {
  if (reason === "client_disconnect") return new GatewayError(499, "client_disconnected", "The client disconnected.");
  return new GatewayError(503, "upstream_unavailable", "The inference service is temporarily unavailable.");
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
  // Do not expose private OmniRoute/provider request IDs, usage limits, or
  // billing-adjacent telemetry. Only the public payload content type crosses
  // this boundary; SP Cambo supplies its own metering header separately.
  const contentType = upstream.headers.get("content-type");
  if (contentType) reply.header("content-type", contentType);
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
