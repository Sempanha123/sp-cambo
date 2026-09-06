<?php

declare(strict_types=1);

/**
 * SP Cambo - Provider malformed tool JSON compatibility hotfix
 *
 * Exact provider bug handled:
 *
 *   {"command":"...","description":"List files"},
 *   "description":"List files"}
 *
 * i.e. the provider closes the tool-input object, then appends one or more
 * top-level fields a second time.
 *
 * Safety rule:
 * - We repair ONLY when the first top-level JSON object is complete and valid.
 * - Every trailing field must already exist in the first object.
 * - Every trailing value must be JSON-equivalent to the value already present.
 * - Unknown, changed, truncated, or otherwise ambiguous JSON is still rejected.
 *
 * This means we do NOT guess missing source/code and do NOT weaken the existing
 * truncated-output protection.
 *
 * Also:
 * - Windows paths are preserved exactly. Escaped backslashes are not the bug.
 * - Stream failures are tagged upstream_invalid_tool_input for route failover.
 * - Runtime billing/token calibration is untouched.
 *
 * Run from SP Cambo repository root:
 *
 *   php FIX-PROVIDER-DUPLICATE-TOOL-JSON.php
 *
 * Then:
 *
 *   cd gateway
 *   pnpm test
 *   pnpm exec tsc --noEmit
 */

$root = getcwd();

if ($root === false) {
    fwrite(STDERR, "ERROR: Could not determine current directory.\n");
    exit(1);
}

function repoPath(string $root, string $relative): string
{
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function readStrict(string $root, string $relative): string
{
    $path = repoPath($root, $relative);

    if (!is_file($path)) {
        throw new RuntimeException("Missing {$relative}. Run this script from the SP Cambo repository root.");
    }

    $value = file_get_contents($path);

    if ($value === false) {
        throw new RuntimeException("Could not read {$relative}.");
    }

    return $value;
}

function replaceOnce(string $source, string $old, string $new, string $label): string
{
    if (str_contains($source, $new)) {
        return $source;
    }

    $count = substr_count($source, $old);

    if ($count !== 1) {
        throw new RuntimeException(
            "{$label}: expected exactly 1 source anchor, found {$count}. No files changed."
        );
    }

    return str_replace($old, $new, $source);
}

function stageExisting(array &$staged, string $root, string $relative, callable $patcher): void
{
    $before = readStrict($root, $relative);
    $after = $patcher($before);

    $staged[$relative] = [
        'path' => repoPath($root, $relative),
        'before' => $before,
        'after' => $after,
    ];
}

$staged = [];

try {
    stageExisting(
        $staged,
        $root,
        'gateway/src/tool-integrity.ts',
        function (string $s): string {
            $first = <<<'TS'
    if (raw !== null) {
      output.input = parseObject(raw);
    } else if (!record(output.input)) {
TS;

            $firstNew = <<<'TS'
    if (raw !== null) {
      output.input = parseObjectCompat(raw);
    } else if (!record(output.input)) {
TS;

            $s = replaceOnce(
                $s,
                $first,
                $firstNew,
                'completed tool wrapper compatibility'
            );

            $second = <<<'TS'
    if (raw !== null) {
      output.input = parseObject(raw);
    } else if (output.input !== undefined && !record(output.input)) {
TS;

            $secondNew = <<<'TS'
    if (raw !== null) {
      output.input = parseObjectCompat(raw);
    } else if (output.input !== undefined && !record(output.input)) {
TS;

            $s = replaceOnce(
                $s,
                $second,
                $secondNew,
                'streamed complete wrapper compatibility'
            );

            if (!str_contains($s, 'function parseObjectCompat(raw: string)')) {
                $anchor = <<<'TS'
function parseObject(raw: string): Record<string, unknown> {
TS;

                $helpers = <<<'TS'
/**
 * Parse a completed provider tool-input wrapper.
 *
 * Some compatible providers emit a valid object and then accidentally append
 * one or more of the same top-level fields after the object has already closed:
 *
 *   {"command":"...","description":"List files"},
 *   "description":"List files"}
 *
 * We repair only that deterministic duplicate-tail shape. Any trailing field
 * that is new or has a different value is rejected.
 */
function parseObjectCompat(raw: string): Record<string, unknown> {
  try {
    return parseObject(raw);
  } catch (originalError) {
    const repaired = repairDuplicatedTrailingFields(raw);

    if (repaired !== null) {
      return repaired;
    }

    throw originalError;
  }
}

function repairDuplicatedTrailingFields(raw: string): Record<string, unknown> | null {
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

  for (const [key, value] of Object.entries(tail)) {
    if (!Object.prototype.hasOwnProperty.call(head, key)) {
      return null;
    }

    if (!sameJsonValue(head[key], value)) {
      return null;
    }
  }

  return head;
}

function firstCompleteObjectEnd(raw: string): number | null {
  let index = 0;

  while (index < raw.length && /\s/u.test(raw[index]!)) {
    index++;
  }

  if (raw[index] !== "{") {
    return null;
  }

  let depth = 0;
  let inString = false;
  let escaped = false;

  for (; index < raw.length; index++) {
    const char = raw[index]!;

    if (inString) {
      if (escaped) {
        escaped = false;
        continue;
      }

      if (char === "\\") {
        escaped = true;
        continue;
      }

      if (char === '"') {
        inString = false;
      }

      continue;
    }

    if (char === '"') {
      inString = true;
      continue;
    }

    if (char === "{") {
      depth++;
      continue;
    }

    if (char === "}") {
      depth--;

      if (depth === 0) {
        return index + 1;
      }

      if (depth < 0) {
        return null;
      }
    }
  }

  return null;
}

function sameJsonValue(left: unknown, right: unknown): boolean {
  if (left === right) return true;

  if (Array.isArray(left) || Array.isArray(right)) {
    if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) {
      return false;
    }

    for (let index = 0; index < left.length; index++) {
      if (!sameJsonValue(left[index], right[index])) {
        return false;
      }
    }

    return true;
  }

  if (record(left) || record(right)) {
    if (!record(left) || !record(right)) {
      return false;
    }

    const leftKeys = Object.keys(left).sort();
    const rightKeys = Object.keys(right).sort();

    if (leftKeys.length !== rightKeys.length) {
      return false;
    }

    for (let index = 0; index < leftKeys.length; index++) {
      const leftKey = leftKeys[index]!;
      const rightKey = rightKeys[index]!;

      if (leftKey !== rightKey) {
        return false;
      }

      if (!sameJsonValue(left[leftKey], right[rightKey])) {
        return false;
      }
    }

    return true;
  }

  return false;
}

TS;

                $s = replaceOnce(
                    $s,
                    $anchor,
                    $helpers . $anchor,
                    'tool JSON compatibility helper insertion'
                );
            }

            return $s;
        }
    );

    stageExisting(
        $staged,
        $root,
        'gateway/src/app.ts',
        function (string $s): string {
            if (!str_contains($s, 'error instanceof InvalidToolInputError')) {
                $old = <<<'TS'
    } catch {
      void reader.cancel(signal.reason).catch(() => undefined);
      const reason = abortReason(signal) ?? "upstream_disconnect";
TS;

                $new = <<<'TS'
    } catch (error) {
      void reader.cancel(signal.reason).catch(() => undefined);
      const reason = error instanceof InvalidToolInputError
        ? "upstream_invalid_tool_input"
        : abortReason(signal) ?? "upstream_disconnect";
TS;

                $s = replaceOnce(
                    $s,
                    $old,
                    $new,
                    'stream invalid-tool failure reason'
                );
            }

            return $s;
        }
    );

    stageExisting(
        $staged,
        $root,
        'gateway/tests/tool-integrity.test.ts',
        function (string $s): string {
            if (str_contains($s, 'repairs the exact duplicated Bash description shape')) {
                return $s;
            }

            $anchor = <<<'TS'
  it("rejects a truncated >30 KB raw Write payload", () => {
TS;

            $tests = <<<'TS'
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

TS;

            return replaceOnce(
                $s,
                $anchor,
                $tests . $anchor,
                'provider duplicate-tail regression tests'
            );
        }
    );

    $changed = array_filter(
        $staged,
        fn(array $item): bool => $item['before'] !== $item['after']
    );

    if ($changed === []) {
        echo "OK: Provider duplicate-tool-JSON compatibility fix is already applied.\n";
        echo "Run:\n";
        echo "  cd gateway\n";
        echo "  pnpm test\n";
        echo "  pnpm exec tsc --noEmit\n";
        exit(0);
    }

    $stamp = date('Ymd-His');
    $backups = [];

    foreach ($changed as $relative => $item) {
        $backup = $item['path'] . '.bak-provider-tool-json-' . $stamp;

        if (!copy($item['path'], $backup)) {
            throw new RuntimeException("Could not back up {$relative}.");
        }

        $backups[$relative] = $backup;
    }

    $written = [];

    try {
        foreach ($changed as $relative => $item) {
            if (file_put_contents($item['path'], $item['after']) === false) {
                throw new RuntimeException("Could not write {$relative}.");
            }

            $written[] = $relative;
        }
    } catch (Throwable $writeError) {
        foreach ($written as $relative) {
            if (isset($backups[$relative])) {
                @copy($backups[$relative], $changed[$relative]['path']);
            }
        }

        throw $writeError;
    }

    echo "SUCCESS: Provider malformed tool JSON compatibility fix applied.\n\n";

    foreach ($changed as $relative => $item) {
        echo "UPDATED: {$relative}\n";
    }

    echo "\nWhat is now repaired safely:\n";
    echo "  valid object + duplicated trailing field(s) with identical values\n\n";

    echo "What is still rejected:\n";
    echo "  truncated JSON\n";
    echo "  unknown appended fields\n";
    echo "  duplicated fields whose values changed\n";
    echo "  arbitrary malformed JSON\n\n";

    echo "Windows paths are preserved; no slash conversion is performed.\n";
    echo "Billing/token calibration was NOT changed.\n\n";

    echo "Now run:\n";
    echo "  cd gateway\n";
    echo "  pnpm test\n";
    echo "  pnpm exec tsc --noEmit\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
