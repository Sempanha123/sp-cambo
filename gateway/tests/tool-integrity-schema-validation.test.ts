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
      {
        name: "AskUserQuestion",
        input_schema: {
          type: "object",
          properties: {
            questions: {
              type: "array",
              items: {
                type: "object",
                properties: {
                  question: { type: "string" },
                },
                required: ["question"],
              },
            },
            annotations: {
              type: "object",
              properties: {
                reason: {
                  type: "object",
                  properties: {
                    summary: { type: "string" },
                  },
                },
              },
            },
          },
          required: ["questions"],
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

  it("drops provider-added EnterPlanMode reason because the tool declares no fields", () => {
    const fields = claudeSchemas();

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_plan_provider_compat",
      name: "EnterPlanMode",
      input: { reason: "Need a plan" },
    }, fields) as any;

    expect(result.input).toEqual({});
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

  it("still rejects unknown EnterPlanMode fields other than reason", () => {
    const fields = claudeSchemas();

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_plan_unknown",
      name: "EnterPlanMode",
      input: { dangerous_field: true },
    }, fields)).toThrow(InvalidToolInputError);
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

  it("accepts streamed EnterPlanMode reason by normalizing it to empty input", () => {
    const fields = claudeSchemas();
    const guard = new AnthropicToolStreamGuard(fields);

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_plan_stream_provider_compat",
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
    }))).not.toThrow();

    expect(() => guard.inspect(sse({
      type: "message_stop",
    }))).not.toThrow();

    expect(() => guard.finish()).not.toThrow();
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

  it("rejects AskUserQuestion when annotations.reason has the wrong nested type", () => {
    const fields = claudeSchemas();

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_question_bad_nested_type",
      name: "AskUserQuestion",
      input: {
        questions: [{ question: "What should I update?" }],
        annotations: {
          reason: "Need clarification",
        },
      },
    }, fields)).toThrow(InvalidToolInputError);
  });

  it("accepts AskUserQuestion when annotations.reason matches the nested schema", () => {
    const fields = claudeSchemas();

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_question_valid_nested_type",
      name: "AskUserQuestion",
      input: {
        questions: [{ question: "What should I update?" }],
        annotations: {
          reason: {
            summary: "Need clarification",
          },
        },
      },
    }, fields) as any;

    expect(result.input.annotations.reason).toEqual({
      summary: "Need clarification",
    });
  });

  it("rejects streamed AskUserQuestion nested type mismatch before release", () => {
    const fields = claudeSchemas();
    const guard = new AnthropicToolStreamGuard(fields);

    guard.inspect(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_question_stream_bad",
        name: "AskUserQuestion",
        input: {},
      },
    }));

    guard.inspect(sse({
      type: "content_block_delta",
      index: 0,
      delta: {
        type: "input_json_delta",
        partial_json: JSON.stringify({
          questions: [{ question: "What should I update?" }],
          annotations: {
            reason: "Need clarification",
          },
        }),
      },
    }));

    expect(() => guard.inspect(sse({
      type: "content_block_stop",
      index: 0,
    }))).toThrow(InvalidToolInputError);
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
