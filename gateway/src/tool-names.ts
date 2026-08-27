export type ToolNameMap = ReadonlyMap<string, string | null>;

/**
 * Build a case-insensitive lookup from the exact tool names supplied by the
 * customer client. Some OpenAI-compatible upstream adapters normalize function
 * names to lowercase. Claude Code treats tool names as case-sensitive, so the
 * gateway restores only casing that can be proven from the original request.
 *
 * A case-insensitive collision (for example both `Bash` and `bash`) is marked
 * ambiguous and is never rewritten.
 */
export function buildToolNameMap(body: Record<string, unknown>): ToolNameMap {
  const map = new Map<string, string | null>();
  const tools = Array.isArray(body.tools) ? body.tools : [];

  for (const tool of tools) {
    const name = requestToolName(tool);
    if (!name) continue;
    const key = name.toLocaleLowerCase("en-US");
    const existing = map.get(key);
    if (existing === undefined) map.set(key, name);
    else if (existing !== name) map.set(key, null);
  }

  return map;
}

/** Restore exact request-defined tool casing in a parsed upstream response. */
export function normalizeToolNames(value: unknown, names: ToolNameMap): unknown {
  if (names.size === 0) return value;
  if (Array.isArray(value)) return value.map((item) => normalizeToolNames(item, names));
  if (!record(value)) return value;

  const output: Record<string, unknown> = {};
  for (const [key, child] of Object.entries(value)) output[key] = normalizeToolNames(child, names);

  const type = typeof output.type === "string" ? output.type : null;
  if (type !== null && ["tool_use", "function_call", "custom_tool_call", "tool_call"].includes(type) && typeof output.name === "string") {
    output.name = exactToolName(output.name, names);
  }

  // OpenAI Chat Completions tool calls place the function name under
  // `tool_calls[].function.name` (streaming uses the same nested shape).
  if (record(output.function) && typeof output.function.name === "string") {
    output.function = { ...output.function, name: exactToolName(output.function.name, names) };
  }

  return output;
}

/** Rewrite JSON payloads inside one or more complete SSE events. */
export function rewriteSseToolNames(text: string, names: ToolNameMap): string {
  if (names.size === 0 || text === "") return text;

  return text
    .split(/(\r?\n)/)
    .map((part) => {
      if (!part.startsWith("data:")) return part;
      const data = part.slice(5).trim();
      if (data === "" || data === "[DONE]") return part;
      try {
        return `data: ${JSON.stringify(normalizeToolNames(JSON.parse(data), names))}`;
      } catch {
        return part;
      }
    })
    .join("");
}

function requestToolName(tool: unknown): string | null {
  if (!record(tool)) return null;
  if (typeof tool.name === "string" && validName(tool.name)) return tool.name;
  if (record(tool.function) && typeof tool.function.name === "string" && validName(tool.function.name)) return tool.function.name;
  return null;
}

function exactToolName(upstreamName: string, names: ToolNameMap): string {
  const match = names.get(upstreamName.toLocaleLowerCase("en-US"));
  return typeof match === "string" ? match : upstreamName;
}

function validName(value: string): boolean {
  return value.length >= 1 && value.length <= 128 && !/[\r\n\0]/.test(value);
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
