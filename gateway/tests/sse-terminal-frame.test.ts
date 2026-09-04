import { describe, expect, it } from "vitest";
import { terminalSseFrame } from "../src/app.js";

describe("terminalSseFrame", () => {
  it("detects OpenAI [DONE]", () => {
    expect(
      terminalSseFrame("data: [DONE]\n\n", "/v1/chat/completions"),
    ).toBe(true);
  });

  it("detects a final Chat Completions finish_reason", () => {
    const frame = `data: ${JSON.stringify({
      choices: [{ index: 0, delta: {}, finish_reason: "stop" }],
    })}\n\n`;

    expect(
      terminalSseFrame(frame, "/v1/chat/completions"),
    ).toBe(true);
  });

  it("keeps a normal Chat Completions delta open", () => {
    const frame = `data: ${JSON.stringify({
      choices: [{ index: 0, delta: { content: "hello" }, finish_reason: null }],
    })}\n\n`;

    expect(
      terminalSseFrame(frame, "/v1/chat/completions"),
    ).toBe(false);
  });

  it("detects Anthropic message_stop", () => {
    expect(
      terminalSseFrame(
        'event: message_stop\ndata: {"type":"message_stop"}\n\n',
        "/v1/messages",
      ),
    ).toBe(true);
  });

  it("detects Responses API completion", () => {
    expect(
      terminalSseFrame(
        'event: response.completed\ndata: {"type":"response.completed"}\n\n',
        "/v1/responses",
      ),
    ).toBe(true);
  });
});