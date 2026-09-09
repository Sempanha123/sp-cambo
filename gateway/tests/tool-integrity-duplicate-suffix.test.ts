import { describe, expect, it } from "vitest";
import {
  AnthropicToolStreamGuard,
  buildToolInputFieldMap,
  InvalidToolInputError,
  normalizeCompleteToolInputs,
} from "../src/tool-integrity.js";

function sse(value: unknown): string {
  return `event: x\ndata: ${JSON.stringify(value)}\n\n`;
}

function fields(name: string, properties: Record<string, unknown>) {
  return buildToolInputFieldMap({
    tools: [{
      name,
      input_schema: {
        type: "object",
        properties,
      },
    }],
  });
}

function reconstructToolJson(text: string): string {
  return text
    .split(/\r?\n/u)
    .filter((line) => line.startsWith("data:"))
    .map((line) => {
      const data = line.slice(5).trim();
      if (data === "" || data === "[DONE]") return null;
      return JSON.parse(data) as any;
    })
    .filter((event) =>
      event
      && event.type === "content_block_delta"
      && event.delta?.type === "input_json_delta")
    .map((event) => event.delta.partial_json)
    .join("");
}

describe("deterministic duplicated tool-input suffix compatibility", () => {
  it("repairs a Write object followed by an exact word suffix duplicate", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const head = JSON.stringify({
      file_path: "index.html",
      content: "Coffee landing page ending with coffee",
    });
    const suffix = 'coffee"}';

    expect(head.endsWith(suffix)).toBe(true);

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_write_suffix",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: head + suffix,
          len: Buffer.byteLength(head + suffix),
        },
      },
    }, toolFields) as any;

    expect(result.input).toEqual({
      file_path: "index.html",
      content: "Coffee landing page ending with coffee",
    });
  });

  it("repairs a Bash object followed by an exact numeric suffix duplicate", () => {
    const toolFields = fields("Bash", {
      command: { type: "string" },
      description: { type: "string" },
      timeout: { type: "number" },
    });

    const head = JSON.stringify({
      command: "node --version",
      description: "Check Node",
      timeout: 120000,
    });
    const suffix = "120000}";

    expect(head.endsWith(suffix)).toBe(true);

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_bash_suffix",
      name: "Bash",
      input: {
        __unparsedToolInput: {
          raw: head + suffix,
          len: Buffer.byteLength(head + suffix),
        },
      },
    }, toolFields) as any;

    expect(result.input).toEqual({
      command: "node --version",
      description: "Check Node",
      timeout: 120000,
    });
  });

  it("repairs the same duplicated suffix in a streamed tool call", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const head = JSON.stringify({
      file_path: "index.html",
      content: "A safe streamed ending",
    });
    const suffix = 'ending"}';
    const malformed = head + suffix;

    expect(head.endsWith(suffix)).toBe(true);

    const guard = new AnthropicToolStreamGuard(toolFields);
    const frames: string[] = [];

    frames.push(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_write_stream_suffix",
        name: "Write",
        input: {},
      },
    }));

    for (let offset = 0; offset < malformed.length; offset += 13) {
      frames.push(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: malformed.slice(offset, offset + 13),
        },
      }));
    }

    frames.push(sse({
      type: "content_block_stop",
      index: 0,
    }));
    frames.push(sse({ type: "message_stop" }));

    for (const frame of frames) guard.inspect(frame);

    const rewritten = guard.rewriteBuffered(frames.join(""));
    const reconstructed = reconstructToolJson(rewritten);

    expect(JSON.parse(reconstructed)).toEqual({
      file_path: "index.html",
      content: "A safe streamed ending",
    });
  });

  it("still rejects a non-duplicate non-comma tail", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const head = JSON.stringify({
      file_path: "index.html",
      content: "valid content",
    });
    const malformed = head + "CHANGED}";

    expect(head.endsWith("CHANGED}")).toBe(false);

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_write_changed_suffix",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, toolFields)).toThrow(InvalidToolInputError);
  });

  it("still rejects truncated JSON", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const malformed = '{"file_path":"index.html","content":"unfinished';

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_write_truncated",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, toolFields)).toThrow(InvalidToolInputError);
  });
});
