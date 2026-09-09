import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";

const root = path.resolve(process.argv[2] ?? ".");
const runChecks = process.argv.includes("--run-checks");

const toolPath = path.join(root, "gateway", "src", "tool-integrity.ts");
const appPath = path.join(root, "gateway", "src", "app.ts");

function fail(message) {
  throw new Error(`[SP Cambo tool diagnostic v2] ${message}`);
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

function replaceOnce(text, oldText, newText, label) {
  const count = text.split(oldText).length - 1;
  if (count === 0) fail(`Could not find ${label}. No files written.`);
  if (count > 1) fail(`Found ${label} more than once. Refusing ambiguous edit.`);
  return text.replace(oldText, newText);
}

const toolFile = read(toolPath);
const appFile = read(appPath);
let tool = toolFile.text;
let app = appFile.text;

if (tool.includes("summarizeInvalidToolInputShapeV2(")
    || app.includes("[SP Cambo tool diagnostic v2]")) {
  console.log("Diagnostic v2 already appears to be applied.");
} else {
  const helperMarker = `function validateState(state: StreamToolState): string | null {
`;

  const helper = `function safeToolNameForDiagnostic(toolName: string | null): string {
  if (toolName === null || toolName === "") return "unknown";
  return /^[A-Za-z0-9_.:-]{1,128}$/u.test(toolName) ? toolName : "invalid-name";
}

function structuralCharKind(value: string | undefined): string {
  if (value === undefined) return "none";

  switch (value) {
    case ",":
      return "comma";
    case "}":
      return "close_brace";
    case "{":
      return "open_brace";
    case "]":
      return "close_bracket";
    case "[":
      return "open_bracket";
    case "\\"":
      return "quote";
    case ":":
      return "colon";
    default:
      if (/\\s/u.test(value)) return "whitespace";
      if (/[0-9-]/u.test(value)) return "numberish";
      if (/[A-Za-z_$]/u.test(value)) return "wordish";
      return "other";
  }
}

function summarizeInvalidToolInputShapeV2(
  raw: string,
  allowedFields: ReadonlySet<string> | null,
): string {
  const bytes = Buffer.byteLength(raw);
  const boundary = firstCompleteObjectEnd(raw);

  if (boundary === null) {
    return [
      \`bytes=\${bytes}\`,
      "complete_object=false",
      \`first=\${structuralCharKind(raw.trimStart()[0])}\`,
      \`last=\${structuralCharKind(raw.trimEnd().at(-1))}\`,
    ].join(" ");
  }

  const headText = raw.slice(0, boundary).trim();
  const tailText = raw.slice(boundary).trim();

  let headKeys: string[] = [];
  try {
    const head = JSON.parse(headText) as unknown;
    if (record(head)) headKeys = Object.keys(head).sort();
  } catch {
    // Structural diagnostics only.
  }

  let commaTailKeys: string[] = [];
  let commaTailObject = false;

  if (tailText.startsWith(",")) {
    try {
      const parsedTail = JSON.parse(\`{\${tailText.slice(1)}\`) as unknown;
      if (record(parsedTail)) {
        commaTailObject = true;
        commaTailKeys = Object.keys(parsedTail).sort();
      }
    } catch {
      // Structural diagnostics only.
    }
  }

  const tailKeysAllowed =
    commaTailObject
    && allowedFields !== null
    && commaTailKeys.every((key) => allowedFields.has(key));

  const allClosingBraces = tailText !== "" && /^\\}+$/u.test(tailText);
  const allClosingBrackets = tailText !== "" && /^\\]+$/u.test(tailText);

  return [
    \`bytes=\${bytes}\`,
    "complete_object=true",
    \`head_keys=\${JSON.stringify(headKeys)}\`,
    \`tail_bytes=\${Buffer.byteLength(tailText)}\`,
    \`tail_first=\${structuralCharKind(tailText[0])}\`,
    \`tail_last=\${structuralCharKind(tailText.at(-1))}\`,
    \`tail_starts_comma=\${tailText.startsWith(",")}\`,
    \`comma_tail_object=\${commaTailObject}\`,
    \`comma_tail_keys=\${JSON.stringify(commaTailKeys)}\`,
    \`comma_tail_keys_allowed=\${tailKeysAllowed}\`,
    \`allowed_field_count=\${allowedFields?.size ?? 0}\`,
    \`tail_all_closing_braces=\${allClosingBraces}\`,
    \`tail_all_closing_brackets=\${allClosingBrackets}\`,
  ].join(" ");
}

`;

  tool = replaceOnce(
    tool,
    helperMarker,
    helper + helperMarker,
    "validateState marker",
  );

  const validateOld = `      if (repaired !== null) {
        return JSON.stringify(repaired);
      }

      throw originalError;
`;
  const validateNew = `      if (repaired !== null) {
        return JSON.stringify(repaired);
      }

      throw new InvalidToolInputError(
        \`Tool input raw JSON could not be parsed. [tool=\${safeToolNameForDiagnostic(state.toolName)} shape \${summarizeInvalidToolInputShapeV2(state.raw, state.allowedFields)}]\`,
      );
`;

  tool = replaceOnce(
    tool,
    validateOld,
    validateNew,
    "stream invalid-tool diagnostic throw",
  );

  const compatOld = `    if (repaired !== null) {
      return repaired;
    }

    throw originalError;
  }
}

function repairDuplicatedTrailingFields(
`;
  const compatNew = `    if (repaired !== null) {
      return repaired;
    }

    throw new InvalidToolInputError(
      \`Completed tool input raw JSON could not be parsed. [shape \${summarizeInvalidToolInputShapeV2(raw, allowedFields)}]\`,
    );
  }
}

function repairDuplicatedTrailingFields(
`;

  tool = replaceOnce(
    tool,
    compatOld,
    compatNew,
    "complete-response invalid-tool diagnostic throw",
  );

  const appOld = `    } catch (error) {
      void reader.cancel(signal.reason).catch(() => undefined);


      const reason = error instanceof InvalidToolInputError
        ? "upstream_invalid_tool_input"
        : abortReason(signal) ?? "upstream_disconnect";
`;

  const appNew = `    } catch (error) {
      void reader.cancel(signal.reason).catch(() => undefined);

      if (error instanceof InvalidToolInputError) {
        console.warn(
          \`[SP Cambo tool diagnostic v2] upstream_invalid_tool_input request=\${requestId} reservation=\${reservationId}: \${error.message}\`,
        );
      }

      const reason = error instanceof InvalidToolInputError
        ? "upstream_invalid_tool_input"
        : abortReason(signal) ?? "upstream_disconnect";
`;

  app = replaceOnce(
    app,
    appOld,
    appNew,
    "stream catch diagnostic insertion",
  );

  const backupDir = path.join(root, ".git", "sp-cambo-fix-backups");
  if (fs.existsSync(path.join(root, ".git"))) {
    fs.mkdirSync(backupDir, { recursive: true });
    const stamp = new Date().toISOString().replace(/[:.]/g, "-");
    fs.writeFileSync(
      path.join(backupDir, `tool-integrity.ts.before-tool-diagnostic-v2.${stamp}`),
      toolFile.raw,
      "utf8",
    );
    fs.writeFileSync(
      path.join(backupDir, `app.ts.before-tool-diagnostic-v2.${stamp}`),
      appFile.raw,
      "utf8",
    );
  }

  write(toolPath, tool, toolFile.crlf);
  write(appPath, app, appFile.crlf);

  console.log("Applied safe structural tool diagnostic v2.");
  console.log("Changed:");
  console.log("  gateway/src/tool-integrity.ts");
  console.log("  gateway/src/app.ts");
  console.log("");
  console.log("Diagnostic logs include only:");
  console.log("  tool name, byte counts, key names, punctuation classes, schema counts");
  console.log("They do NOT log tool values, commands, prompts, file contents, or secrets.");
}

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
