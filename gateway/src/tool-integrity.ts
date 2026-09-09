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
  sawDelta: boolean;
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
      output.input = parseObjectCompat(raw);
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
  private readonly repairs = new Map<number, string>();

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
          sawDelta: false,
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

        state.sawDelta = true;
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

        const repaired = validateState(state);

        if (repaired !== null && state.sawDelta) {
          this.repairs.set(index, repaired);
        }

        this.active.delete(index);
        continue;
      }

      if (type === "message_stop" && this.active.size > 0) {
        throw new InvalidToolInputError("Anthropic message ended before a tool block completed.");
      }
    }
  }

  /**
   * Rewrite only deterministic duplicate-tail repairs after the complete
   * tool-enabled stream has been validated.
   *
   * Valid streams are returned unchanged. For a repaired stream, canonical
   * JSON is redistributed over the provider's existing input_json_delta
   * events, so a large Write/Edit payload does not become one giant SSE line.
   */
  rewriteBuffered(text: string): string {
    this.finish();

    if (this.repairs.size === 0 || text === "") return text;

    const cursors = new Map<number, number>();

    const rewritten = text
      .split(/(\r?\n)/)
      .map((part) => {
        if (!part.startsWith("data:")) return part;

        const data = part.slice(5).trim();

        if (data === "" || data === "[DONE]") return part;

        let event: unknown;

        try {
          event = JSON.parse(data) as unknown;
        } catch {
          return part;
        }

        if (!record(event) || event.type !== "content_block_delta") return part;

        const delta = event.delta;

        if (!record(delta) || delta.type !== "input_json_delta") return part;

        if (typeof delta.partial_json !== "string") {
          throw new InvalidToolInputError("Tool input delta is missing partial_json.");
        }

        const index = eventIndex(event);
        const canonical = this.repairs.get(index);

        if (canonical === undefined) return part;

        const cursor = cursors.get(index) ?? 0;
        const end = Math.min(
          cursor + delta.partial_json.length,
          canonical.length,
        );

        cursors.set(index, end);

        return `data: ${JSON.stringify({
          ...event,
          delta: {
            ...delta,
            partial_json: canonical.slice(cursor, end),
          },
        })}`;
      })
      .join("");

    for (const [index, canonical] of this.repairs.entries()) {
      if ((cursors.get(index) ?? 0) !== canonical.length) {
        throw new InvalidToolInputError(
          "Repaired tool input could not be emitted safely.",
        );
      }
    }

    return rewritten;
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
      output.input = parseObjectCompat(raw);
    } else if (output.input !== undefined && !record(output.input)) {
      throw new InvalidToolInputError("Anthropic streamed tool_use.input must be an object.");
    }
  }

  return output;
}

function validateState(state: StreamToolState): string | null {
  if (state.raw !== "") {
    try {
      parseObject(state.raw);
      return null;
    } catch (originalError) {
      const repaired = repairDuplicatedTrailingFields(state.raw);

      if (repaired !== null) {
        return JSON.stringify(repaired);
      }

      throw originalError;
    }
  }

  if (!state.hadObjectInput) {
    throw new InvalidToolInputError("Anthropic tool block completed without valid input.");
  }

  return null;
}

/**
 * Parse a completed provider tool-input wrapper.
 *
 * Some compatible providers emit a valid object and then accidentally append
 * one or more of the same top-level fields after the object has already closed:
 *
 *   {"command":"...","description":"List files"},
 *   "description":"List files"}
 *
 * We repair only that deterministic duplicate-tail shape. Any trailing field
 * that is new or has a different value is rejected.
 */
function parseObjectCompat(raw: string): Record<string, unknown> {
  try {
    return parseObject(raw);
  } catch (originalError) {
    const repaired = repairDuplicatedTrailingFields(raw);

    if (repaired !== null) {
      return repaired;
    }

    throw originalError;
  }
}

function repairDuplicatedTrailingFields(raw: string): Record<string, unknown> | null {
  const boundary = firstCompleteObjectEnd(raw);

  if (boundary === null) return null;

  const headText = raw.slice(0, boundary).trim();
  const tailText = raw.slice(boundary).trim();

  if (tailText === "" || !tailText.startsWith(",")) {
    return null;
  }

  let head: unknown;
  let tail: unknown;

  try {
    head = JSON.parse(headText) as unknown;
    tail = JSON.parse(`{${tailText.slice(1)}`) as unknown;
  } catch {
    return null;
  }

  if (!record(head) || !record(tail) || Object.keys(tail).length === 0) {
    return null;
  }

  for (const [key, value] of Object.entries(tail)) {
    if (!Object.prototype.hasOwnProperty.call(head, key)) {
      return null;
    }

    if (!sameJsonValue(head[key], value)) {
      return null;
    }
  }

  return head;
}

function firstCompleteObjectEnd(raw: string): number | null {
  let index = 0;

  while (index < raw.length && /\s/u.test(raw[index]!)) {
    index++;
  }

  if (raw[index] !== "{") {
    return null;
  }

  let depth = 0;
  let inString = false;
  let escaped = false;

  for (; index < raw.length; index++) {
    const char = raw[index]!;

    if (inString) {
      if (escaped) {
        escaped = false;
        continue;
      }

      if (char === "\\") {
        escaped = true;
        continue;
      }

      if (char === '"') {
        inString = false;
      }

      continue;
    }

    if (char === '"') {
      inString = true;
      continue;
    }

    if (char === "{") {
      depth++;
      continue;
    }

    if (char === "}") {
      depth--;

      if (depth === 0) {
        return index + 1;
      }

      if (depth < 0) {
        return null;
      }
    }
  }

  return null;
}

function sameJsonValue(left: unknown, right: unknown): boolean {
  if (left === right) return true;

  if (Array.isArray(left) || Array.isArray(right)) {
    if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) {
      return false;
    }

    for (let index = 0; index < left.length; index++) {
      if (!sameJsonValue(left[index], right[index])) {
        return false;
      }
    }

    return true;
  }

  if (record(left) || record(right)) {
    if (!record(left) || !record(right)) {
      return false;
    }

    const leftKeys = Object.keys(left).sort();
    const rightKeys = Object.keys(right).sort();

    if (leftKeys.length !== rightKeys.length) {
      return false;
    }

    for (let index = 0; index < leftKeys.length; index++) {
      const leftKey = leftKeys[index]!;
      const rightKey = rightKeys[index]!;

      if (leftKey !== rightKey) {
        return false;
      }

      if (!sameJsonValue(left[leftKey], right[rightKey])) {
        return false;
      }
    }

    return true;
  }

  return false;
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
