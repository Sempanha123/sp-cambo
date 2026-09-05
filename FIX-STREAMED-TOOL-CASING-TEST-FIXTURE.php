<?php

declare(strict_types=1);

/**
 * SP Cambo - Fix legacy streamed Claude tool-casing test fixture
 *
 * Why:
 * The new tool-integrity guard correctly rejects a tool_use stream that ends
 * without content_block_stop/message_stop. The old casing-only test fixture was
 * incomplete, so it now gets HTTP 503.
 *
 * This patch updates TESTS ONLY. Runtime gateway behavior is not weakened.
 *
 * Run from repository root:
 *
 *   php FIX-STREAMED-TOOL-CASING-TEST-FIXTURE.php
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

$relative = 'gateway/tests/app.test.ts';
$path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

if (!is_file($path)) {
    fwrite(STDERR, "ERROR: Missing {$relative}\n");
    fwrite(STDERR, "Run this script from the SP Cambo repository root.\n");
    exit(1);
}

$before = file_get_contents($path);

if ($before === false) {
    fwrite(STDERR, "ERROR: Could not read {$relative}\n");
    exit(1);
}

$after = $before;

// If already fixed, do nothing.
if (
    str_contains($after, 'type: "content_block_stop", index: 0')
    && str_contains($after, 'type: "message_stop"')
) {
    echo "OK: streamed tool-casing fixture already contains terminal Anthropic events.\n";
    echo "Run:\n";
    echo "  cd gateway\n";
    echo "  pnpm test\n";
    echo "  pnpm exec tsc --noEmit\n";
    exit(0);
}

/*
 * Target only the specific test named:
 *   restores exact Claude Code tool casing in streamed Anthropic responses
 *
 * The historical fixture has:
 * - content_block_start with tool_use
 * - message_delta usage
 * ...then EOF
 *
 * That is not a complete Anthropic tool stream.
 */
$testName = 'it("restores exact Claude Code tool casing in streamed Anthropic responses"';

$start = strpos($after, $testName);

if ($start === false) {
    fwrite(STDERR, "ERROR: Could not find the streamed tool-casing test.\n");
    fwrite(STDERR, "No file was changed.\n");
    exit(1);
}

$nextTest = strpos($after, "\nit(", $start + strlen($testName));

if ($nextTest === false) {
    $nextTest = strlen($after);
}

$block = substr($after, $start, $nextTest - $start);

$needle = '    `event: message_delta\\ndata: ${JSON.stringify({ type: "message_delta", usage: { input_tokens: 4, output_tokens: 6 } })}\\n\\n`,';

if (!str_contains($block, $needle)) {
    fwrite(STDERR, "ERROR: The target test exists, but its stream fixture differs from the expected structure.\n");
    fwrite(STDERR, "No file was changed.\n");
    exit(1);
}

$replacement = $needle . "\n" .
'    `event: content_block_stop\\ndata: ${JSON.stringify({ type: "content_block_stop", index: 0 })}\\n\\n`,' . "\n" .
'    `event: message_stop\\ndata: ${JSON.stringify({ type: "message_stop" })}\\n\\n`,';

$patchedBlock = str_replace($needle, $replacement, $block, $count);

if ($count !== 1) {
    fwrite(STDERR, "ERROR: Expected to patch exactly one message_delta fixture, patched {$count}.\n");
    fwrite(STDERR, "No file was changed.\n");
    exit(1);
}

$after = substr($after, 0, $start) . $patchedBlock . substr($after, $nextTest);

$stamp = date('Ymd-His');
$backup = $path . '.bak-stream-tool-fixture-' . $stamp;

if (!copy($path, $backup)) {
    fwrite(STDERR, "ERROR: Could not create backup.\n");
    exit(1);
}

if (file_put_contents($path, $after) === false) {
    @copy($backup, $path);
    fwrite(STDERR, "ERROR: Could not write {$relative}; backup restored.\n");
    exit(1);
}

echo "UPDATED: {$relative}\n";
echo "BACKUP : " . basename($backup) . "\n\n";

echo "Added required Anthropic stream terminal events to the old casing test:\n";
echo "  content_block_stop\n";
echo "  message_stop\n\n";

echo "Runtime gateway code was NOT changed.\n";
echo "LOCAL_OUTPUT_CALIBRATION_BPS was NOT changed.\n\n";

echo "Now run:\n";
echo "  cd gateway\n";
echo "  pnpm test\n";
echo "  pnpm exec tsc --noEmit\n";
