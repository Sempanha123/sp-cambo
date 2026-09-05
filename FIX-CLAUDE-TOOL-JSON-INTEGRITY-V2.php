<?php

declare(strict_types=1);

/**
 * SP Cambo - Claude Code tool JSON integrity hotfix V2
 *
 * V2 is adaptive: it does not depend on the exact multiline formatting of
 * gateway/src/app.ts that caused the first patch to stop with:
 *
 *   expected exactly 1 source anchor, found 0
 *
 * This patch:
 * - preserves your current output calibration (including 19_500 / 1.95x)
 * - repairs valid __unparsedToolInput.raw wrappers
 * - rejects malformed/truncated Anthropic tool JSON before public bytes are sent
 * - lets the existing route failover retry another provider
 * - adds >30 KB Write regression tests
 *
 * Run from repository root:
 *
 *   php FIX-CLAUDE-TOOL-JSON-INTEGRITY-V2.php
 *
 * Then:
 *
 *   cd gateway
 *   pnpm test
 *   pnpm exec tsc --noEmit
 */

$root = getcwd();

if ($root === false) {
    fwrite(STDERR, "ERROR: Could not determine current directory.\n");
    exit(1);
}

function pathFor(string $root, string $relative): string
{
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function readFileStrict(string $root, string $relative): string
{
    $path = pathFor($root, $relative);

    if (!is_file($path)) {
        throw new RuntimeException("Missing {$relative}. Run this script from the SP Cambo repository root.");
    }

    $value = file_get_contents($path);

    if ($value === false) {
        throw new RuntimeException("Could not read {$relative}.");
    }

    return $value;
}

function regexReplaceOnce(string $source, string $pattern, string $replacement, string $label): string
{
    $count = preg_match_all($pattern, $source);

    if ($count !== 1) {
        throw new RuntimeException(
            "{$label}: expected exactly 1 compatible source location, found {$count}. No files changed."
        );
    }

    $result = preg_replace($pattern, $replacement, $source, 1);

    if ($result === null) {
        throw new RuntimeException("{$label}: regex replacement failed.");
    }

    return $result;
}

function stageExisting(array &$staged, string $root, string $relative, callable $patcher): void
{
    $before = readFileStrict($root, $relative);
    $after = $patcher($before);

    $staged[$relative] = [
        'path' => pathFor($root, $relative),
        'before' => $before,
        'after' => $after,
        'created' => false,
    ];
}

function stageNew(array &$staged, string $root, string $relative, string $content): void
{
    $path = pathFor($root, $relative);

    if (is_file($path)) {
        $existing = file_get_contents($path);

        if ($existing === false) {
            throw new RuntimeException("Could not read existing {$relative}.");
        }

        if ($existing !== $content) {
            throw new RuntimeException(
                "{$relative} already exists with different content. " .
                "No overwrite was performed."
            );
        }

        $staged[$relative] = [
            'path' => $path,
            'before' => $existing,
            'after' => $content,
            'created' => false,
        ];

        return;
    }

    $staged[$relative] = [
        'path' => $path,
        'before' => null,
        'after' => $content,
        'created' => true,
    ];
}

$integritySource = <<<'TS'
/**
 * Anthropic / Claude Code tool-input integrity helpers.
 *
 * We never guess missing provider output. A raw compatibility wrapper is repaired
 * only when its JSON is complete and parses to an object. Truncated tool JSON is
 * rejected so the gateway can retry another route before Claude Code sees it.
 */

export const MAX_BUFFERED_TOOL_STREAM_BYTES = 8 * 1024 * 1024;

export class InvalidToolInputError extends Error {
  constructor(message = "The upstream returned invalid tool input JSON.") {
    super(message);
    this.name = "InvalidToolInputError";
  }
}

type StreamToolState = {
  raw: string;
  hadObjectInput: boolean;
};

export function normalizeCompleteToolInputs(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeCompleteToolInputs(item));
  }

  if (!record(value)) return value;

  const output: Record<string, unknown> = {};

  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeCompleteToolInputs(child);
  }

  if (output.type === "tool_use") {
    const raw = unparsedRaw(output.input);

    if (raw !== null) {
      output.input = parseObject(raw);
    } else if (!record(output.input)) {
      throw new InvalidToolInputError("Anthropic tool_use.input must be a JSON object.");
    }
  }

  return output;
}

/**
 * Rewrite only complete tool-input wrappers in SSE JSON.
 * input_json_delta.partial_json remains a string fragment and is not parsed here.
 */
export function rewriteSseToolInputs(text: string): string {
  if (text === "") return text;

  return text
    .split(/(\r?\n)/)
    .map((part) => {
      if (!part.startsWith("data:")) return part;

      const data = part.slice(5).trim();

      if (data === "" || data === "[DONE]") return part;

      let parsed: unknown;

      try {
        parsed = JSON.parse(data) as unknown;
      } catch {
        return part;
      }

      return `data: ${JSON.stringify(normalizeStreamEvent(parsed))}`;
    })
    .join("");
}

/**
 * Validate a streamed Anthropic tool call across all input_json_delta chunks.
 *
 * SP Cambo can hold tool-enabled /v1/messages streams until finish() succeeds.
 * If a provider cuts a 30 KB Write argument short, no broken tool call needs to
 * reach Claude Code.
 */
export class AnthropicToolStreamGuard {
  private readonly active = new Map<number, StreamToolState>();

  inspect(frame: string): void {
    for (const line of frame.split(/\r?\n/)) {
      if (!line.startsWith("data:")) continue;

      const data = line.slice(5).trim();

      if (data === "" || data === "[DONE]") continue;

      let event: unknown;

      try {
        event = JSON.parse(data) as unknown;
      } catch {
        throw new InvalidToolInputError("Anthropic SSE contained invalid JSON.");
      }

      if (!record(event)) continue;

      const type = typeof event.type === "string" ? event.type : "";

      if (type === "content_block_start") {
        const block = event.content_block;

        if (!record(block) || block.type !== "tool_use") continue;

        const index = eventIndex(event);

        if (this.active.has(index)) {
          throw new InvalidToolInputError("Duplicate Anthropic tool block index.");
        }

        const raw = unparsedRaw(block.input);
        const hadObjectInput = record(block.input) && raw === null;

        if (block.input !== undefined && raw === null && !record(block.input)) {
          throw new InvalidToolInputError("Anthropic tool_use.input must be an object.");
        }

        this.active.set(index, {
          raw: raw ?? "",
          hadObjectInput,
        });

        continue;
      }

      if (type === "content_block_delta") {
        const delta = event.delta;

        if (!record(delta) || delta.type !== "input_json_delta") continue;

        const index = eventIndex(event);
        const state = this.active.get(index);

        if (!state) {
          throw new InvalidToolInputError("Tool input delta arrived without a tool block.");
        }

        if (typeof delta.partial_json !== "string") {
          throw new InvalidToolInputError("Tool input delta is missing partial_json.");
        }

        state.raw += delta.partial_json;

        if (Buffer.byteLength(state.raw) > MAX_BUFFERED_TOOL_STREAM_BYTES) {
          throw new InvalidToolInputError("Tool input exceeded the integrity buffer limit.");
        }

        continue;
      }

      if (type === "content_block_stop") {
        const index = eventIndex(event);
        const state = this.active.get(index);

        if (!state) continue;

        validateState(state);
        this.active.delete(index);
        continue;
      }

      if (type === "message_stop" && this.active.size > 0) {
        throw new InvalidToolInputError("Anthropic message ended before a tool block completed.");
      }
    }
  }

  finish(): void {
    if (this.active.size > 0) {
      throw new InvalidToolInputError("Anthropic stream ended before tool input completed.");
    }
  }
}

function normalizeStreamEvent(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeStreamEvent(item));
  }

  if (!record(value)) return value;

  const output: Record<string, unknown> = {};

  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeStreamEvent(child);
  }

  if (output.type === "tool_use") {
    const raw = unparsedRaw(output.input);

    if (raw !== null) {
      output.input = parseObject(raw);
    } else if (output.input !== undefined && !record(output.input)) {
      throw new InvalidToolInputError("Anthropic streamed tool_use.input must be an object.");
    }
  }

  return output;
}

function validateState(state: StreamToolState): void {
  if (state.raw !== "") {
    parseObject(state.raw);
    return;
  }

  if (!state.hadObjectInput) {
    throw new InvalidToolInputError("Anthropic tool block completed without valid input.");
  }
}

function parseObject(raw: string): Record<string, unknown> {
  let parsed: unknown;

  try {
    parsed = JSON.parse(raw) as unknown;
  } catch {
    throw new InvalidToolInputError("Tool input raw JSON could not be parsed.");
  }

  if (!record(parsed)) {
    throw new InvalidToolInputError("Tool input raw JSON must decode to an object.");
  }

  return parsed;
}

function unparsedRaw(input: unknown): string | null {
  if (typeof input === "string") return input;
  if (!record(input)) return null;

  const wrapper = input.__unparsedToolInput;

  if (!record(wrapper) || typeof wrapper.raw !== "string") {
    return null;
  }

  return wrapper.raw;
}

function eventIndex(event: Record<string, unknown>): number {
  const value = event.index;

  if (!Number.isSafeInteger(value) || (value as number) < 0) {
    throw new InvalidToolInputError("Anthropic tool event is missing a valid block index.");
  }

  return value as number;
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
TS;

$integrityTest = <<<'TS'
import { describe, expect, it } from "vitest";
import {
  AnthropicToolStreamGuard,
  InvalidToolInputError,
  normalizeCompleteToolInputs,
  rewriteSseToolInputs,
} from "../src/tool-integrity.js";

function bigWrite(): { file_path: string; content: string } {
  const row =
    `<section data-json='{"quote":"\\"","slash":"\\\\","line":"a\\nb"}'>` +
    `const p = "C:\\\\tmp\\\\file"; const tpl = "\${value}"; </script></section>\r\n`;

  return {
    file_path: "index.html",
    content: "<!DOCTYPE html>\n" + row.repeat(420),
  };
}

function sse(value: unknown): string {
  return `event: x\ndata: ${JSON.stringify(value)}\n\n`;
}

describe("Claude tool JSON integrity", () => {
  it("repairs a valid >30 KB __unparsedToolInput Write payload exactly", () => {
    const original = bigWrite();
    const raw = JSON.stringify(original);

    expect(Buffer.byteLength(raw)).toBeGreaterThan(30_000);

    const result = normalizeCompleteToolInputs({
      type: "message",
      content: [{
        type: "tool_use",
        id: "tool_write",
        name: "Write",
        input: {
          __unparsedToolInput: {
            raw,
            len: Buffer.byteLength(raw),
          },
        },
      }],
    }) as any;

    expect(result.content[0].input.file_path).toBe("index.html");
    expect(result.content[0].input.content).toBe(original.content);
  });

  it("rejects a truncated >30 KB raw Write payload", () => {
    const raw = JSON.stringify(bigWrite()).slice(0, -7);

    expect(() => normalizeCompleteToolInputs({
      type: "message",
      content: [{
        type: "tool_use",
        id: "tool_bad",
        name: "Write",
        input: {
          __unparsedToolInput: {
            raw,
            len: Buffer.byteLength(raw),
          },
        },
      }],
    })).toThrow(InvalidToolInputError);
  });

  it("validates a large streamed Write assembled from many partial_json chunks", () => {
    const original = bigWrite();
    const raw = JSON.stringify(original);
    const guard = new AnthropicToolStreamGuard();

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_stream",
        name: "write",
        input: {},
      },
    }));

    for (let offset = 0; offset < raw.length; offset += 113) {
      guard.inspect(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: raw.slice(offset, offset + 113),
        },
      }));
    }

    guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }));

    guard.inspect(sse({
      type: "message_stop",
    }));

    expect(() => guard.finish()).not.toThrow();
  });

  it("rejects streamed partial_json when the provider truncates it", () => {
    const raw = JSON.stringify(bigWrite()).slice(0, -5);
    const guard = new AnthropicToolStreamGuard();

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_truncated",
        name: "Write",
        input: {},
      },
    }));

    guard.inspect(sse({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: raw,
      },
    }));

    expect(() => guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }))).toThrow(InvalidToolInputError);
  });

  it("rewrites a valid raw wrapper inside a streamed tool_use block", () => {
    const original = {
      file_path: "index.html",
      content: "hello\nworld",
    };

    const frame = sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_wrapper",
        name: "Write",
        input: {
          __unparsedToolInput: {
            raw: JSON.stringify(original),
            len: JSON.stringify(original).length,
          },
        },
      },
    });

    const rewritten = rewriteSseToolInputs(frame);

    expect(rewritten).toContain('"file_path":"index.html"');
    expect(rewritten).not.toContain("__unparsedToolInput");
  });
});
TS;

$appIntegrationTest = <<<'TS'
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
TS;

$staged = [];

try {
    stageExisting($staged, $root, 'gateway/src/app.ts', function (string $s): string {
        // 1. Import.
        if (!str_contains($s, './tool-integrity.js')) {
            $s = regexReplaceOnce(
                $s,
                '/^(import[^\r\n]*from "\.\/tool-names\.js";)\R/m',
                '$1' . "\n" .
                'import { AnthropicToolStreamGuard, InvalidToolInputError, MAX_BUFFERED_TOOL_STREAM_BYTES, normalizeCompleteToolInputs, rewriteSseToolInputs } from "./tool-integrity.js";' . "\n",
                'tool-integrity import'
            );
        }

        // 2. Non-stream JSON tool input repair/validation.
        if (!str_contains($s, 'normalizeCompleteToolInputs(parsed)')) {
            $pattern = '/const\s+normalizedResponse\s*=\s*restorePublicModel\(\s*normalizeToolNames\(\s*parsed\s*,\s*toolNames\s*\)\s*,\s*publicModel\s*,?\s*\)\s*;/s';

            $replacement = <<<'TS'
let normalizedResponse: unknown;
    try {
      normalizedResponse = restorePublicModel(
        normalizeToolNames(normalizeCompleteToolInputs(parsed), toolNames),
        publicModel,
      );
    } catch (error) {
      if (error instanceof InvalidToolInputError) {
        throw new PreStreamFailure("upstream_invalid_tool_input");
      }
      throw error;
    }
TS;

            $s = regexReplaceOnce(
                $s,
                $pattern,
                $replacement,
                'non-stream tool input validation'
            );
        }

        // 3. Stream guard initialization.
        if (!str_contains($s, 'const toolGuard = path === "/v1/messages"')) {
            $pattern = '/let\s+buffer\s*=\s*""\s*;\s*let\s+bytesSent\s*=\s*false\s*;\s*let\s+localOutputTokens\s*=\s*0\s*;\s*let\s+terminalFrameSeen\s*=\s*false\s*;\s*\R\s*const\s+reader\s*=\s*upstream\.body!\.getReader\(\)\s*;\s*const\s+decoder\s*=\s*new\s+TextDecoder\(\)\s*;/';

            $replacement = <<<'TS'
let buffer = ""; let bytesSent = false; let localOutputTokens = 0; let terminalFrameSeen = false;
    // Hold Anthropic tool-enabled streams until tool JSON integrity is known.
    // This allows the existing route pool to retry before malformed tool input
    // reaches Claude Code.
    const toolGuard = path === "/v1/messages" && toolNames.size > 0
      ? new AnthropicToolStreamGuard()
      : null;
    const heldToolFrames: string[] = [];
    let heldToolBytes = 0;
    const reader = upstream.body!.getReader(); const decoder = new TextDecoder();
TS;

            $s = regexReplaceOnce(
                $s,
                $pattern,
                $replacement,
                'stream tool guard initialization'
            );
        }

        // 4. Repair complete __unparsedToolInput wrappers before public write.
        if (!str_contains($s, 'rewriteSseToolNames(rewriteSseToolInputs(text), toolNames)')) {
            $pattern = '/rewriteSseToolNames\(\s*text\s*,\s*toolNames\s*\)/';

            $s = regexReplaceOnce(
                $s,
                $pattern,
                'rewriteSseToolNames(rewriteSseToolInputs(text), toolNames)',
                'stream tool input rewrite'
            );
        }

        // 5. Add hold/flush helpers after writePublic.
        if (!str_contains($s, 'const holdOrWrite = async')) {
            $pattern = '/(\s*if\s*\(\s*!reply\.raw\.write\(publicText\)\s*\)\s*await\s+abortable\(\s*once\(reply\.raw,\s*"drain"\)\s*,\s*signal\s*\)\s*;\s*\R\s*\};)/';

            $helper = <<<'TS'

    const holdOrWrite = async (rawFrame: string, publicFrame: string): Promise<void> => {
      if (!toolGuard) {
        await writePublic(publicFrame);
        return;
      }

      toolGuard.inspect(rawFrame);
      heldToolBytes += Buffer.byteLength(publicFrame);

      if (heldToolBytes > MAX_BUFFERED_TOOL_STREAM_BYTES) {
        throw new InvalidToolInputError(
          "Tool-enabled Anthropic stream exceeded the integrity buffer limit.",
        );
      }

      heldToolFrames.push(publicFrame);
    };

    const flushHeld = async (): Promise<void> => {
      if (!toolGuard || heldToolFrames.length === 0) return;

      toolGuard.finish();

      for (const frame of heldToolFrames) {
        await writePublic(frame);
      }

      heldToolFrames.length = 0;
      heldToolBytes = 0;
    };
TS;

            $count = preg_match_all($pattern, $s);

            if ($count !== 1) {
                throw new RuntimeException(
                    "stream hold helper insertion: expected exactly 1 compatible source location, found {$count}. No files changed."
                );
            }

            $s = preg_replace_callback(
                $pattern,
                fn(array $m): string => $m[1] . $helper,
                $s,
                1
            );

            if ($s === null) {
                throw new RuntimeException("stream hold helper insertion failed.");
            }
        }

        // 6. Hold every complete SSE frame when tools are enabled.
        if (!str_contains($s, 'await holdOrWrite(frame.complete,')) {
            $pattern = '/await\s+writePublic\(\s*localizeSseUsage\(\s*frame\.complete\s*,\s*path\s*,\s*localUsage\s*\)\s*\)\s*;/';

            $s = regexReplaceOnce(
                $s,
                $pattern,
                'await holdOrWrite(frame.complete, localizeSseUsage(frame.complete, path, localUsage));',
                'complete SSE frame hold'
            );
        }

        // 7. Hold any final incomplete buffer too.
        if (!str_contains($s, 'await holdOrWrite(buffer,')) {
            $pattern = '/await\s+writePublic\(\s*localizeSseUsage\(\s*buffer\s*,\s*path\s*,\s*localUsage\s*\)\s*\)\s*;/';

            $s = regexReplaceOnce(
                $s,
                $pattern,
                'await holdOrWrite(buffer, localizeSseUsage(buffer, path, localUsage));',
                'tail SSE buffer hold'
            );
        }

        // 8. Validate and flush held frames before leaving the try block.
        if (!str_contains($s, 'await flushHeld();')) {
            $pattern = '/(\s*if\s*\(\s*buffer\s*!==\s*""\s*\)\s*\{\s*localOutputTokens\s*\+=\s*spLocalOutputTokensFromSse\(buffer\)\s*;\s*const\s+localUsage\s*=\s*spLocalUsageFromOutputTokens\(\s*localInputTokens\s*,\s*localCacheReadTokens\s*,\s*localOutputTokens\s*\)\s*;\s*await\s+holdOrWrite\(\s*buffer\s*,\s*localizeSseUsage\(\s*buffer\s*,\s*path\s*,\s*localUsage\s*\)\s*\)\s*;\s*\})\s*(\R\s*\}\s*catch\s*\{)/s';

            $count = preg_match_all($pattern, $s);

            if ($count !== 1) {
                throw new RuntimeException(
                    "held stream flush: expected exactly 1 compatible source location, found {$count}. No files changed."
                );
            }

            $s = preg_replace(
                $pattern,
                '$1' . "\n      await flushHeld();" . '$2',
                $s,
                1
            );

            if ($s === null) {
                throw new RuntimeException("held stream flush replacement failed.");
            }
        }

        return $s;
    });

    stageNew(
        $staged,
        $root,
        'gateway/src/tool-integrity.ts',
        $integritySource . "\n"
    );

    stageNew(
        $staged,
        $root,
        'gateway/tests/tool-integrity.test.ts',
        $integrityTest . "\n"
    );

    stageNew(
        $staged,
        $root,
        'gateway/tests/tool-integrity-app.test.ts',
        $appIntegrationTest . "\n"
    );

    $changed = array_filter(
        $staged,
        fn(array $item): bool => $item['before'] !== $item['after']
    );

    if ($changed === []) {
        echo "OK: V2 tool JSON integrity patch is already applied.\n";
        echo "Run:\n";
        echo "  cd gateway\n";
        echo "  pnpm test\n";
        echo "  pnpm exec tsc --noEmit\n";
        exit(0);
    }

    $stamp = date('Ymd-His');
    $backups = [];

    foreach ($changed as $relative => $item) {
        if ($item['created']) continue;

        $backup = $item['path'] . '.bak-tool-json-v2-' . $stamp;

        if (!copy($item['path'], $backup)) {
            throw new RuntimeException("Could not create backup for {$relative}.");
        }

        $backups[$relative] = $backup;
    }

    $written = [];

    try {
        foreach ($changed as $relative => $item) {
            $directory = dirname($item['path']);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Could not create directory for {$relative}.");
            }

            if (file_put_contents($item['path'], $item['after']) === false) {
                throw new RuntimeException("Could not write {$relative}.");
            }

            $written[] = $relative;
        }
    } catch (Throwable $writeError) {
        foreach ($written as $relative) {
            $item = $changed[$relative];

            if ($item['created']) {
                @unlink($item['path']);
                continue;
            }

            if (isset($backups[$relative])) {
                @copy($backups[$relative], $item['path']);
            }
        }

        throw $writeError;
    }

    echo "SUCCESS: Claude tool JSON integrity V2 applied.\n\n";

    foreach ($changed as $relative => $item) {
        echo ($item['created'] ? "CREATED: " : "UPDATED: ") . $relative . "\n";
    }

    echo "\nThis patch did NOT change LOCAL_OUTPUT_CALIBRATION_BPS.\n";
    echo "Your 19_500 / 1.95x setting can remain as-is.\n\n";

    echo "Now run:\n";
    echo "  cd gateway\n";
    echo "  pnpm test\n";
    echo "  pnpm exec tsc --noEmit\n\n";

    echo "Then inspect:\n";
    echo "  git diff -- gateway/src/app.ts gateway/src/tool-integrity.ts gateway/tests\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
