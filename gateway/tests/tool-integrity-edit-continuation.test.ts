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

function reconstructToolJson(text: string): string {
  return text
    .split(/\r?\n/)
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

function anthropicToolFields(
  name: string,
  properties: Record<string, unknown>,
) {
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

describe("schema-aware Claude tool continuation compatibility", () => {
  it("repairs Edit continuation fields declared by the client schema", () => {
    const fields = anthropicToolFields("Edit", {
      file_path: { type: "string" },
      old_string: { type: "string" },
      new_string: { type: "string" },
      replace_all: { type: "boolean" },
    });

    const malformed =
      `{"file_path":"index.html"},`
      + `"old_string":"<title>Rustic Bean Coffee</title>",`
      + `"new_string":"<title>SP Cambo Coffee</title>",`
      + `"replace_all":false}`;

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_edit_continuation",
      name: "Edit",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, fields) as any;

    expect(result.input).toEqual({
      file_path: "index.html",
      old_string: "<title>Rustic Bean Coffee</title>",
      new_string: "<title>SP Cambo Coffee</title>",
      replace_all: false,
    });
  });

  it("repairs Read continuation fields declared by the client schema", () => {
    const fields = anthropicToolFields("Read", {
      file_path: { type: "string" },
      offset: { type: "number" },
      limit: { type: "number" },
    });

    const malformed =
      `{"file_path":"index.html"},`
      + `"offset":1,`
      + `"limit":20}`;

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_read_continuation",
      name: "read",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, fields) as any;

    expect(result.input).toEqual({
      file_path: "index.html",
      offset: 1,
      limit: 20,
    });
  });

  it("repairs streamed Bash continuation using its original schema", () => {
    const fields = anthropicToolFields("Bash", {
      command: { type: "string" },
      description: { type: "string" },
      timeout: { type: "number" },
    });

    const malformed =
      `{"command":"node --version"},`
      + `"description":"Check Node",`
      + `"timeout":120000}`;

    const guard = new AnthropicToolStreamGuard(fields);
    const frames: string[] = [];

    frames.push(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_bash_stream_continuation",
        name: "bash",
        input: {},
      },
    }));

    for (let offset = 0; offset < malformed.length; offset += 11) {
      frames.push(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: malformed.slice(offset, offset + 11),
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
      command: "node --version",
      description: "Check Node",
      timeout: 120000,
    });
  });

  it("still rejects a new field that is not in the original tool schema", () => {
    const fields = anthropicToolFields("Read", {
      file_path: { type: "string" },
    });

    const malformed =
      `{"file_path":"index.html"},`
      + `"unexpected_field":true}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_unknown_tail",
      name: "Read",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, fields)).toThrow(InvalidToolInputError);
  });

  it("still rejects conflicting duplicate values", () => {
    const fields = anthropicToolFields("Edit", {
      file_path: { type: "string" },
      old_string: { type: "string" },
      new_string: { type: "string" },
    });

    const malformed =
      `{"file_path":"index.html","old_string":"old"},`
      + `"old_string":"CHANGED",`
      + `"new_string":"new"}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_conflicting_tail",
      name: "Edit",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, fields)).toThrow(InvalidToolInputError);
  });

  it("still rejects truncated JSON even when the schema knows every field", () => {
    const fields = anthropicToolFields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const malformed = `{"file_path":"index.html","content":"unfinished`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_truncated",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, fields)).toThrow(InvalidToolInputError);
  });
});
