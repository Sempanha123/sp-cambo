import fs from "node:fs";
import path from "node:path";

const root = path.resolve(process.argv[2] ?? ".");
const runChecks = process.argv.includes("--run-checks");

const appPath = path.join(root, "gateway", "src", "app.ts");
const toolPath = path.join(root, "gateway", "src", "tool-integrity.ts");
const testPath = path.join(root, "gateway", "tests", "tool-integrity-edit-continuation.test.ts");

function fail(message) {
  throw new Error(`[SP Cambo schema-aware tool fix] ${message}`);
}

function readNormalized(file) {
  const raw = fs.readFileSync(file, "utf8");
  return { raw, text: raw.replace(/\r\n/g, "\n"), crlf: raw.includes("\r\n") };
}

function writePreserve(file, text, crlf) {
  fs.writeFileSync(file, crlf ? text.replace(/\n/g, "\r\n") : text, "utf8");
}

function replaceOnce(text, oldText, newText, label) {
  const first = text.indexOf(oldText);
  if (first < 0) fail(`Could not find ${label}. No files were written.`);
  const second = text.indexOf(oldText, first + oldText.length);
  if (second >= 0) fail(`Found ${label} more than once. Refusing an ambiguous edit.`);
  return text.slice(0, first) + newText + text.slice(first + oldText.length);
}

if (!fs.existsSync(appPath)) fail(`Missing ${appPath}`);
if (!fs.existsSync(toolPath)) fail(`Missing ${toolPath}`);

const appSource = readNormalized(appPath);
const toolSource = readNormalized(toolPath);

let app = appSource.text;
let tool = toolSource.text;

if (tool.includes("export type ToolInputFieldMap")) {
  console.log("Schema-aware tool repair already appears to be applied.");
  process.exit(0);
}

/* -------------------------------------------------------------------------- */
/* tool-integrity.ts                                                           */
/* -------------------------------------------------------------------------- */

tool = replaceOnce(
  tool,
`type StreamToolState = {
  raw: string;
  hadObjectInput: boolean;
  sawDelta: boolean;
  toolName: string | null;
};
`,
`export type ToolInputFieldMap = ReadonlyMap<
  string,
  ReadonlySet<string> | null
>;

const EMPTY_TOOL_INPUT_FIELDS: ToolInputFieldMap = new Map();

type StreamToolState = {
  raw: string;
  hadObjectInput: boolean;
  sawDelta: boolean;
  toolName: string | null;
  allowedFields: ReadonlySet<string> | null;
};

/**
 * Build a case-insensitive map of the top-level input fields declared by the
 * customer's original tool schemas.
 *
 * The map is used only for deterministic provider-compatibility repair. It
 * never invents missing values: a trailing continuation field is accepted only
 * when that exact field name was declared by the client for that exact tool.
 */
export function buildToolInputFieldMap(
  body: Record<string, unknown>,
): ToolInputFieldMap {
  const map = new Map<string, ReadonlySet<string> | null>();
  const tools = Array.isArray(body.tools) ? body.tools : [];

  for (const candidate of tools) {
    if (!record(candidate)) continue;

    let name: string | null = null;
    let schema: unknown = null;

    if (typeof candidate.name === "string") {
      name = candidate.name;
      schema = candidate.input_schema;
    } else if (
      record(candidate.function)
      && typeof candidate.function.name === "string"
    ) {
      name = candidate.function.name;
      schema = candidate.function.parameters;
    }

    if (!name || name.length > 128 || /[\r\n\0]/u.test(name)) continue;

    const properties =
      record(schema) && record(schema.properties)
        ? new Set(Object.keys(schema.properties))
        : null;

    const key = name.toLocaleLowerCase("en-US");

    if (map.has(key)) {
      // Case-insensitive duplicate/collision is ambiguous, so continuation
      // repair for that tool is disabled.
      map.set(key, null);
    } else {
      map.set(key, properties);
    }
  }

  return map;
}
`,
  "StreamToolState block",
);

tool = replaceOnce(
  tool,
`export function normalizeCompleteToolInputs(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeCompleteToolInputs(item));
  }
`,
`export function normalizeCompleteToolInputs(
  value: unknown,
  toolInputFields: ToolInputFieldMap = EMPTY_TOOL_INPUT_FIELDS,
): unknown {
  if (Array.isArray(value)) {
    return value.map((item) =>
      normalizeCompleteToolInputs(item, toolInputFields));
  }
`,
  "normalizeCompleteToolInputs signature",
);

tool = replaceOnce(
  tool,
`  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeCompleteToolInputs(child);
  }
`,
`  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeCompleteToolInputs(child, toolInputFields);
  }
`,
  "normalizeCompleteToolInputs recursion",
);

{
  const oldCall = `      const toolName = typeof output.name === "string" ? output.name : null;
      output.input = parseObjectCompat(raw, toolName);
`;
  const newCall = `      const toolName = typeof output.name === "string" ? output.name : null;
      output.input = parseObjectCompat(
        raw,
        allowedFieldsForTool(toolName, toolInputFields),
      );
`;

  const occurrences = tool.split(oldCall).length - 1;

  if (occurrences !== 2) {
    fail(
      `Expected exactly 2 tool compatibility call sites, found ${occurrences}. No files were written.`,
    );
  }

  tool = tool.split(oldCall).join(newCall);
}

tool = replaceOnce(
  tool,
`export function rewriteSseToolInputs(text: string): string {
`,
`export function rewriteSseToolInputs(
  text: string,
  toolInputFields: ToolInputFieldMap = EMPTY_TOOL_INPUT_FIELDS,
): string {
`,
  "rewriteSseToolInputs signature",
);

tool = replaceOnce(
  tool,
`      return \`data: \${JSON.stringify(normalizeStreamEvent(parsed))}\`;
`,
`      return \`data: \${JSON.stringify(
        normalizeStreamEvent(parsed, toolInputFields),
      )}\`;
`,
  "rewriteSseToolInputs normalize call",
);

tool = replaceOnce(
  tool,
`export class AnthropicToolStreamGuard {
  private readonly active = new Map<number, StreamToolState>();
  private readonly repairs = new Map<number, string>();

  inspect(frame: string): void {
`,
`export class AnthropicToolStreamGuard {
  private readonly active = new Map<number, StreamToolState>();
  private readonly repairs = new Map<number, string>();

  constructor(
    private readonly toolInputFields: ToolInputFieldMap = EMPTY_TOOL_INPUT_FIELDS,
  ) {}

  inspect(frame: string): void {
`,
  "AnthropicToolStreamGuard constructor",
);

tool = replaceOnce(
  tool,
`        const raw = unparsedRaw(block.input);
        const hadObjectInput = record(block.input) && raw === null;
        const toolName = typeof block.name === "string" ? block.name : null;
`,
`        const raw = unparsedRaw(block.input);
        const hadObjectInput = record(block.input) && raw === null;
        const toolName = typeof block.name === "string" ? block.name : null;
        const allowedFields = allowedFieldsForTool(
          toolName,
          this.toolInputFields,
        );
`,
  "stream tool schema lookup",
);

tool = replaceOnce(
  tool,
`          sawDelta: false,
          toolName,
        });
`,
`          sawDelta: false,
          toolName,
          allowedFields,
        });
`,
  "stream state allowed fields",
);

tool = replaceOnce(
  tool,
`function normalizeStreamEvent(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeStreamEvent(item));
  }
`,
`function normalizeStreamEvent(
  value: unknown,
  toolInputFields: ToolInputFieldMap,
): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeStreamEvent(item, toolInputFields));
  }
`,
  "normalizeStreamEvent signature",
);

tool = replaceOnce(
  tool,
`  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeStreamEvent(child);
  }
`,
`  for (const [key, child] of Object.entries(value)) {
    output[key] = normalizeStreamEvent(child, toolInputFields);
  }
`,
  "normalizeStreamEvent recursion",
);


tool = replaceOnce(
  tool,
`      const repaired = repairDuplicatedTrailingFields(state.raw, state.toolName);
`,
`      const repaired = repairDuplicatedTrailingFields(
        state.raw,
        state.allowedFields,
      );
`,
  "stream state repair call",
);

tool = replaceOnce(
  tool,
` * We always repair deterministic identical duplicate tails. For the Claude Code
 * Edit tool only, we also repair a complete continuation tail made exclusively
 * from known Edit schema fields. Unknown fields, changed duplicate values, and
 * truncated/ambiguous JSON remain rejected.
 */
function parseObjectCompat(
  raw: string,
  toolName: string | null = null,
): Record<string, unknown> {
`,
` * We always repair deterministic identical duplicate tails. We also repair a
 * complete continuation tail only when every new top-level field was declared
 * by the customer's original schema for that exact tool. Unknown fields,
 * changed duplicate values, and truncated/ambiguous JSON remain rejected.
 */
function parseObjectCompat(
  raw: string,
  allowedFields: ReadonlySet<string> | null = null,
): Record<string, unknown> {
`,
  "parseObjectCompat comment/signature",
);

tool = replaceOnce(
  tool,
`    const repaired = repairDuplicatedTrailingFields(raw, toolName);
`,
`    const repaired = repairDuplicatedTrailingFields(raw, allowedFields);
`,
  "parseObjectCompat repair call",
);

const repairStart = tool.indexOf("const SAFE_EDIT_TRAILING_CONTINUATION_FIELDS");
const firstObjectEnd = tool.indexOf("function firstCompleteObjectEnd(", repairStart);

if (repairStart < 0 || firstObjectEnd < 0) {
  fail("Could not locate the existing Edit-only repair block. No files were written.");
}

const generalizedRepair = `function repairDuplicatedTrailingFields(
  raw: string,
  allowedFields: ReadonlySet<string> | null = null,
): Record<string, unknown> | null {
  const boundary = firstCompleteObjectEnd(raw);

  if (boundary === null) return null;

  const headText = raw.slice(0, boundary).trim();
  const tailText = raw.slice(boundary).trim();

  if (tailText === "" || !tailText.startsWith(",")) {
    return null;
  }

  let head: unknown;
  let tail: unknown;

  try {
    head = JSON.parse(headText) as unknown;
    tail = JSON.parse(\`{\${tailText.slice(1)}\`) as unknown;
  } catch {
    return null;
  }

  if (!record(head) || !record(tail) || Object.keys(tail).length === 0) {
    return null;
  }

  const merged: Record<string, unknown> = { ...head };

  for (const [key, value] of Object.entries(tail)) {
    if (Object.prototype.hasOwnProperty.call(head, key)) {
      // A duplicate is safe only if it is byte-semantically the same JSON
      // value. Conflicting repeats remain invalid.
      if (!sameJsonValue(head[key], value)) {
        return null;
      }

      continue;
    }

    // A provider may prematurely close a tool input object and then continue
    // the SAME object with additional top-level fields. Repair that defect only
    // when the original client schema proves that this exact field belongs to
    // this exact tool.
    if (allowedFields === null || !allowedFields.has(key)) {
      return null;
    }

    merged[key] = value;
  }

  return merged;
}

function allowedFieldsForTool(
  toolName: string | null,
  toolInputFields: ToolInputFieldMap,
): ReadonlySet<string> | null {
  if (toolName === null) return null;

  return toolInputFields.get(
    toolName.toLocaleLowerCase("en-US"),
  ) ?? null;
}
`;

tool =
  tool.slice(0, repairStart)
  + generalizedRepair
  + tool.slice(firstObjectEnd);

/* -------------------------------------------------------------------------- */
/* app.ts                                                                      */
/* -------------------------------------------------------------------------- */

app = replaceOnce(
  app,
`import { AnthropicToolStreamGuard, InvalidToolInputError, MAX_BUFFERED_TOOL_STREAM_BYTES, normalizeCompleteToolInputs, rewriteSseToolInputs } from "./tool-integrity.js";
`,
`import { AnthropicToolStreamGuard, buildToolInputFieldMap, InvalidToolInputError, MAX_BUFFERED_TOOL_STREAM_BYTES, normalizeCompleteToolInputs, rewriteSseToolInputs, type ToolInputFieldMap } from "./tool-integrity.js";
`,
  "tool-integrity import",
);

app = replaceOnce(
  app,
`    const toolNames = buildToolNameMap(prepared.body);
`,
`    const toolNames = buildToolNameMap(prepared.body);
    const toolInputFields = buildToolInputFieldMap(prepared.body);
`,
  "tool input field map creation",
);

app = replaceOnce(
  app,
`                  toolNames,
                  localInput.input_tokens,
`,
`                  toolNames,
                  toolInputFields,
                  localInput.input_tokens,
`,
  "stream call tool field map",
);

app = replaceOnce(
  app,
`                toolNames,
                localInput.input_tokens,
`,
`                toolNames,
                toolInputFields,
                localInput.input_tokens,
`,
  "json call tool field map",
);

app = replaceOnce(
  app,
`  async function json(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestStartedAt: number, signal: AbortSignal, toolNames: ToolNameMap, localInputTokens: number, localCacheReadTokens: number, publicModel: string, onPublicOutputStarted: () => void): Promise<unknown> {
`,
`  async function json(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestStartedAt: number, signal: AbortSignal, toolNames: ToolNameMap, toolInputFields: ToolInputFieldMap, localInputTokens: number, localCacheReadTokens: number, publicModel: string, onPublicOutputStarted: () => void): Promise<unknown> {
`,
  "json signature",
);

app = replaceOnce(
  app,
`        normalizeToolNames(normalizeCompleteToolInputs(parsed), toolNames),
`,
`        normalizeToolNames(
          normalizeCompleteToolInputs(parsed, toolInputFields),
          toolNames,
        ),
`,
  "json tool input normalization",
);

app = replaceOnce(
  app,
`  async function stream(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestId: string, requestStartedAt: number, signal: AbortSignal, toolNames: ToolNameMap, localInputTokens: number, localCacheReadTokens: number, publicModel: string, onPublicOutputStarted: () => void, onUpstreamActivity: () => void): Promise<void> {
`,
`  async function stream(reply: FastifyReply, upstream: Response, reservationId: string, path: InferencePath, requestId: string, requestStartedAt: number, signal: AbortSignal, toolNames: ToolNameMap, toolInputFields: ToolInputFieldMap, localInputTokens: number, localCacheReadTokens: number, publicModel: string, onPublicOutputStarted: () => void, onUpstreamActivity: () => void): Promise<void> {
`,
  "stream signature",
);

app = replaceOnce(
  app,
`      ? new AnthropicToolStreamGuard()
`,
`      ? new AnthropicToolStreamGuard(toolInputFields)
`,
  "stream guard constructor",
);

app = replaceOnce(
  app,
`        rewriteSseToolNames(rewriteSseToolInputs(text), toolNames),
`,
`        rewriteSseToolNames(
          rewriteSseToolInputs(text, toolInputFields),
          toolNames,
        ),
`,
  "stream SSE normalization",
);

/* -------------------------------------------------------------------------- */
/* Tests                                                                        */
/* -------------------------------------------------------------------------- */

const tests = `import { describe, expect, it } from "vitest";
import {
  AnthropicToolStreamGuard,
  buildToolInputFieldMap,
  InvalidToolInputError,
  normalizeCompleteToolInputs,
} from "../src/tool-integrity.js";

function sse(value: unknown): string {
  return \`event: x\\ndata: \${JSON.stringify(value)}\\n\\n\`;
}

function reconstructToolJson(text: string): string {
  return text
    .split(/\\r?\\n/)
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
      \`{"file_path":"index.html"},\`
      + \`"old_string":"<title>Rustic Bean Coffee</title>",\`
      + \`"new_string":"<title>SP Cambo Coffee</title>",\`
      + \`"replace_all":false}\`;

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
      \`{"file_path":"index.html"},\`
      + \`"offset":1,\`
      + \`"limit":20}\`;

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
      \`{"command":"node --version"},\`
      + \`"description":"Check Node",\`
      + \`"timeout":120000}\`;

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
      \`{"file_path":"index.html"},\`
      + \`"unexpected_field":true}\`;

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
      \`{"file_path":"index.html","old_string":"old"},\`
      + \`"old_string":"CHANGED",\`
      + \`"new_string":"new"}\`;

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

    const malformed = \`{"file_path":"index.html","content":"unfinished\`;

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
`;

const testSource = fs.existsSync(testPath) ? readNormalized(testPath) : { crlf: false };

/* -------------------------------------------------------------------------- */
/* Validate and write                                                          */
/* -------------------------------------------------------------------------- */

const requiredToolMarkers = [
  "export type ToolInputFieldMap",
  "export function buildToolInputFieldMap",
  "allowedFieldsForTool",
  "state.allowedFields",
];

for (const marker of requiredToolMarkers) {
  if (!tool.includes(marker)) fail(`Post-patch tool-integrity marker missing: ${marker}`);
}

const requiredAppMarkers = [
  "const toolInputFields = buildToolInputFieldMap(prepared.body);",
  "new AnthropicToolStreamGuard(toolInputFields)",
  "rewriteSseToolInputs(text, toolInputFields)",
  "normalizeCompleteToolInputs(parsed, toolInputFields)",
];

for (const marker of requiredAppMarkers) {
  if (!app.includes(marker)) fail(`Post-patch app marker missing: ${marker}`);
}

const backupDir = path.join(root, ".git", "sp-cambo-fix-backups");
if (fs.existsSync(path.join(root, ".git"))) {
  fs.mkdirSync(backupDir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  fs.writeFileSync(path.join(backupDir, `app.ts.before-schema-tool-fix.${stamp}`), appSource.raw);
  fs.writeFileSync(path.join(backupDir, `tool-integrity.ts.before-schema-tool-fix.${stamp}`), toolSource.raw);
  if (fs.existsSync(testPath)) {
    fs.writeFileSync(
      path.join(backupDir, `tool-integrity-edit-continuation.test.ts.before-schema-tool-fix.${stamp}`),
      fs.readFileSync(testPath),
    );
  }
}

writePreserve(appPath, app, appSource.crlf);
writePreserve(toolPath, tool, toolSource.crlf);
writePreserve(testPath, tests, testSource.crlf);

console.log("Applied schema-aware Claude tool continuation repair.");
console.log("Changed:");
console.log("  gateway/src/app.ts");
console.log("  gateway/src/tool-integrity.ts");
console.log("  gateway/tests/tool-integrity-edit-continuation.test.ts");
console.log("");
console.log("Safety rules preserved:");
console.log("  - identical duplicate tails: repair");
console.log("  - new tail field declared by the exact client tool schema: repair");
console.log("  - unknown field: reject");
console.log("  - conflicting duplicate: reject");
console.log("  - truncated/ambiguous JSON: reject");
console.log("");

if (runChecks) {
  const { spawnSync } = await import("node:child_process");
  const gateway = path.join(root, "gateway");

  for (const [cmd, args] of [
    ["pnpm", ["test"]],
    ["pnpm", ["typecheck"]],
    ["pnpm", ["build"]],
  ]) {
    console.log(`> ${cmd} ${args.join(" ")}`);
    const result = spawnSync(cmd, args, {
      cwd: gateway,
      stdio: "inherit",
      shell: process.platform === "win32",
    });
    if (result.status !== 0) {
      fail(`${cmd} ${args.join(" ")} failed.`);
    }
  }

  console.log("");
  console.log("ALL GATEWAY CHECKS PASSED.");
} else {
  console.log("Next:");
  console.log("  cd gateway");
  console.log("  pnpm test");
  console.log("  pnpm typecheck");
  console.log("  pnpm build");
}
