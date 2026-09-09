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

export type ToolInputFieldMap = ReadonlyMap<
  string,
  ReadonlySet<string> | null
>;

const EMPTY_TOOL_INPUT_FIELDS: ToolInputFieldMap = new Map();

type StreamToolState = {
  raw: string;
  hadObjectInput: boolean;
  sawDelta: boolean;
  toolName: string | null;
  allowedFields: ReadonlySet<string> | null;
};

/**
 * Build a case-insensitive map of the top-level input fields declared by the
 * customer's original tool schemas.
 *
 * The map is used only for deterministic provider-compatibility repair. It
 * never invents missing values: a trailing continuation field is accepted only
 * when that exact field name was declared by the client for that exact tool.
 */
export function buildToolInputFieldMap(
  body: Record<string, unknown>,
): ToolInputFieldMap {
  const map = new Map<string, ReadonlySet<string> | null>();
  const tools = Array.isArray(body.tools) ? body.tools : [];

  for (const candidate of tools) {
    if (!record(candidate)) continue;

    let name: string | null = null;
    let schema: unknown = null;

    if (typeof candidate.name === "string") {
      name = candidate.name;
      schema = candidate.input_schema;
    } else if (
      record(candidate.function)
      && typeof candidate.function.name === "string"
    ) {
      name = candidate.function.name;
      schema = candidate.function.parameters;
    }

    if (!name || name.length > 128 || name.includes("\r") || name.includes("\n") || name.includes("\0")) continue;

    const properties =
      record(schema) && record(schema.properties)
        ? new Set(Object.keys(schema.properties))
        : null;

    const key = name.toLocaleLowerCase("en-US");

    if (map.has(key)) {
      // Case-insensitive duplicate/collision is ambiguous, so continuation
      // repair for that tool is disabled.
      map.set(key, null);
    } else {
      map.set(key, properties);
    }
  }

  return map;
}

export function normalizeCompleteToolInputs(
  value: unknown,
  toolInputFields: ToolInputFieldMap = EMPTY_TOOL_INPUT_FIELDS,
): unknown {
  if (Array.isArray(value)) {
    return value.map((item) =>
      normalizeCompleteToolInputs(item, toolInputFields));
  }

  if (!record(value)) return value;

  const output: Record<string, unknown> = {};

  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeCompleteToolInputs(child, toolInputFields);
  }

  if (output.type === "tool_use") {
    const raw = unparsedRaw(output.input);

    if (raw !== null) {
      const toolName = typeof output.name === "string" ? output.name : null;
      output.input = parseObjectCompat(
        raw,
        allowedFieldsForTool(toolName, toolInputFields),
      );
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
export function rewriteSseToolInputs(
  text: string,
  toolInputFields: ToolInputFieldMap = EMPTY_TOOL_INPUT_FIELDS,
): string {
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

      return `data: ${JSON.stringify(
        normalizeStreamEvent(parsed, toolInputFields),
      )}`;
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

  constructor(
    private readonly toolInputFields: ToolInputFieldMap = EMPTY_TOOL_INPUT_FIELDS,
  ) {}

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
        const toolName = typeof block.name === "string" ? block.name : null;
        const allowedFields = allowedFieldsForTool(
          toolName,
          this.toolInputFields,
        );

        if (block.input !== undefined && raw === null && !record(block.input)) {
          throw new InvalidToolInputError("Anthropic tool_use.input must be an object.");
        }

        this.active.set(index, {
          raw: raw ?? "",
          hadObjectInput,
          sawDelta: false,
          toolName,
          allowedFields,
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

function normalizeStreamEvent(
  value: unknown,
  toolInputFields: ToolInputFieldMap,
): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeStreamEvent(item, toolInputFields));
  }

  if (!record(value)) return value;

  const output: Record<string, unknown> = {};

  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeStreamEvent(child, toolInputFields);
  }

  if (output.type === "tool_use") {
    const raw = unparsedRaw(output.input);

    if (raw !== null) {
      const toolName = typeof output.name === "string" ? output.name : null;
      output.input = parseObjectCompat(
        raw,
        allowedFieldsForTool(toolName, toolInputFields),
      );
    } else if (output.input !== undefined && !record(output.input)) {
      throw new InvalidToolInputError("Anthropic streamed tool_use.input must be an object.");
    }
  }

  return output;
}

function safeToolNameForDiagnostic(toolName: string | null): string {
  if (toolName === null || toolName === "") return "unknown";
  return /^[A-Za-z0-9_.:-]{1,128}$/u.test(toolName) ? toolName : "invalid-name";
}

function structuralCharKind(value: string | undefined): string {
  if (value === undefined) return "none";

  switch (value) {
    case ",":
      return "comma";
    case "}":
      return "close_brace";
    case "{":
      return "open_brace";
    case "]":
      return "close_bracket";
    case "[":
      return "open_bracket";
    case "\"":
      return "quote";
    case ":":
      return "colon";
    default:
      if (/\s/u.test(value)) return "whitespace";
      if (/[0-9-]/u.test(value)) return "numberish";
      if (/[A-Za-z_$]/u.test(value)) return "wordish";
      return "other";
  }
}

function summarizeInvalidToolInputShapeV2(
  raw: string,
  allowedFields: ReadonlySet<string> | null,
): string {
  const bytes = Buffer.byteLength(raw);
  const boundary = firstCompleteObjectEnd(raw);

  if (boundary === null) {
    return [
      `bytes=${bytes}`,
      "complete_object=false",
      `first=${structuralCharKind(raw.trimStart()[0])}`,
      `last=${structuralCharKind(raw.trimEnd().at(-1))}`,
    ].join(" ");
  }

  const headText = raw.slice(0, boundary).trim();
  const tailText = raw.slice(boundary).trim();

  let headKeys: string[] = [];
  try {
    const head = JSON.parse(headText) as unknown;
    if (record(head)) headKeys = Object.keys(head).sort();
  } catch {
    // Structural diagnostics only.
  }

  let commaTailKeys: string[] = [];
  let commaTailObject = false;

  if (tailText.startsWith(",")) {
    try {
      const parsedTail = JSON.parse(`{${tailText.slice(1)}`) as unknown;
      if (record(parsedTail)) {
        commaTailObject = true;
        commaTailKeys = Object.keys(parsedTail).sort();
      }
    } catch {
      // Structural diagnostics only.
    }
  }

  const tailKeysAllowed =
    commaTailObject
    && allowedFields !== null
    && commaTailKeys.every((key) => allowedFields.has(key));

  const allClosingBraces = tailText !== "" && /^\}+$/u.test(tailText);
  const allClosingBrackets = tailText !== "" && /^\]+$/u.test(tailText);

  return [
    `bytes=${bytes}`,
    "complete_object=true",
    `head_keys=${JSON.stringify(headKeys)}`,
    `tail_bytes=${Buffer.byteLength(tailText)}`,
    `tail_first=${structuralCharKind(tailText[0])}`,
    `tail_last=${structuralCharKind(tailText.at(-1))}`,
    `tail_starts_comma=${tailText.startsWith(",")}`,
    `comma_tail_object=${commaTailObject}`,
    `comma_tail_keys=${JSON.stringify(commaTailKeys)}`,
    `comma_tail_keys_allowed=${tailKeysAllowed}`,
    `allowed_field_count=${allowedFields?.size ?? 0}`,
    `tail_all_closing_braces=${allClosingBraces}`,
    `tail_all_closing_brackets=${allClosingBrackets}`,
  ].join(" ");
}

function validateState(state: StreamToolState): string | null {
  if (state.raw !== "") {
    try {
      parseObject(state.raw);
      return null;
    } catch (originalError) {
      const repaired = repairDuplicatedTrailingFields(
        state.raw,
        state.allowedFields,
      );

      if (repaired !== null) {
        return JSON.stringify(repaired);
      }

      throw new InvalidToolInputError(
        `Tool input raw JSON could not be parsed. [tool=${safeToolNameForDiagnostic(state.toolName)} shape ${summarizeInvalidToolInputShapeV2(state.raw, state.allowedFields)}]`,
      );
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
 * We always repair deterministic identical duplicate tails. We also repair a
 * complete continuation tail only when every new top-level field was declared
 * by the customer's original schema for that exact tool. Unknown fields,
 * changed duplicate values, and truncated/ambiguous JSON remain rejected.
 */
function parseObjectCompat(
  raw: string,
  allowedFields: ReadonlySet<string> | null = null,
): Record<string, unknown> {
  try {
    return parseObject(raw);
  } catch (originalError) {
    const repaired = repairDuplicatedTrailingFields(raw, allowedFields);

    if (repaired !== null) {
      return repaired;
    }

    throw new InvalidToolInputError(
      `Completed tool input raw JSON could not be parsed. [shape ${summarizeInvalidToolInputShapeV2(raw, allowedFields)}]`,
    );
  }
}

function repairDuplicatedTrailingFields(
  raw: string,
  allowedFields: ReadonlySet<string> | null = null,
): Record<string, unknown> | null {
  const boundary = firstCompleteObjectEnd(raw);

  if (boundary === null) return null;

  const headText = raw.slice(0, boundary).trim();
  const tailText = raw.slice(boundary).trim();

  if (tailText === "") {
    return null;
  }

  let head: unknown;

  try {
    head = JSON.parse(headText) as unknown;
  } catch {
    return null;
  }

  if (!record(head)) {
    return null;
  }

  if (!tailText.startsWith(",")) {
    // Exact duplicated suffixes are safe to discard because the complete object
    // already contains every byte being repeated after its closing brace.
    //
    // This targets compatible providers that emit overlapping partial_json
    // chunks, for example:
    //
    //   {"command":"echo 120000","description":"Check 120000"}120000"}
    //
    // The malformed tail is repaired only when it is byte-for-byte an exact
    // suffix of the already valid object. Arbitrary garbage or a changed suffix
    // is still rejected.
    return headText.endsWith(tailText) ? head : null;
  }

  let tail: unknown;

  try {
    tail = JSON.parse(`{${tailText.slice(1)}`) as unknown;
  } catch {
    return null;
  }

  if (!record(tail) || Object.keys(tail).length === 0) {
    return null;
  }

  const merged: Record<string, unknown> = { ...head };

  for (const [key, value] of Object.entries(tail)) {
    if (Object.prototype.hasOwnProperty.call(head, key)) {
      // A duplicate is safe only if it is byte-semantically the same JSON
      // value. Conflicting repeats remain invalid.
      if (!sameJsonValue(head[key], value)) {
        return null;
      }

      continue;
    }

    // A provider may prematurely close a tool input object and then continue
    // the SAME object with additional top-level fields. Repair that defect only
    // when the original client schema proves that this exact field belongs to
    // this exact tool.
    if (allowedFields === null || !allowedFields.has(key)) {
      return null;
    }

    merged[key] = value;
  }

  return merged;
}

function allowedFieldsForTool(
  toolName: string | null,
  toolInputFields: ToolInputFieldMap,
): ReadonlySet<string> | null {
  if (toolName === null) return null;

  return toolInputFields.get(
    toolName.toLocaleLowerCase("en-US"),
  ) ?? null;
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
