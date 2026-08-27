import { describe, expect, it } from "vitest";
import { buildToolNameMap, normalizeToolNames, rewriteSseToolNames } from "../src/tool-names.js";

describe("tool name compatibility", () => {
  it("restores Anthropic tool_use casing from the incoming schema", () => {
    const names = buildToolNameMap({ tools: [{ name: "Bash" }, { name: "Edit" }] });
    const result = normalizeToolNames({ content: [{ type: "tool_use", id: "t1", name: "bash", input: {} }] }, names) as any;
    expect(result.content[0].name).toBe("Bash");
  });

  it("restores OpenAI function-call casing without changing unknown tools", () => {
    const names = buildToolNameMap({ tools: [{ type: "function", function: { name: "Read" } }] });
    const result = normalizeToolNames({ choices: [{ message: { tool_calls: [
      { type: "function", function: { name: "read", arguments: "{}" } },
      { type: "function", function: { name: "unknown_tool", arguments: "{}" } },
    ] } }] }, names) as any;
    expect(result.choices[0].message.tool_calls[0].function.name).toBe("Read");
    expect(result.choices[0].message.tool_calls[1].function.name).toBe("unknown_tool");
  });

  it("does not guess when request tool names collide case-insensitively", () => {
    const names = buildToolNameMap({ tools: [{ name: "Bash" }, { name: "bash" }] });
    const result = normalizeToolNames({ type: "tool_use", name: "bash" }, names) as any;
    expect(result.name).toBe("bash");
  });

  it("rewrites streamed SSE tool names while preserving event framing", () => {
    const names = buildToolNameMap({ tools: [{ name: "Edit" }] });
    const input = `event: content_block_start\ndata: ${JSON.stringify({ type: "content_block_start", content_block: { type: "tool_use", name: "edit", id: "tool_1" } })}\n\n`;
    const output = rewriteSseToolNames(input, names);
    expect(output).toContain('"name":"Edit"');
    expect(output).toContain("event: content_block_start");
    expect(output.endsWith("\n\n")).toBe(true);
  });
});
