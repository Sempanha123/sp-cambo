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

function claudeSchemas() {
  return buildToolInputFieldMap({
    tools: [
      {
        name: "Read",
        input_schema: {
          type: "object",
          properties: {
            file_path: { type: "string" },
            offset: { type: "number" },
            limit: { type: "number" },
          },
          required: ["file_path"],
        },
      },
      {
        name: "EnterPlanMode",
        input_schema: {
          type: "object",
          properties: {},
          required: [],
        },
      },
    ],
  });
}

describe("Claude tool schema validation", () => {
  it("rejects Read {} because file_path is required", () => {
    const fields = claudeSchemas();

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_read_empty",
      name: "Read",
      input: {},
    }, fields)).toThrow(InvalidToolInputError);
  });

  it("accepts Read when file_path is present", () => {
    const fields = claudeSchemas();

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_read_valid",
      name: "read",
      input: { file_path: "index.html" },
    }, fields) as any;

    expect(result.input).toEqual({ file_path: "index.html" });
  });

  it("rejects EnterPlanMode reason because the tool declares no fields", () => {
    const fields = claudeSchemas();

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_plan_bad",
      name: "EnterPlanMode",
      input: { reason: "Need a plan" },
    }, fields)).toThrow(InvalidToolInputError);
  });

  it("accepts EnterPlanMode {}", () => {
    const fields = claudeSchemas();

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_plan_valid",
      name: "ENTERPLANMODE",
      input: {},
    }, fields) as any;

    expect(result.input).toEqual({});
  });

  it("rejects streamed Read {} before the held stream is released", () => {
    const fields = claudeSchemas();
    const guard = new AnthropicToolStreamGuard(fields);

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_read_stream_empty",
        name: "Read",
        input: {},
      },
    }));

    expect(() => guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }))).toThrow(InvalidToolInputError);
  });

  it("rejects streamed EnterPlanMode with unexpected reason", () => {
    const fields = claudeSchemas();
    const guard = new AnthropicToolStreamGuard(fields);

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_plan_stream_bad",
        name: "EnterPlanMode",
        input: {},
      },
    }));

    guard.inspect(sse({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: JSON.stringify({ reason: "Need a plan" }),
      },
    }));

    expect(() => guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }))).toThrow(InvalidToolInputError);
  });

  it("accepts streamed Read with required file_path", () => {
    const fields = claudeSchemas();
    const guard = new AnthropicToolStreamGuard(fields);

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_read_stream_valid",
        name: "Read",
        input: {},
      },
    }));

    guard.inspect(sse({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: JSON.stringify({ file_path: "index.html" }),
      },
    }));

    expect(() => guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }))).not.toThrow();

    expect(() => guard.inspect(sse({
      type: "message_stop",
    }))).not.toThrow();

    expect(() => guard.finish()).not.toThrow();
  });

  it("supports OpenAI-style function schemas too", () => {
    const fields = buildToolInputFieldMap({
      tools: [{
        type: "function",
        function: {
          name: "Read",
          parameters: {
            type: "object",
            properties: {
              file_path: { type: "string" },
            },
            required: ["file_path"],
          },
        },
      }],
    });

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_openai_style_missing",
      name: "Read",
      input: {},
    }, fields)).toThrow(InvalidToolInputError);

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_openai_style_extra",
      name: "Read",
      input: {
        file_path: "index.html",
        reason: "extra",
      },
    }, fields)).toThrow(InvalidToolInputError);
  });
});
