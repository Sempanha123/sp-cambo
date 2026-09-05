<?php

declare(strict_types=1);

/**
 * SP Cambo - Gateway test expectation fix for current 1.95x output calibration.
 *
 * This patch changes TESTS ONLY.
 * It does NOT change gateway billing/runtime behavior.
 *
 * Current runtime policy:
 *   LOCAL_OUTPUT_CALIBRATION_BPS = 19_500
 *
 * Therefore a local generated-output estimate of 2 becomes:
 *   ceil(2 * 1.95) = 4
 *
 * Run this file from the SP Cambo repository root:
 *
 *   php FIX-GATEWAY-TEST-EXPECTATIONS-195.php
 *
 * Then:
 *
 *   cd gateway
 *   pnpm test
 */

$root = getcwd();

if ($root === false) {
    fwrite(STDERR, "ERROR: Could not determine current directory.\n");
    exit(1);
}

$protocolRelative = 'gateway/src/protocol.ts';
$testRelative = 'gateway/tests/app.test.ts';

$protocolPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $protocolRelative);
$testPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $testRelative);

foreach ([$protocolPath => $protocolRelative, $testPath => $testRelative] as $path => $relative) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: Missing {$relative}\n");
        fwrite(STDERR, "Run this script from the SP Cambo repository root.\n");
        exit(1);
    }
}

$protocol = file_get_contents($protocolPath);
$tests = file_get_contents($testPath);

if ($protocol === false || $tests === false) {
    fwrite(STDERR, "ERROR: Could not read gateway source files.\n");
    exit(1);
}

/*
 * Safety check:
 * This test patch is specifically for the current 1.95x runtime policy.
 */
if (!preg_match(
    '/export const LOCAL_OUTPUT_CALIBRATION_BPS\s*=\s*19_500\s*;/',
    $protocol
)) {
    fwrite(STDERR, "ERROR: gateway/src/protocol.ts is not using LOCAL_OUTPUT_CALIBRATION_BPS = 19_500.\n");
    fwrite(STDERR, "No test file was changed.\n");

    if (preg_match(
        '/export const LOCAL_OUTPUT_CALIBRATION_BPS\s*=\s*([0-9_]+)\s*;/',
        $protocol,
        $m
    )) {
        fwrite(STDERR, "Current calibration: {$m[1]}\n");
    }

    exit(1);
}

$before = $tests;

/*
 * Patch only the two exact stale assertions reported by Vitest.
 * Do not globally replace output_tokens: 3 because many other tests
 * intentionally use provider/mock values of 3.
 */
$oldJson = 'input_tokens: estimateTokens(JSON.stringify(body)), output_tokens: 3, cache_read_tokens: 0, cache_write_tokens: 0, reasoning_tokens: 0';
$newJson = 'input_tokens: estimateTokens(JSON.stringify(body)), output_tokens: 4, cache_read_tokens: 0, cache_write_tokens: 0, reasoning_tokens: 0';

$oldStream = 'input_tokens: estimateTokens(JSON.stringify(requestBody)), output_tokens: 3';
$newStream = 'input_tokens: estimateTokens(JSON.stringify(requestBody)), output_tokens: 4';

$replacements = [
    [$oldJson, $newJson, 'non-stream local JSON settlement expectation'],
    [$oldStream, $newStream, 'streamed Anthropic tool settlement expectation'],
];

foreach ($replacements as [$old, $new, $label]) {
    if (str_contains($tests, $new)) {
        echo "OK: {$label} is already updated.\n";
        continue;
    }

    $count = substr_count($tests, $old);

    if ($count !== 1) {
        fwrite(STDERR, "ERROR: {$label}: expected exactly 1 stale assertion, found {$count}.\n");
        fwrite(STDERR, "No file was changed.\n");
        exit(1);
    }

    $tests = str_replace($old, $new, $tests);
}

if ($tests === $before) {
    echo "\nOK: {$testRelative} already matches the 1.95x runtime calibration.\n";
    echo "Run:\n";
    echo "  cd gateway\n";
    echo "  pnpm test\n";
    exit(0);
}

$stamp = date('Ymd-His');
$backup = $testPath . '.bak-calibration-tests-' . $stamp;

if (!copy($testPath, $backup)) {
    fwrite(STDERR, "ERROR: Could not create test backup.\n");
    exit(1);
}

if (file_put_contents($testPath, $tests) === false) {
    @copy($backup, $testPath);
    fwrite(STDERR, "ERROR: Could not write {$testRelative}. Backup restored.\n");
    exit(1);
}

echo "\nUPDATED: {$testRelative}\n";
echo "BACKUP : " . basename($backup) . "\n";
echo "RUNTIME: unchanged (19_500 / 1.95x)\n";
echo "TEST #1: expected output_tokens 3 -> 4\n";
echo "TEST #2: expected output_tokens 3 -> 4\n\n";

echo "Now run:\n";
echo "  cd gateway\n";
echo "  pnpm test\n\n";

echo "Expected:\n";
echo "  Test Files  7 passed (7)\n";
echo "  Tests       56 passed (56)\n";
