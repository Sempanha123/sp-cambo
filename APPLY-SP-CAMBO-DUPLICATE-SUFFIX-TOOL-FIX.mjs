import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";

const root = path.resolve(process.argv[2] ?? ".");
const runChecks = process.argv.includes("--run-checks");

const toolPath = path.join(root, "gateway", "src", "tool-integrity.ts");
const testPath = path.join(root, "gateway", "tests", "tool-integrity-duplicate-suffix.test.ts");

function fail(message) {
  throw new Error(`[SP Cambo duplicate-suffix tool fix] ${message}`);
}

function read(file) {
  if (!fs.existsSync(file)) fail(`Missing ${file}`);
  const raw = fs.readFileSync(file, "utf8");
  return {
    raw,
    crlf: raw.includes("\r\n"),
    text: raw.replace(/\r\n/g, "\n"),
  };
}

function write(file, text, crlf) {
  fs.writeFileSync(file, crlf ? text.replace(/\n/g, "\r\n") : text, "utf8");
}

const source = read(toolPath);
let tool = source.text;

const patchedMarker = `// Exact duplicated suffixes are safe to discard because the complete object`;

if (!tool.includes(patchedMarker)) {
  const oldBlock = `  const headText = raw.slice(0, boundary).trim();
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
`;

  const newBlock = `  const headText = raw.slice(0, boundary).trim();
  const tailText = raw.slice(boundary).trim();

  if (tailText === "") {
    return null;
  }

  let head: unknown;

  try {
    head = JSON.parse(headText) as unknown;
  } catch {
    return null;
  }

  if (!record(head)) {
    return null;
  }

  if (!tailText.startsWith(",")) {
    // Exact duplicated suffixes are safe to discard because the complete object
    // already contains every byte being repeated after its closing brace.
    //
    // This targets compatible providers that emit overlapping partial_json
    // chunks, for example:
    //
    //   {"command":"echo 120000","description":"Check 120000"}120000"}
    //
    // The malformed tail is repaired only when it is byte-for-byte an exact
    // suffix of the already valid object. Arbitrary garbage or a changed suffix
    // is still rejected.
    return headText.endsWith(tailText) ? head : null;
  }

  let tail: unknown;

  try {
    tail = JSON.parse(\`{\${tailText.slice(1)}\`) as unknown;
  } catch {
    return null;
  }

  if (!record(tail) || Object.keys(tail).length === 0) {
    return null;
  }
`;

  const count = tool.split(oldBlock).length - 1;
  if (count !== 1) {
    fail(`Expected exactly one repair function block, found ${count}. No files written.`);
  }

  tool = tool.replace(oldBlock, newBlock);

  if (fs.existsSync(path.join(root, ".git"))) {
    const backupDir = path.join(root, ".git", "sp-cambo-fix-backups");
    fs.mkdirSync(backupDir, { recursive: true });
    const stamp = new Date().toISOString().replace(/[:.]/g, "-");
    fs.writeFileSync(
      path.join(backupDir, `tool-integrity.ts.before-duplicate-suffix-fix.${stamp}`),
      source.raw,
      "utf8",
    );
  }

  write(toolPath, tool, source.crlf);
  console.log("Applied deterministic duplicate-suffix repair.");
} else {
  console.log("Duplicate-suffix repair already appears to be applied.");
}

const testContent = `import { describe, expect, it } from "vitest";
import {
  AnthropicToolStreamGuard,
  buildToolInputFieldMap,
  InvalidToolInputError,
  normalizeCompleteToolInputs,
} from "../src/tool-integrity.js";

function sse(value: unknown): string {
  return \`event: x\\ndata: \${JSON.stringify(value)}\\n\\n\`;
}

function fields(name: string, properties: Record<string, unknown>) {
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

function reconstructToolJson(text: string): string {
  return text
    .split(/\\r?\\n/u)
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

describe("deterministic duplicated tool-input suffix compatibility", () => {
  it("repairs a Write object followed by an exact word suffix duplicate", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const head = JSON.stringify({
      file_path: "index.html",
      content: "Coffee landing page ending with coffee",
    });
    const suffix = 'coffee"}';

    expect(head.endsWith(suffix)).toBe(true);

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_write_suffix",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: head + suffix,
          len: Buffer.byteLength(head + suffix),
        },
      },
    }, toolFields) as any;

    expect(result.input).toEqual({
      file_path: "index.html",
      content: "Coffee landing page ending with coffee",
    });
  });

  it("repairs a Bash object followed by an exact numeric suffix duplicate", () => {
    const toolFields = fields("Bash", {
      command: { type: "string" },
      description: { type: "string" },
      timeout: { type: "number" },
    });

    const head = JSON.stringify({
      command: "node --version",
      description: "Check Node",
      timeout: 120000,
    });
    const suffix = "120000}";

    expect(head.endsWith(suffix)).toBe(true);

    const result = normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_bash_suffix",
      name: "Bash",
      input: {
        __unparsedToolInput: {
          raw: head + suffix,
          len: Buffer.byteLength(head + suffix),
        },
      },
    }, toolFields) as any;

    expect(result.input).toEqual({
      command: "node --version",
      description: "Check Node",
      timeout: 120000,
    });
  });

  it("repairs the same duplicated suffix in a streamed tool call", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const head = JSON.stringify({
      file_path: "index.html",
      content: "A safe streamed ending",
    });
    const suffix = 'ending"}';
    const malformed = head + suffix;

    expect(head.endsWith(suffix)).toBe(true);

    const guard = new AnthropicToolStreamGuard(toolFields);
    const frames: string[] = [];

    frames.push(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_write_stream_suffix",
        name: "Write",
        input: {},
      },
    }));

    for (let offset = 0; offset < malformed.length; offset += 13) {
      frames.push(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: malformed.slice(offset, offset + 13),
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
      file_path: "index.html",
      content: "A safe streamed ending",
    });
  });

  it("still rejects a non-duplicate non-comma tail", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const head = JSON.stringify({
      file_path: "index.html",
      content: "valid content",
    });
    const malformed = head + "CHANGED}";

    expect(head.endsWith("CHANGED}")).toBe(false);

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_write_changed_suffix",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, toolFields)).toThrow(InvalidToolInputError);
  });

  it("still rejects truncated JSON", () => {
    const toolFields = fields("Write", {
      file_path: { type: "string" },
      content: { type: "string" },
    });

    const malformed = '{"file_path":"index.html","content":"unfinished';

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_write_truncated",
      name: "Write",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    }, toolFields)).toThrow(InvalidToolInputError);
  });
});
`;

if (!fs.existsSync(testPath)) {
  fs.writeFileSync(testPath, testContent, "utf8");
  console.log("Created gateway/tests/tool-integrity-duplicate-suffix.test.ts");
} else {
  const existing = fs.readFileSync(testPath, "utf8");
  if (existing !== testContent) {
    fail(`Test file already exists with different content: ${testPath}`);
  }
  console.log("Duplicate-suffix test file already exists.");
}

console.log("");
console.log("Safety preserved:");
console.log("  - exact duplicated suffix after a complete valid object: repair");
console.log("  - schema-aware comma continuation: repair");
console.log("  - conflicting/changed tail: reject");
console.log("  - unknown continuation field: reject");
console.log("  - truncated JSON: reject");

if (runChecks) {
  const gatewayDir = path.join(root, "gateway");
  for (const [cmd, args] of [
    ["pnpm", ["test"]],
    ["pnpm", ["typecheck"]],
    ["pnpm", ["build"]],
  ]) {
    console.log(`> ${cmd} ${args.join(" ")}`);
    const result = spawnSync(cmd, args, {
      cwd: gatewayDir,
      stdio: "inherit",
      shell: process.platform === "win32",
    });
    if (result.status !== 0) {
      fail(`${cmd} ${args.join(" ")} failed.`);
    }
  }

  console.log("");
  console.log("ALL GATEWAY CHECKS PASSED.");
}
