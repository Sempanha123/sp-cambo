<?php

declare(strict_types=1);

/**
 * SP Cambo - Fix streamed Claude tool-casing test fixture index
 *
 * Cause:
 * The old test emits content_block_start without Anthropic's required `index`.
 * The new tool-integrity guard correctly rejects that malformed event, causing
 * HTTP 503.
 *
 * This patch updates TESTS ONLY.
 * Runtime gateway behavior and LOCAL_OUTPUT_CALIBRATION_BPS are unchanged.
 *
 * Run from repository root:
 *
 *   php FIX-STREAMED-TOOL-CASING-TEST-INDEX.php
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

$testName = 'it("restores exact Claude Code tool casing in streamed Anthropic responses"';
$start = strpos($before, $testName);

if ($start === false) {
    fwrite(STDERR, "ERROR: Could not find the target streamed tool-casing test.\n");
    exit(1);
}

$next = strpos($before, "\nit(", $start + strlen($testName));
if ($next === false) {
    $next = strlen($before);
}

$block = substr($before, $start, $next - $start);

if (str_contains($block, 'type: "content_block_start", index: 0,')) {
    echo "OK: Target test already has content_block_start index: 0.\n";
    echo "Run:\n";
    echo "  cd gateway\n";
    echo "  pnpm test\n";
    echo "  pnpm exec tsc --noEmit\n";
    exit(0);
}

$old = '{ type: "content_block_start", content_block: { type: "tool_use", id: "tool_1", name: "edit", input: {} } }';
$new = '{ type: "content_block_start", index: 0, content_block: { type: "tool_use", id: "tool_1", name: "edit", input: {} } }';

$count = substr_count($block, $old);

if ($count !== 1) {
    fwrite(STDERR, "ERROR: Expected exactly one target content_block_start fixture, found {$count}.\n");
    fwrite(STDERR, "No file was changed.\n");
    exit(1);
}

$patchedBlock = str_replace($old, $new, $block);
$after = substr($before, 0, $start) . $patchedBlock . substr($before, $next);

$stamp = date('Ymd-His');
$backup = $path . '.bak-stream-tool-index-' . $stamp;

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

echo "Fixed target test fixture:\n";
echo "  content_block_start now includes index: 0\n\n";

echo "Runtime gateway code was NOT changed.\n";
echo "LOCAL_OUTPUT_CALIBRATION_BPS was NOT changed.\n\n";

echo "Now run:\n";
echo "  cd gateway\n";
echo "  pnpm test\n";
echo "  pnpm exec tsc --noEmit\n";
