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

  it("repairs the exact duplicated Bash description shape from a compatible provider", () => {
    const original = {
      command: 'ls -la "C:\\Users\\Rg Gear\\Desktop\\New folder (2)"',
      description: "List files in working directory",
    };

    const valid = JSON.stringify(original);
    const duplicated =
      `${valid}, "description": ${JSON.stringify(original.description)}}`;

    const result = normalizeCompleteToolInputs({
      type: "message",
      content: [{
        type: "tool_use",
        id: "tool_bash_duplicate",
        name: "Bash",
        input: {
          __unparsedToolInput: {
            raw: duplicated,
            len: Buffer.byteLength(duplicated),
          },
        },
      }],
    }) as any;

    expect(result.content[0].input.command).toBe(original.command);
    expect(result.content[0].input.description).toBe(original.description);
  });

  it("repairs multiple duplicated trailing fields only when values are identical", () => {
    const original = {
      command: "pwd",
      description: "Show directory",
    };

    const valid = JSON.stringify(original);
    const duplicated =
      `${valid}, "description": "Show directory", "command": "pwd"}`;

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_multi_duplicate",
      name: "Bash",
      input: {
        __unparsedToolInput: {
          raw: duplicated,
          len: Buffer.byteLength(duplicated),
        },
      },
    }) as any;

    expect(result.input).toEqual(original);
  });

  it("rejects duplicate-tail repair when the provider changes the repeated value", () => {
    const original = {
      command: "pwd",
      description: "Show directory",
    };

    const invalid =
      `${JSON.stringify(original)}, "description": "Run something else"}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_bad_duplicate",
      name: "Bash",
      input: {
        __unparsedToolInput: {
          raw: invalid,
          len: Buffer.byteLength(invalid),
        },
      },
    })).toThrow(InvalidToolInputError);
  });

  it("rejects duplicate-tail repair when a new unknown trailing field is appended", () => {
    const original = {
      command: "pwd",
      description: "Show directory",
    };

    const invalid =
      `${JSON.stringify(original)}, "dangerous_new_field": true}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_unknown_tail",
      name: "Bash",
      input: {
        __unparsedToolInput: {
          raw: invalid,
          len: Buffer.byteLength(invalid),
        },
      },
    })).toThrow(InvalidToolInputError);
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

  it("repairs duplicated trailing fields in streamed partial_json before public flush", () => {
    const original = {
      command: "pwd",
      description: "Show directory",
    };
    const valid = JSON.stringify(original);
    const duplicated =
      `${valid}, "description": ${JSON.stringify(original.description)}}`;
    const guard = new AnthropicToolStreamGuard();
    const frames: string[] = [];

    frames.push(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_stream_duplicate",
        name: "Bash",
        input: {},
      },
    }));

    for (let offset = 0; offset < duplicated.length; offset += 11) {
      frames.push(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: duplicated.slice(offset, offset + 11),
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
    const reconstructed = rewritten
      .split(/\r?\n/)
      .filter((line) => line.startsWith("data:"))
      .map((line) => JSON.parse(line.slice(5).trim()) as any)
      .filter((event) =>
        event.type === "content_block_delta"
        && event.delta?.type === "input_json_delta")
      .map((event) => event.delta.partial_json)
      .join("");

    expect(reconstructed).toBe(JSON.stringify(original));
    expect(JSON.parse(reconstructed)).toEqual(original);
  });

  it("rejects a changed duplicate tail in streamed partial_json", () => {
    const original = {
      command: "pwd",
      description: "Show directory",
    };
    const invalid =
      `${JSON.stringify(original)}, "description": "Run something else"}`;
    const guard = new AnthropicToolStreamGuard();

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_stream_bad_duplicate",
        name: "Bash",
        input: {},
      },
    }));

    guard.inspect(sse({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: invalid,
      },
    }));

    expect(() => guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }))).toThrow(InvalidToolInputError);
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
