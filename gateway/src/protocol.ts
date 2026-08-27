import { createHash, randomUUID } from "node:crypto";
import { GatewayError } from "./errors.js";
import type { InferencePath, Usage } from "./types.js";

const FIELDS: Record<InferencePath, ReadonlySet<string>> = {
  "/v1/messages": new Set(["model", "messages", "system", "max_tokens", "metadata", "stop_sequences", "stream", "temperature", "thinking", "tool_choice", "tools", "top_k", "top_p", "service_tier"]),
  "/v1/messages/count_tokens": new Set(["model", "messages", "system", "thinking", "tool_choice", "tools"]),
  "/v1/responses": new Set(["model", "input", "instructions", "max_output_tokens", "metadata", "parallel_tool_calls", "reasoning", "service_tier", "store", "stream", "temperature", "text", "tool_choice", "tools", "top_logprobs", "top_p", "truncation", "user", "include", "previous_response_id", "prompt_cache_key", "stream_options", "background", "prompt", "conversation", "max_tool_calls"]),
  "/v1/chat/completions": new Set(["model", "messages", "max_completion_tokens", "max_tokens", "metadata", "n", "parallel_tool_calls", "presence_penalty", "frequency_penalty", "reasoning_effort", "response_format", "seed", "service_tier", "stop", "store", "stream", "stream_options", "temperature", "tool_choice", "tools", "top_logprobs", "top_p", "user"]),
};

export type Prepared = {
  publicModel: string;
  requestedMaxOutput: number;
  estimatedInput: number;
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
  const estimated = estimateTokens(raw);
  return {
    publicModel: model,
    requestedMaxOutput: requested,
    estimatedInput: estimated,
    streaming,
    fingerprint: createHash("sha256").update(raw).digest("hex"),
    requestId: randomUUID(),
    body: parsed,
  };
}

export function upstreamBody(path: InferencePath, prepared: Prepared, internalModel: string, hardMax: number): string {
  const body: Record<string, unknown> = { ...prepared.body, model: internalModel };
  if (path === "/v1/messages") body.max_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
  if (path === "/v1/responses") body.max_output_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
  if (path === "/v1/chat/completions") {
    if ("max_completion_tokens" in body) body.max_completion_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
    else body.max_tokens = Math.min(prepared.requestedMaxOutput, hardMax);
    if (prepared.streaming) {
      body.stream_options = record(body.stream_options)
        ? { ...body.stream_options, include_usage: true }
        : { include_usage: true };
    }
  }
  return JSON.stringify(body);
}

export function usageFromJson(value: unknown, path: InferencePath): Usage | null {
  if (!record(value)) return null;
  if (path === "/v1/messages/count_tokens") {
    const countedInput = integer(value.input_tokens) ?? integer(value.inputTokens) ?? integer(value.prompt_tokens) ?? integer(value.promptTokens);
    return countedInput === null ? null : {
      input_tokens: countedInput,
      output_tokens: 0,
      cache_read_tokens: 0,
      cache_write_tokens: 0,
      reasoning_tokens: 0,
    };
  }

  const usage = findUsage(value);
  if (!usage) return null;

  const inputTotal = firstInteger(usage, [
    "input_tokens", "prompt_tokens", "inputTokens", "promptTokens", "prompt_token_count", "promptTokenCount",
  ]) ?? 0;
  const outputTotal = firstInteger(usage, [
    "output_tokens", "completion_tokens", "outputTokens", "completionTokens", "candidates_token_count", "candidatesTokenCount",
  ]) ?? 0;
  const inputDetails = record(usage.input_tokens_details) ? usage.input_tokens_details
    : record(usage.prompt_tokens_details) ? usage.prompt_tokens_details
      : record(usage.inputTokensDetails) ? usage.inputTokensDetails
        : record(usage.promptTokensDetails) ? usage.promptTokensDetails
          : {};
  const outputDetails = record(usage.output_tokens_details) ? usage.output_tokens_details
    : record(usage.completion_tokens_details) ? usage.completion_tokens_details
      : record(usage.outputTokensDetails) ? usage.outputTokensDetails
        : record(usage.completionTokensDetails) ? usage.completionTokensDetails
          : {};
  const reportedCacheRead = firstInteger(usage, ["cache_read_input_tokens", "cacheReadInputTokens"])
    ?? firstInteger(inputDetails, ["cached_tokens", "cachedTokens"])
    ?? 0;
  const reportedCacheWrite = firstInteger(usage, ["cache_creation_input_tokens", "cacheCreationInputTokens"])
    ?? 0;
  const reasoning = Math.min(outputTotal, firstInteger(outputDetails, ["reasoning_tokens", "reasoningTokens"]) ?? 0);

  if (path === "/v1/responses" || path === "/v1/chat/completions") {
    // OpenAI totals include cached/reasoning subsets. Partition those totals so
    // Laravel can price each category exactly once instead of double billing.
    const cacheRead = Math.min(inputTotal, reportedCacheRead);
    const cacheWrite = Math.min(inputTotal - cacheRead, reportedCacheWrite);
    return {
      input_tokens: inputTotal - cacheRead - cacheWrite,
      output_tokens: outputTotal - reasoning,
      cache_read_tokens: cacheRead,
      cache_write_tokens: cacheWrite,
      reasoning_tokens: reasoning,
    };
  }

  // Anthropic cache counts are additional to input_tokens. Reasoning, when an
  // adapter exposes it as an output detail, remains a partition of output.
  return {
    input_tokens: inputTotal,
    output_tokens: outputTotal - reasoning,
    cache_read_tokens: reportedCacheRead,
    cache_write_tokens: reportedCacheWrite,
    reasoning_tokens: reasoning,
  };
}

function findUsage(value: Record<string, unknown>): Record<string, unknown> | null {
  const direct: unknown[] = [
    value.usage, value.usage_metadata, value.usageMetadata,
    record(value.response) ? value.response.usage : null,
    record(value.message) ? value.message.usage : null,
    record(value.data) ? value.data.usage : null,
    record(value.meta) ? value.meta.usage : null,
    record(value.metadata) ? value.metadata.usage : null,
  ];
  for (const candidate of direct) if (record(candidate)) return candidate;

  // OmniRoute adapters can wrap provider events differently depending on the
  // selected combo/protocol. Search a small bounded object tree for a usage-like
  // record instead of assuming one vendor-specific envelope.
  const queue: Array<{ value: Record<string, unknown>; depth: number }> = [{ value, depth: 0 }];
  const seen = new Set<object>();
  while (queue.length) {
    const current = queue.shift()!;
    if (seen.has(current.value)) continue;
    seen.add(current.value);
    if (looksLikeUsage(current.value)) return current.value;
    if (current.depth >= 4) continue;
    for (const child of Object.values(current.value)) {
      if (record(child)) queue.push({ value: child, depth: current.depth + 1 });
      else if (Array.isArray(child)) for (const item of child) if (record(item)) queue.push({ value: item, depth: current.depth + 1 });
    }
  }
  return null;
}

function looksLikeUsage(value: Record<string, unknown>): boolean {
  return [
    "input_tokens", "prompt_tokens", "inputTokens", "promptTokens", "prompt_token_count", "promptTokenCount",
    "output_tokens", "completion_tokens", "outputTokens", "completionTokens", "candidates_token_count", "candidatesTokenCount",
  ].some((key) => integer(value[key]) !== null);
}

export function usageFromHeaders(headers: Headers, path: InferencePath): Usage | null {
  const get = (...names: string[]): number | null => {
    for (const name of names) {
      const raw = headers.get(name);
      if (raw === null || raw.trim() === "") continue;
      const value = Number(raw);
      if (Number.isSafeInteger(value) && value >= 0) return value;
    }
    return null;
  };
  const input = get("x-usage-input-tokens", "x-omniroute-input-tokens", "x-prompt-tokens", "x-input-tokens");
  const output = get("x-usage-output-tokens", "x-omniroute-output-tokens", "x-completion-tokens", "x-output-tokens");
  if (input === null && output === null) return null;
  const cacheRead = get("x-usage-cache-read-tokens", "x-cache-read-tokens") ?? 0;
  const cacheWrite = get("x-usage-cache-write-tokens", "x-cache-write-tokens") ?? 0;
  const reasoning = get("x-usage-reasoning-tokens", "x-reasoning-tokens") ?? 0;
  const inputTotal = input ?? 0;
  const outputTotal = output ?? 0;
  if (path === "/v1/responses" || path === "/v1/chat/completions") {
    const cached = Math.min(inputTotal, cacheRead);
    const written = Math.min(Math.max(0, inputTotal - cached), cacheWrite);
    const reasoned = Math.min(outputTotal, reasoning);
    return { input_tokens: inputTotal - cached - written, output_tokens: outputTotal - reasoned, cache_read_tokens: cached, cache_write_tokens: written, reasoning_tokens: reasoned };
  }
  return { input_tokens: inputTotal, output_tokens: Math.max(0, outputTotal - Math.min(outputTotal, reasoning)), cache_read_tokens: cacheRead, cache_write_tokens: cacheWrite, reasoning_tokens: Math.min(outputTotal, reasoning) };
}

function firstInteger(value: Record<string, unknown>, keys: string[]): number | null {
  for (const key of keys) {
    const parsed = integer(value[key]);
    if (parsed !== null) return parsed;
  }
  return null;
}

export function mergeUsage(current: Usage | null, next: Usage | null): Usage | null {
  if (!next) return current;
  if (!current) return next;
  return {
    input_tokens: Math.max(current.input_tokens, next.input_tokens), output_tokens: Math.max(current.output_tokens, next.output_tokens),
    cache_read_tokens: Math.max(current.cache_read_tokens, next.cache_read_tokens), cache_write_tokens: Math.max(current.cache_write_tokens, next.cache_write_tokens), reasoning_tokens: Math.max(current.reasoning_tokens, next.reasoning_tokens),
  };
}

function outputTokens(path: InferencePath, body: Record<string, unknown>, fallback: number): number {
  if (path === "/v1/messages/count_tokens") return 0;
  const value = path === "/v1/messages" ? body.max_tokens : path === "/v1/responses" ? body.max_output_tokens : body.max_completion_tokens ?? body.max_tokens;
  if (value === undefined && path !== "/v1/messages") return fallback;
  if (!Number.isSafeInteger(value) || (value as number) < 1) throw new GatewayError(400, "invalid_max_output_tokens", "The maximum output token value is invalid.");
  return value as number;
}

function estimateTokens(raw: string): number {
  // Each token represented in this JSON request requires at least one UTF-8 byte.
  // Reserving the full byte count is therefore a conservative protocol-agnostic
  // ceiling, unlike bytes/4 heuristics that can under-reserve token-dense input.
  const bytes = Buffer.byteLength(raw, "utf8");
  if (!Number.isSafeInteger(bytes)) throw new GatewayError(413, "request_too_large", "The request exceeds the supported size.");
  return Math.max(1, bytes);
}

function integer(value: unknown): number | null { return Number.isSafeInteger(value) && (value as number) >= 0 ? value as number : null; }
function record(value: unknown): value is Record<string, unknown> { return typeof value === "object" && value !== null && !Array.isArray(value); }
