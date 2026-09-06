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
