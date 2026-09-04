import { createHash, randomUUID } from "node:crypto";
import { GatewayError } from "./errors.js";
import type { InferencePath, Usage } from "./types.js";

/**
 * SP Cambo local-only output calibration.
 *
 * This is NOT provider usage and NEVER reads OmniRoute token counters.
 * The local text estimator is intentionally conservative on generated output
 * because code/HTML and hidden tokenizer differences can otherwise under-meter
 * customer usage. Input/cache estimation is unchanged.
 *
 * 15_000 bps = 1.50x locally measured generated output.
 */
export const LOCAL_OUTPUT_CALIBRATION_BPS = 15_000;
const LOCAL_OUTPUT_CALIBRATION_SCALE = 10_000;

export function localOutputBilledTokens(rawTokens: number): number {
  const tokens = Math.max(0, Math.trunc(rawTokens));
  if (tokens === 0) return 0;
  return Math.ceil((tokens * LOCAL_OUTPUT_CALIBRATION_BPS) / LOCAL_OUTPUT_CALIBRATION_SCALE);
}

const FIELDS: Record<InferencePath, ReadonlySet<string>> = {
  "/v1/messages": new Set(["model", "messages", "system", "max_tokens", "metadata", "stop_sequences", "stream", "temperature", "thinking", "tool_choice", "tools", "top_k", "top_p", "service_tier", "context_management", "output_config"]),
  "/v1/messages/count_tokens": new Set(["model", "messages", "system", "thinking", "tool_choice", "tools", "context_management", "output_config"]),
  "/v1/responses": new Set(["model", "input", "instructions", "max_output_tokens", "metadata", "parallel_tool_calls", "reasoning", "service_tier", "store", "stream", "temperature", "text", "tool_choice", "tools", "top_logprobs", "top_p", "truncation", "user", "include", "previous_response_id", "prompt_cache_key", "stream_options", "background", "prompt", "conversation", "max_tool_calls"]),
  "/v1/chat/completions": new Set(["model", "messages", "max_completion_tokens", "max_tokens", "metadata", "n", "parallel_tool_calls", "presence_penalty", "frequency_penalty", "reasoning_effort", "response_format", "seed", "service_tier", "stop", "store", "stream", "stream_options", "temperature", "tool_choice", "tools", "top_logprobs", "top_p", "user"]),
};

export type PromptSegment = { digest: string; tokens: number };

export type Prepared = {
  publicModel: string;
  requestedMaxOutput: number;
  estimatedInput: number;
  promptSegments: PromptSegment[];
  streaming: boolean;
  fingerprint: string;
  requestId: string;
  body: Record<string, unknown>;
};

export function prepare(path: InferencePath, raw: string, defaultMaxOutput: number): Prepared {
  let parsed: unknown;
  try { parsed = JSON.parse(raw); } catch { throw new GatewayError(400, "invalid_request", "The request body must be valid JSON."); }
  if (!record(parsed)) throw new GatewayError(400, "invalid_request", "The request body must be a JSON object.");
  for (const key of Object.keys(parsed)) {
    if (!FIELDS[path].has(key)) throw new GatewayError(400, "unsupported_parameter", `The parameter '${key}' is not supported.`);
  }
  const model = parsed.model;
  if (typeof model !== "string" || !/^[a-zA-Z0-9._:-]{1,100}$/.test(model)) throw new GatewayError(400, "invalid_model", "A valid public model alias is required.");
  const requested = outputTokens(path, parsed, defaultMaxOutput);
  const streaming = parsed.stream === true;
  const promptSegments = promptSegmentsFor(parsed);
  const estimated = promptSegments.reduce((sum, segment) => sum + segment.tokens, 0);
  if (!Number.isSafeInteger(estimated)) {
    throw new GatewayError(413, "request_too_large", "The request exceeds the supported size.");
  }
  return {
    publicModel: model,
    requestedMaxOutput: requested,
    estimatedInput: Math.max(0, estimated),
    promptSegments,
    streaming,
    fingerprint: createHash("sha256").update(raw).digest("hex"),
    requestId: randomUUID(),
    body: parsed,
  };
}

export function upstreamBody(path: InferencePath, prepared: Prepared, internalModel: string, hardMax: number): string {
  const body: Record<string, unknown> = { ...prepared.body, model: internalModel };
  // Claude Code may send Anthropic-only compatibility extensions even when a
  // customer routes the Anthropic Messages API to a non-Anthropic private model.
  // Accept these fields at SP Cambo's public edge, then remove them before the
  // private route so Gemini/OpenAI-style adapters do not reject the request.
  if (path === "/v1/messages" || path === "/v1/messages/count_tokens") {
    delete body.context_management;
    delete body.output_config;
  }
  if (path === "/v1/messages") body.max_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
  if (path === "/v1/responses") body.max_output_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
  if (path === "/v1/chat/completions") {
    if ("max_completion_tokens" in body) body.max_completion_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
    else body.max_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
  }
  // R42 does not request provider/OmniRoute usage telemetry. Customer-facing
  // usage is derived only from SP Cambo's local request/response meter.
  if (record(body.stream_options)) {
    const options = { ...body.stream_options };
    delete options.include_usage;
    if (Object.keys(options).length === 0) delete body.stream_options;
    else body.stream_options = options;
  }
  return JSON.stringify(body);
}

// Provider/OmniRoute usage parsers intentionally do not exist in R42.
// Customer metering is derived exclusively from the request received and the
// response content delivered at the SP Cambo public gateway.

function outputTokens(path: InferencePath, body: Record<string, unknown>, fallback: number): number {
  if (path === "/v1/messages/count_tokens") return 0;
  const value = path === "/v1/messages" ? body.max_tokens : path === "/v1/responses" ? body.max_output_tokens : body.max_completion_tokens ?? body.max_tokens;
  if (value === undefined && path !== "/v1/messages") return fallback;
  if (!Number.isSafeInteger(value) || (value as number) < 1) throw new GatewayError(400, "invalid_max_output_tokens", "The maximum output token value is invalid.");
  return value as number;
}

export function estimateTokens(raw: string): number {
  // SP-local meter: estimate only model-visible request content. This remains
  // provider-independent; OmniRoute/provider usage counters are never read.
  let parsed: unknown;
  try { parsed = JSON.parse(raw); } catch { parsed = raw; }

  const estimated = promptSegmentsFor(parsed).reduce((sum, segment) => sum + segment.tokens, 0);
  if (!Number.isSafeInteger(estimated)) {
    throw new GatewayError(413, "request_too_large", "The request exceeds the supported size.");
  }
  return Math.max(0, estimated);
}

/**
 * Build privacy-preserving prompt segments for SP Cambo's local cache meter.
 * Only SHA-256 digests and token estimates are retained by the cache; prompt
 * text itself is never stored. Segment token totals intentionally mirror the
 * local structured estimator so cache and non-cache accounting reconcile.
 */
export function promptSegmentsFor(value: unknown): PromptSegment[] {
  const segments: PromptSegment[] = [];
  collectPromptSegments(value, "", 0, segments);
  return segments;
}

function pushPromptSegment(segments: PromptSegment[], marker: string, tokens: number): void {
  if (tokens <= 0) return;
  segments.push({
    digest: createHash("sha256").update(marker).digest("hex"),
    tokens,
  });
}

function collectPromptSegments(value: unknown, parentKey: string, depth: number, segments: PromptSegment[]): void {
  if (depth > 16 || value === null || value === undefined) return;
  if (typeof value === "string") {
    if (["model", "user", "service_tier", "prompt_cache_key", "previous_response_id"].includes(parentKey)) return;
    pushPromptSegment(segments, `s:${parentKey}:${value}`, estimateTextTokens(value));
    return;
  }
  if (typeof value === "number" || typeof value === "boolean") {
    pushPromptSegment(segments, `p:${parentKey}:${String(value)}`, 1);
    return;
  }
  if (Array.isArray(value)) {
    for (let index = 0; index < value.length; index++) {
      pushPromptSegment(segments, `a:${parentKey}:${index}`, 1);
      collectPromptSegments(value[index], parentKey, depth + 1, segments);
    }
    return;
  }
  if (!record(value)) return;

  const ignored = new Set([
    "max_tokens", "max_completion_tokens", "max_output_tokens", "stream",
    "temperature", "top_p", "top_k", "n", "seed", "store", "metadata",
    "service_tier", "parallel_tool_calls", "stream_options",
  ]);

  pushPromptSegment(segments, `o:${parentKey}:${depth}`, depth === 0 ? 6 : 2);
  let entries = Object.entries(value).filter(([key]) => !ignored.has(key));
  if (depth === 0) {
    // Put long-lived agent context before turn-by-turn conversation content.
    // This mirrors the prefix-caching shape used by common AI clients and makes
    // stable system/tool definitions reusable even when a new message is added.
    const order = new Map<string, number>([
      ["system", 0], ["instructions", 0], ["tools", 1], ["tool_choice", 2],
      ["thinking", 3], ["reasoning", 3], ["response_format", 4], ["text", 4],
      ["messages", 10], ["input", 10], ["prompt", 10], ["conversation", 10],
    ]);
    entries = entries.sort(([left], [right]) => {
      const leftRank = order.get(left) ?? 5;
      const rightRank = order.get(right) ?? 5;
      return leftRank - rightRank || left.localeCompare(right);
    });
  }
  for (const [key, child] of entries) {
    const keyTokens = 1 + (key === "model" ? 0 : Math.min(2, estimateTextTokens(key)));
    pushPromptSegment(segments, `k:${parentKey}:${key}`, keyTokens);
    collectPromptSegments(child, key, depth + 1, segments);
  }
}

export function spLocalUsage(inputTokens: number, cacheReadTokens: number, outputPayload: unknown, rawFallback = ""): Usage {
  const generatedTokens = generatedPayloadTokens(outputPayload);
  // When an adapter returns an unfamiliar public envelope, estimate only from
  // the public response body. Provider usage metadata is never used here.
  const fallbackTokens = rawFallback === "" ? 0 : Math.max(1, estimateTextTokens(rawFallback));
  const outputTokens = generatedTokens > 0 ? generatedTokens : Math.ceil(fallbackTokens / 2);

  return {
    input_tokens: Math.max(0, inputTokens),
    output_tokens: localOutputBilledTokens(outputTokens),
    cache_read_tokens: Math.max(0, cacheReadTokens),
    cache_write_tokens: 0,
    reasoning_tokens: 0,
  };
}

export function spLocalOutputTokensFromSse(frame: string): number {
  let total = 0;
  for (const line of frame.split(/\r?\n/)) {
    if (!line.startsWith("data:")) continue;
    const data = line.slice(5).trim();
    if (data === "" || data === "[DONE]") continue;
    try {
      const parsed = JSON.parse(data) as unknown;
      if (record(parsed)) {
        const eventType = typeof parsed.type === "string" ? parsed.type.toLowerCase() : "";
        // Many streaming protocols send a final snapshot after all deltas. The
        // snapshot is useful to the client but must not be counted a second time.
        if (eventType.endsWith(".done") || eventType.includes("completed") || eventType === "message_stop") continue;
      }
      total += generatedPayloadTokens(parsed);
    } catch { /* Unknown SSE text is not treated as usage or billable content. */ }
  }
  return total;
}

export function spLocalUsageFromOutputTokens(inputTokens: number, cacheReadTokens: number, outputTokens: number): Usage {
  return {
    input_tokens: Math.max(0, inputTokens),
    output_tokens: localOutputBilledTokens(outputTokens),
    cache_read_tokens: Math.max(0, cacheReadTokens),
    cache_write_tokens: 0,
    reasoning_tokens: 0,
  };
}

/**
 * A deterministic tokenizer-like heuristic for customer-visible SP estimates.
 * It handles Latin/code text, punctuation, and non-ASCII scripts more naturally
 * than a simple bytes/4 rule while remaining provider-independent.
 */
function estimateTextTokens(text: string): number {
  const normalized = text.trim();
  if (normalized === "") return 0;

  let asciiWordChars = 0;
  let punctuation = 0;
  let nonAscii = 0;
  let whitespaceRuns = 0;
  let inWhitespace = false;

  for (const char of normalized) {
    const code = char.codePointAt(0) ?? 0;
    if (/\s/u.test(char)) {
      if (!inWhitespace) whitespaceRuns++;
      inWhitespace = true;
      continue;
    }
    inWhitespace = false;
    if (code > 0x7f) {
      nonAscii++;
    } else if (/[A-Za-z0-9_]/.test(char)) {
      asciiWordChars++;
    } else {
      punctuation++;
    }
  }

  const tokens =
    Math.ceil(asciiWordChars / 4) +
    Math.ceil(punctuation / 2) +
    Math.ceil(nonAscii * 0.75) +
    Math.ceil(whitespaceRuns / 6);

  return Math.max(1, tokens);
}

function estimateStructuredTokens(value: unknown, parentKey = "", depth = 0): number {
  if (depth > 16 || value === null || value === undefined) return 0;
  if (typeof value === "string") {
    // Transport-only identifiers are not treated as model-visible prompt text.
    if (["model", "user", "service_tier", "prompt_cache_key", "previous_response_id"].includes(parentKey)) return 0;
    return estimateTextTokens(value);
  }
  if (typeof value === "number" || typeof value === "boolean") return 1;
  if (Array.isArray(value)) {
    return value.reduce((sum, item) => sum + 1 + estimateStructuredTokens(item, parentKey, depth + 1), 0);
  }
  if (!record(value)) return 0;

  const ignored = new Set([
    "max_tokens", "max_completion_tokens", "max_output_tokens", "stream",
    "temperature", "top_p", "top_k", "n", "seed", "store", "metadata",
    "service_tier", "parallel_tool_calls", "stream_options",
  ]);

  let total = depth === 0 ? 6 : 2; // protocol/message framing overhead.
  for (const [key, child] of Object.entries(value)) {
    if (ignored.has(key)) continue;
    total += 1;
    if (key !== "model") total += Math.min(2, estimateTextTokens(key));
    total += estimateStructuredTokens(child, key, depth + 1);
  }
  return total;
}

function generatedPayloadTokens(value: unknown): number {
  const generatedKeys = new Set([
    "text", "content", "output_text", "reasoning_content", "arguments", "delta",
    "partial_json", "completion", "generated_text",
  ]);
  let tokens = 0;
  const walk = (node: unknown, parentKey = "", depth = 0): void => {
    if (depth > 12 || node === null || node === undefined) return;
    if (typeof node === "string") {
      if (generatedKeys.has(parentKey)) tokens += estimateTextTokens(node);
      return;
    }
    if (Array.isArray(node)) { for (const item of node) walk(item, parentKey, depth + 1); return; }
    if (!record(node)) return;
    for (const [key, child] of Object.entries(node)) {
      if ((key === "input" || key === "arguments") && record(child)) {
        tokens += estimateStructuredTokens(child, key, depth + 1);
        continue;
      }
      walk(child, key, depth + 1);
    }
  };
  walk(value);
  return tokens;
}


function logicalInputTokens(usage: Usage): number {
  return Math.max(0, usage.input_tokens) + Math.max(0, usage.cache_read_tokens) + Math.max(0, usage.cache_write_tokens);
}

function logicalTotalTokens(usage: Usage): number {
  return logicalInputTokens(usage) + Math.max(0, usage.output_tokens) + Math.max(0, usage.reasoning_tokens);
}

/**
 * Replace any provider/OmniRoute usage payload with SP Cambo's local meter.
 * This is a public-response compatibility layer only; billing already uses
 * the same local counts directly.
 */
function publicUsageShape(path: InferencePath, usage: Usage): Record<string, unknown> {
  if (path === "/v1/messages" || path === "/v1/messages/count_tokens") {
    return {
      input_tokens: usage.input_tokens,
      output_tokens: usage.output_tokens,
      cache_creation_input_tokens: 0,
      cache_read_input_tokens: usage.cache_read_tokens,
    };
  }
  if (path === "/v1/chat/completions") {
    return {
      prompt_tokens: logicalInputTokens(usage),
      completion_tokens: usage.output_tokens,
      total_tokens: logicalTotalTokens(usage),
      prompt_tokens_details: { cached_tokens: usage.cache_read_tokens },
    };
  }
  return {
    input_tokens: logicalInputTokens(usage),
    output_tokens: usage.output_tokens,
    total_tokens: logicalTotalTokens(usage),
    input_tokens_details: { cached_tokens: usage.cache_read_tokens },
  };
}

function replaceProviderUsageWithLocal(value: Record<string, unknown>, path: InferencePath, usage: Usage, depth = 0): void {
  if (depth > 8) return;
  delete value.usage_metadata;
  delete value.usageMetadata;
  if ("usage" in value) value.usage = publicUsageShape(path, usage);
  for (const [key, child] of Object.entries(value)) {
    if (key === "usage") continue;
    if (record(child)) replaceProviderUsageWithLocal(child, path, usage, depth + 1);
    else if (Array.isArray(child)) {
      for (const item of child) if (record(item)) replaceProviderUsageWithLocal(item, path, usage, depth + 1);
    }
  }
}

export function withLocalUsage(value: unknown, path: InferencePath, usage: Usage): unknown {
  if (!record(value)) return value;
  replaceProviderUsageWithLocal(value, path, usage);
  value.usage = publicUsageShape(path, usage);
  return value;
}

/**
 * Keep the public alias stable in response metadata. Only known protocol
 * envelope objects are traversed so an assistant's structured output is never
 * rewritten merely because it contains a field named `model`.
 */
export function restorePublicModel(value: unknown, publicModel: string): unknown {
  if (!record(value)) return value;

  if (typeof value.model === "string") value.model = publicModel;

  for (const key of ["message", "response", "data"] as const) {
    const child = value[key];
    if (record(child)) restorePublicModel(child, publicModel);
    else if (Array.isArray(child)) {
      for (const item of child) if (record(item)) restorePublicModel(item, publicModel);
    }
  }

  return value;
}

/** Restore the public alias inside JSON payloads carried by SSE events. */
export function restorePublicModelInSse(frame: string, publicModel: string): string {
  return frame.split(/(\r?\n)/).map((part) => {
    if (!part.startsWith("data:")) return part;
    const raw = part.slice(5).trim();
    if (raw === "" || raw === "[DONE]") return part;

    try {
      return `data: ${JSON.stringify(restorePublicModel(JSON.parse(raw), publicModel))}`;
    } catch {
      return part;
    }
  }).join("");
}

/**
 * Overwrite every provider/OmniRoute usage object inside an SSE frame with
 * SP Cambo's local meter. No provider usage numbers are allowed through as a
 * customer-visible billing signal.
 */
export function localizeSseUsage(frame: string, path: InferencePath, usage: Usage): string {
  const lines = frame.split(/\r?\n/);
  return lines.map((line) => {
    if (!line.startsWith("data:")) return line;
    const raw = line.slice(5).trim();
    if (raw === "" || raw === "[DONE]") return line;
    let parsed: unknown;
    try { parsed = JSON.parse(raw); } catch { return line; }
    if (!record(parsed)) return line;
    replaceProviderUsageWithLocal(parsed, path, usage);
    return `data: ${JSON.stringify(parsed)}`;
  }).join("\n");
}

function record(value: unknown): value is Record<string, unknown> { return typeof value === "object" && value !== null && !Array.isArray(value); }
