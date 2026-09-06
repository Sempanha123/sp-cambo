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
