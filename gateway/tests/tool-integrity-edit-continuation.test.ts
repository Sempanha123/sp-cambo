import { describe, expect, it } from "vitest";
import {
  AnthropicToolStreamGuard,
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

describe("Claude Edit continuation-tail compatibility", () => {
  it("repairs complete Edit fields appended after a prematurely closed object", () => {
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
    }) as any;

    expect(result.input).toEqual({
      file_path: "index.html",
      old_string: "<title>Rustic Bean Coffee</title>",
      new_string: "<title>SP Cambo Coffee</title>",
      replace_all: false,
    });
  });

  it("repairs the same Edit continuation shape in streamed partial_json", () => {
    const malformed =
      `{"file_path":"index.html"},`
      + `"old_string":"old",`
      + `"new_string":"new"}`;

    const guard = new AnthropicToolStreamGuard();
    const frames: string[] = [];

    frames.push(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_edit_stream_continuation",
        name: "Edit",
        input: {},
      },
    }));

    for (let offset = 0; offset < malformed.length; offset += 9) {
      frames.push(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: malformed.slice(offset, offset + 9),
        },
      }));
    }

    frames.push(sse({
      type: "content_block_stop",
      index: 0,
    }));

    frames.push(sse({
      type: "message_stop",
    }));

    for (const frame of frames) {
      guard.inspect(frame);
    }

    const rewritten = guard.rewriteBuffered(frames.join(""));
    const reconstructed = reconstructToolJson(rewritten);

    expect(JSON.parse(reconstructed)).toEqual({
      file_path: "index.html",
      old_string: "old",
      new_string: "new",
    });
  });

  it("still rejects an unknown new trailing field", () => {
    const malformed =
      `{"file_path":"index.html"},`
      + `"old_string":"old",`
      + `"new_string":"new",`
      + `"unexpected_field":true}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_edit_unknown_tail",
      name: "Edit",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    })).toThrow(InvalidToolInputError);
  });

  it("still rejects a conflicting repeated Edit field", () => {
    const malformed =
      `{"file_path":"index.html","old_string":"old"},`
      + `"old_string":"CHANGED",`
      + `"new_string":"new"}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_edit_conflicting_tail",
      name: "Edit",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    })).toThrow(InvalidToolInputError);
  });
});