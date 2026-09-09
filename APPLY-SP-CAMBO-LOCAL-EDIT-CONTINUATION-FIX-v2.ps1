param(
    [string]$RepoRoot = ".",
    [switch]$RunChecks
)

$ErrorActionPreference = "Stop"

function Fail([string]$Message) {
    throw "[SP Cambo local Edit fix v2] $Message"
}

$root = (Resolve-Path $RepoRoot).Path
$src = Join-Path $root "gateway/src/tool-integrity.ts"
$test = Join-Path $root "gateway/tests/tool-integrity-edit-continuation.test.ts"

if (-not (Test-Path $src)) {
    Fail "Cannot find gateway/src/tool-integrity.ts under $root"
}

$raw = [System.IO.File]::ReadAllText($src)
$newline = if ($raw.Contains("`r`n")) { "`r`n" } else { "`n" }

# Normalize line endings before matching so Windows CRLF does not break exact blocks.
$text = $raw.Replace("`r`n", "`n")

if ($text.Contains("SAFE_EDIT_TRAILING_CONTINUATION_FIELDS")) {
    Write-Host "Fix already appears to be applied. No source changes made."
} else {
    $old = @'
type StreamToolState = {
  raw: string;
  hadObjectInput: boolean;
  sawDelta: boolean;
};
'@
    $new = @'
type StreamToolState = {
  raw: string;
  hadObjectInput: boolean;
  sawDelta: boolean;
  toolName: string | null;
};
'@

    if (-not $text.Contains($old)) {
        Write-Host ""
        Write-Host "Detected source markers:"
        Write-Host ("  StreamToolState: " + $text.Contains("type StreamToolState"))
        Write-Host ("  AnthropicToolStreamGuard: " + $text.Contains("class AnthropicToolStreamGuard"))
        Write-Host ("  parseObjectCompat: " + $text.Contains("function parseObjectCompat"))
        Write-Host ("  repairDuplicatedTrailingFields: " + $text.Contains("function repairDuplicatedTrailingFields"))
        Fail "Could not match StreamToolState after CRLF normalization. No file written."
    }

    $text = $text.Replace($old, $new)

    # Complete/non-streamed wrapper call sites.
    $oldCall = '      output.input = parseObjectCompat(raw);'
    $callCount = ([regex]::Matches($text, [regex]::Escape($oldCall))).Count
    if ($callCount -ne 2) {
        Fail "Expected 2 parseObjectCompat(raw) call sites, found $callCount. No file written."
    }

    $newCall = @'
      const toolName = typeof output.name === "string" ? output.name : null;
      output.input = parseObjectCompat(raw, toolName);
'@.TrimEnd()

    $text = $text.Replace($oldCall, $newCall)

    # Capture tool name in streamed state.
    $old = @'
        const raw = unparsedRaw(block.input);
        const hadObjectInput = record(block.input) && raw === null;

        if (block.input !== undefined && raw === null && !record(block.input)) {
'@
    $new = @'
        const raw = unparsedRaw(block.input);
        const hadObjectInput = record(block.input) && raw === null;
        const toolName = typeof block.name === "string" ? block.name : null;

        if (block.input !== undefined && raw === null && !record(block.input)) {
'@
    if (-not $text.Contains($old)) {
        Fail "Could not find streamed tool block initialization. No file written."
    }
    $text = $text.Replace($old, $new)

    $old = @'
        this.active.set(index, {
          raw: raw ?? "",
          hadObjectInput,
          sawDelta: false,
        });
'@
    $new = @'
        this.active.set(index, {
          raw: raw ?? "",
          hadObjectInput,
          sawDelta: false,
          toolName,
        });
'@
    if (-not $text.Contains($old)) {
        Fail "Could not find streamed tool state assignment. No file written."
    }
    $text = $text.Replace($old, $new)

    # Stream validation passes the tool name.
    $old = '      const repaired = repairDuplicatedTrailingFields(state.raw);'
    $new = '      const repaired = repairDuplicatedTrailingFields(state.raw, state.toolName);'
    if (-not $text.Contains($old)) {
        Fail "Could not find streamed repair call. No file written."
    }
    $text = $text.Replace($old, $new)

    # Complete parser passes tool name.
    $old = @'
function parseObjectCompat(raw: string): Record<string, unknown> {
  try {
    return parseObject(raw);
  } catch (originalError) {
    const repaired = repairDuplicatedTrailingFields(raw);
'@
    $new = @'
function parseObjectCompat(
  raw: string,
  toolName: string | null = null,
): Record<string, unknown> {
  try {
    return parseObject(raw);
  } catch (originalError) {
    const repaired = repairDuplicatedTrailingFields(raw, toolName);
'@
    if (-not $text.Contains($old)) {
        Fail "Could not find parseObjectCompat function. No file written."
    }
    $text = $text.Replace($old, $new)

    # Replace compatibility repair function.
    $start = $text.IndexOf("function repairDuplicatedTrailingFields(")
    $end = $text.IndexOf("function firstCompleteObjectEnd(", $start)

    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        Fail "Could not locate repair function boundaries. No file written."
    }

    $replacement = @'
const SAFE_EDIT_TRAILING_CONTINUATION_FIELDS = new Set([
  "file_path",
  "old_string",
  "new_string",
  "replace_all",
]);

function repairDuplicatedTrailingFields(
  raw: string,
  toolName: string | null = null,
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
    tail = JSON.parse(`{${tailText.slice(1)}`) as unknown;
  } catch {
    return null;
  }

  if (!record(head) || !record(tail) || Object.keys(tail).length === 0) {
    return null;
  }

  const normalizedToolName = toolName?.trim().toLowerCase() ?? "";
  const allowEditContinuation = normalizedToolName === "edit";
  const merged: Record<string, unknown> = { ...head };

  for (const [key, value] of Object.entries(tail)) {
    if (Object.prototype.hasOwnProperty.call(head, key)) {
      if (!sameJsonValue(head[key], value)) {
        return null;
      }

      continue;
    }

    if (!allowEditContinuation || !SAFE_EDIT_TRAILING_CONTINUATION_FIELDS.has(key)) {
      return null;
    }

    merged[key] = value;
  }

  return merged;
}

'@

    $text = $text.Substring(0, $start) + $replacement + $text.Substring($end)

    # Update compatibility comment if exact old wording is present.
    $text = $text.Replace(
@'
 * We repair only that deterministic duplicate-tail shape. Any trailing field
 * that is new or has a different value is rejected.
'@,
@'
 * We always repair deterministic identical duplicate tails. For the Claude Code
 * Edit tool only, we also repair a complete continuation tail made exclusively
 * from known Edit schema fields. Unknown fields, changed duplicate values, and
 * truncated/ambiguous JSON remain rejected.
'@
    )

    # Sanity checks before writing.
    $required = @(
        "toolName: string | null;",
        "SAFE_EDIT_TRAILING_CONTINUATION_FIELDS",
        "repairDuplicatedTrailingFields(state.raw, state.toolName)",
        "parseObjectCompat(raw, toolName)"
    )

    foreach ($marker in $required) {
        if (-not $text.Contains($marker)) {
            Fail "Post-patch validation failed; missing marker: $marker"
        }
    }

    # Backup under .git.
    $gitDir = Join-Path $root ".git"
    if (Test-Path $gitDir) {
        $backupDir = Join-Path $gitDir "sp-cambo-fix-backups"
        New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
        $backup = Join-Path $backupDir ("tool-integrity.ts.before-local-edit-fix-v2." + (Get-Date -Format "yyyyMMdd-HHmmss"))
        [System.IO.File]::WriteAllText(
            $backup,
            $raw,
            (New-Object System.Text.UTF8Encoding($false))
        )
        Write-Host "Backup: $backup"
    }

    if ($newline -eq "`r`n") {
        $text = $text.Replace("`n", "`r`n")
    }

    [System.IO.File]::WriteAllText(
        $src,
        $text,
        (New-Object System.Text.UTF8Encoding($false))
    )

    Write-Host "Updated: $src"
}

$testContent = @'
import { describe, expect, it } from "vitest";
import {
  AnthropicToolStreamGuard,
  InvalidToolInputError,
  normalizeCompleteToolInputs,
} from "../src/tool-integrity.js";

function sse(value: unknown): string {
  return `event: x\ndata: ${JSON.stringify(value)}\n\n`;
}

function reconstructToolJson(text: string): string {
  return text
    .split(/\r?\n/)
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

describe("Claude Edit continuation-tail compatibility", () => {
  it("repairs complete Edit fields appended after a prematurely closed object", () => {
    const malformed =
      `{"file_path":"index.html"},`
      + `"old_string":"<title>Rustic Bean Coffee</title>",`
      + `"new_string":"<title>SP Cambo Coffee</title>",`
      + `"replace_all":false}`;

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
    }) as any;

    expect(result.input).toEqual({
      file_path: "index.html",
      old_string: "<title>Rustic Bean Coffee</title>",
      new_string: "<title>SP Cambo Coffee</title>",
      replace_all: false,
    });
  });

  it("repairs the same Edit continuation shape in streamed partial_json", () => {
    const malformed =
      `{"file_path":"index.html"},`
      + `"old_string":"old",`
      + `"new_string":"new"}`;

    const guard = new AnthropicToolStreamGuard();
    const frames: string[] = [];

    frames.push(sse({
      type: "content_block_start",
      index: 0,
      content_block: {
        type: "tool_use",
        id: "tool_edit_stream_continuation",
        name: "Edit",
        input: {},
      },
    }));

    for (let offset = 0; offset < malformed.length; offset += 9) {
      frames.push(sse({
        type: "content_block_delta",
        index: 0,
        delta: {
          type: "input_json_delta",
          partial_json: malformed.slice(offset, offset + 9),
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
    const reconstructed = reconstructToolJson(rewritten);

    expect(JSON.parse(reconstructed)).toEqual({
      file_path: "index.html",
      old_string: "old",
      new_string: "new",
    });
  });

  it("still rejects an unknown new trailing field", () => {
    const malformed =
      `{"file_path":"index.html"},`
      + `"old_string":"old",`
      + `"new_string":"new",`
      + `"unexpected_field":true}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_edit_unknown_tail",
      name: "Edit",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    })).toThrow(InvalidToolInputError);
  });

  it("still rejects a conflicting repeated Edit field", () => {
    const malformed =
      `{"file_path":"index.html","old_string":"old"},`
      + `"old_string":"CHANGED",`
      + `"new_string":"new"}`;

    expect(() => normalizeCompleteToolInputs({
      type: "tool_use",
      id: "tool_edit_conflicting_tail",
      name: "Edit",
      input: {
        __unparsedToolInput: {
          raw: malformed,
          len: Buffer.byteLength(malformed),
        },
      },
    })).toThrow(InvalidToolInputError);
  });
});
'@

[System.IO.File]::WriteAllText(
    $test,
    $testContent,
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Host "Added/updated test: $test"
Write-Host ""
Write-Host "Local Edit continuation fix v2 applied."
Write-Host "No app.ts change. No DB change. No OmniRoute change."
Write-Host ""

if (Test-Path (Join-Path $root ".git")) {
    Push-Location $root
    try {
        git diff --check -- gateway/src/tool-integrity.ts gateway/tests/tool-integrity-edit-continuation.test.ts
        if ($LASTEXITCODE -ne 0) {
            Fail "git diff --check failed."
        }

        git status --short -- gateway/src/tool-integrity.ts gateway/tests/tool-integrity-edit-continuation.test.ts
    }
    finally {
        Pop-Location
    }
}

if ($RunChecks) {
    Push-Location (Join-Path $root "gateway")
    try {
        pnpm test
        if ($LASTEXITCODE -ne 0) { Fail "pnpm test failed." }

        pnpm typecheck
        if ($LASTEXITCODE -ne 0) { Fail "pnpm typecheck failed." }

        pnpm build
        if ($LASTEXITCODE -ne 0) { Fail "pnpm build failed." }
    }
    finally {
        Pop-Location
    }

    Write-Host ""
    Write-Host "ALL LOCAL CHECKS PASSED."
} else {
    Write-Host ""
    Write-Host "Next:"
    Write-Host "  cd gateway"
    Write-Host "  pnpm test"
    Write-Host "  pnpm typecheck"
    Write-Host "  pnpm build"
}
