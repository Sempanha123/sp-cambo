<?php
declare(strict_types=1);

/**
 * SP Cambo R45 — safer local-only token / credit metering.
 *
 * IMPORTANT:
 * - Never reads OmniRoute/provider usage counters.
 * - Keeps input + local cache logic unchanged.
 * - Improves customer-visible/generated output estimation only.
 * - Fixes an accidental recursive boundedLocalOutput() bug in current main.
 * - Adds regression tests.
 *
 * Run from SP Cambo project root:
 *   php ./APPLY-LOCAL-METERING-R45.php
 */

$root = __DIR__;

$protocolPath = $root . '/gateway/src/protocol.ts';
$appTestPath = $root . '/gateway/tests/app.test.ts';
$billingPath = $root . '/backend/app/Services/InferenceBillingService.php';
$gatewayMeterTestPath = $root . '/gateway/tests/local-metering.test.ts';
$backendMeterTestPath = $root . '/backend/tests/Feature/Feature/LocalMeteringTest.php';

foreach ([$protocolPath, $appTestPath, $billingPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "ERROR: Missing required file: {$required}\n");
        fwrite(STDERR, "Place this installer in the SP Cambo project root.\n");
        exit(1);
    }
}

$protocol = (string) file_get_contents($protocolPath);
$appTest = (string) file_get_contents($appTestPath);
$billing = (string) file_get_contents($billingPath);

$originalProtocol = $protocol;
$originalAppTest = $appTest;
$originalBilling = $billing;

$changes = [];

/* --------------------------------------------------------------------------
 * 1) Replace blanket 1.50x output calibration with 1.20x safety.
 *
 * R45 first improves the generated-text estimator itself. Keeping the old
 * blanket 1.50x on top of a stronger estimator would be unnecessarily harsh.
 * ----------------------------------------------------------------------- */
if (str_contains($protocol, 'export const LOCAL_OUTPUT_CALIBRATION_BPS = 15_000;')) {
    $protocol = str_replace(
        'export const LOCAL_OUTPUT_CALIBRATION_BPS = 15_000;',
        'export const LOCAL_OUTPUT_CALIBRATION_BPS = 12_000;',
        $protocol
    );
    $protocol = str_replace(
        '15_000 bps = 1.50x locally measured generated output.',
        '12_000 bps = 1.20x safety on locally measured generated output.',
        $protocol
    );
    $changes[] = 'gateway: output safety 1.50x -> 1.20x after content-aware estimation';
} elseif (str_contains($protocol, 'export const LOCAL_OUTPUT_CALIBRATION_BPS = 12_000;')) {
    $changes[] = 'gateway: output safety already 1.20x';
} else {
    fwrite(STDERR, "ERROR: Unknown LOCAL_OUTPUT_CALIBRATION_BPS block in protocol.ts. No files changed.\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 2) Add output-only text estimator.
 *
 * Input/cache keeps the existing estimator unchanged.
 * Output adds:
 * - UTF-8 byte floor
 * - code/HTML/JSON-like floor
 * - newline/indentation cost
 * - non-ASCII floor
 *
 * No provider usage is read.
 * ----------------------------------------------------------------------- */
if (!str_contains($protocol, 'export function estimateOutputTextTokens(')) {
    $anchor = <<<'TS'
function estimateStructuredTokens(value: unknown, parentKey = "", depth = 0): number {
TS;

    if (!str_contains($protocol, $anchor)) {
        fwrite(STDERR, "ERROR: Could not find estimateStructuredTokens anchor. No files changed.\n");
        exit(1);
    }

    $helper = <<<'TS'
/**
 * Output-only SP Cambo local meter.
 *
 * Input/cache estimation intentionally remains unchanged. Generated responses
 * need a stronger floor because code, HTML, JSON, indentation and non-ASCII
 * scripts can tokenize more densely than ordinary prose.
 *
 * This function uses ONLY text that SP Cambo actually sends to the customer.
 * It never reads OmniRoute/provider token counters, usage headers or hidden
 * provider reasoning.
 */
export function estimateOutputTextTokens(text: string): number {
  const normalized = text.trim();
  if (normalized === "") return 0;

  const base = estimateTextTokens(normalized);
  const bytes = Buffer.byteLength(normalized, "utf8");

  let punctuation = 0;
  let nonAscii = 0;
  let newlines = 0;
  let indentation = 0;

  for (const line of normalized.split(/\r?\n/)) {
    const indent = line.match(/^[ \t]+/)?.[0] ?? "";
    indentation += indent.replace(/\t/g, "    ").length;
  }

  for (const char of normalized) {
    const code = char.codePointAt(0) ?? 0;
    if (char === "\n") newlines++;
    if (code > 0x7f) {
      nonAscii++;
    } else if (!/\s/.test(char) && !/[A-Za-z0-9_]/.test(char)) {
      punctuation++;
    }
  }

  const charCount = Math.max(1, [...normalized].length);
  const punctuationDensity = punctuation / charCount;
  const codeLike = /```|<\/?[A-Za-z][^>]*>|(?:^|\n)\s*(?:const|let|var|function|class|def|import|export|SELECT|CREATE|INSERT|UPDATE|DELETE)\b|[{}()[\];=<>]{3,}/m.test(normalized)
    || punctuationDensity >= 0.10;

  // Prose floor: ~4 UTF-8 bytes/token before the output safety margin.
  // Code/markup floor: denser ~3 bytes/token because punctuation, indentation
  // and short identifiers split more often in common tokenizers.
  const byteFloor = Math.ceil(bytes / (codeLike ? 3.0 : 4.0));

  // Preserve the existing lexical estimate, but account for formatting that the
  // old estimator mostly treated as free.
  const structureFloor = base
    + Math.ceil(newlines / 3)
    + Math.ceil(indentation / 8);

  // Khmer/CJK/emoji and other non-ASCII output should never collapse to the
  // ASCII chars-per-token assumption.
  const nonAsciiFloor = nonAscii > 0 ? Math.ceil(nonAscii * 1.05) : 0;

  return Math.max(1, base, byteFloor, structureFloor, nonAsciiFloor);
}

TS;

    $protocol = str_replace($anchor, $helper . $anchor, $protocol);
    $changes[] = 'gateway: added content-aware output-only local estimator';
} else {
    $changes[] = 'gateway: content-aware output estimator already present';
}

/* --------------------------------------------------------------------------
 * 3) Unknown response fallback must not be halved.
 * ----------------------------------------------------------------------- */
$oldFallback = <<<'TS'
  const fallbackTokens = rawFallback === "" ? 0 : Math.max(1, estimateTextTokens(rawFallback));
  const outputTokens = generatedTokens > 0 ? generatedTokens : Math.ceil(fallbackTokens / 2);
TS;
$newFallback = <<<'TS'
  const fallbackTokens = rawFallback === "" ? 0 : Math.max(1, estimateOutputTextTokens(rawFallback));
  const outputTokens = generatedTokens > 0 ? generatedTokens : fallbackTokens;
TS;

if (str_contains($protocol, $oldFallback)) {
    $protocol = str_replace($oldFallback, $newFallback, $protocol);
    $changes[] = 'gateway: removed 50% under-metering fallback for unfamiliar response envelopes';
} elseif (str_contains($protocol, $newFallback)) {
    $changes[] = 'gateway: safe unfamiliar-envelope fallback already present';
} else {
    fwrite(STDERR, "ERROR: Could not find output fallback block. No files changed.\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 4) Generated text uses the output-only estimator.
 * ----------------------------------------------------------------------- */
$oldGenerated = 'if (generatedKeys.has(parentKey)) tokens += estimateTextTokens(node);';
$newGenerated = 'if (generatedKeys.has(parentKey)) tokens += estimateOutputTextTokens(node);';

if (str_contains($protocol, $oldGenerated)) {
    $protocol = str_replace($oldGenerated, $newGenerated, $protocol);
    $changes[] = 'gateway: generated text now uses output-only estimator';
} elseif (str_contains($protocol, $newGenerated)) {
    $changes[] = 'gateway: generated text estimator already updated';
} else {
    fwrite(STDERR, "ERROR: Could not find generatedPayloadTokens text-count line. No files changed.\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 5) Current main has an accidental recursive boundedLocalOutput() line:
 *      $output = $this->boundedLocalOutput($snapshot, $usage);
 *    This must read the locally supplied gateway output instead.
 * ----------------------------------------------------------------------- */
$badRecursive = '$output = $this->boundedLocalOutput($snapshot, $usage);';
$fixedOutput = '$output = max(0, (int) ($usage[\'output_tokens\'] ?? 0));';

if (str_contains($billing, $badRecursive)) {
    $billing = str_replace($badRecursive, $fixedOutput, $billing);
    $changes[] = 'backend: fixed boundedLocalOutput() recursive-call bug';
} elseif (str_contains($billing, $fixedOutput)) {
    $changes[] = 'backend: boundedLocalOutput() recursion already fixed';
} else {
    fwrite(STDERR, "ERROR: Could not verify boundedLocalOutput() implementation. No files changed.\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 6) Update existing calibration regression expectation.
 * ----------------------------------------------------------------------- */
if (str_contains($appTest, 'expect(localOutputBilledTokens(622)).toBe(933);')) {
    $appTest = str_replace(
        'expect(localOutputBilledTokens(622)).toBe(933);',
        'expect(localOutputBilledTokens(622)).toBe(747);',
        $appTest
    );
    $changes[] = 'gateway test: updated 622-token safety expectation to 747';
} elseif (str_contains($appTest, 'expect(localOutputBilledTokens(622)).toBe(747);')) {
    $changes[] = 'gateway test: output-safety expectation already updated';
} else {
    fwrite(STDERR, "ERROR: Could not find existing localOutputBilledTokens(622) test. No files changed.\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 7) New gateway regression tests.
 * ----------------------------------------------------------------------- */
$gatewayMeterTest = <<<'TS'
import { describe, expect, it } from "vitest";
import {
  estimateOutputTextTokens,
  localOutputBilledTokens,
  spLocalUsage,
} from "../src/protocol.js";

describe("R45 local-only output metering", () => {
  it("meters code and markup with a denser local floor", () => {
    const html = `
<!doctype html>
<html>
  <head>
    <style>
      body { margin: 0; display: grid; place-items: center; }
      .card { padding: 24px; border: 1px solid #ddd; }
    </style>
  </head>
  <body>
    <main class="card">
      <h1>Hello</h1>
      <button onclick="console.log('clicked')">Start</button>
    </main>
  </body>
</html>`.repeat(8);

    const bytes = Buffer.byteLength(html.trim(), "utf8");
    const local = estimateOutputTextTokens(html);

    // R45 code/markup floor is at least one local token per 3 UTF-8 bytes
    // before the separate output safety calibration.
    expect(local).toBeGreaterThanOrEqual(Math.ceil(bytes / 3));
    expect(localOutputBilledTokens(local)).toBeGreaterThanOrEqual(local);
  });

  it("does not halve the fallback when an adapter uses an unfamiliar envelope", () => {
    const raw = JSON.stringify({
      custom_result: {
        rendered_answer: "A local response body that SP Cambo can see. ".repeat(40),
      },
    });

    const usage = spLocalUsage(0, 0, { unrecognized_transport_shape: true }, raw);
    const expected = localOutputBilledTokens(estimateOutputTextTokens(raw));

    expect(usage.output_tokens).toBe(expected);
  });

  it("keeps all provider counters irrelevant to customer billing", () => {
    const visible = { content: [{ type: "text", text: "hello customer" }] };

    const cheapProviderClaim = spLocalUsage(
      7,
      0,
      { ...visible, usage: { input_tokens: 1, output_tokens: 1 } },
    );
    const hugeProviderClaim = spLocalUsage(
      7,
      0,
      { ...visible, usage: { input_tokens: 999999, output_tokens: 999999 } },
    );

    expect(hugeProviderClaim).toEqual(cheapProviderClaim);
  });
});
TS;

/* --------------------------------------------------------------------------
 * 8) New backend regression test for the recursive bug + requested-max cap.
 * ----------------------------------------------------------------------- */
$backendMeterTest = <<<'PHP'
<?php

namespace Tests\Feature\Feature;

use App\Services\InferenceBillingService;
use App\Services\ModelRoutePoolService;
use App\Services\ReservationService;
use Tests\TestCase;

class LocalMeteringTest extends TestCase
{
    private function service(): InferenceBillingService
    {
        return new InferenceBillingService(
            $this->createMock(ReservationService::class),
            $this->createMock(ModelRoutePoolService::class),
        );
    }

    public function test_local_output_is_read_from_gateway_usage_and_bounded_by_requested_max(): void
    {
        $snapshot = [
            'billing_mode' => 'TOKEN_QUOTA',
            'requested_max_output_tokens' => 100,
            'local_cache_read_billing_bps' => 2500,
        ];

        $usage = [
            'input_tokens' => 10,
            'output_tokens' => 150,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
        ];

        // 10 local input + output capped to the customer's requested 100.
        // No provider/OmniRoute usage exists in this test.
        $this->assertSame(110, $this->service()->actualUnits($snapshot, $usage));
    }

    public function test_local_output_below_requested_max_is_not_changed_by_backend(): void
    {
        $snapshot = [
            'billing_mode' => 'TOKEN_QUOTA',
            'requested_max_output_tokens' => 100,
            'local_cache_read_billing_bps' => 2500,
        ];

        $usage = [
            'input_tokens' => 10,
            'output_tokens' => 70,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
        ];

        $this->assertSame(80, $this->service()->actualUnits($snapshot, $usage));
    }
}
PHP;

/* --------------------------------------------------------------------------
 * All anchors validated. Back up existing files, then write.
 * ----------------------------------------------------------------------- */
$timestamp = date('Ymd-His');
$toWrite = [
    $protocolPath => $protocol,
    $appTestPath => $appTest,
    $billingPath => $billing,
    $gatewayMeterTestPath => $gatewayMeterTest,
    $backendMeterTestPath => $backendMeterTest,
];

foreach ([$protocolPath, $appTestPath, $billingPath] as $path) {
    $backup = $path . '.bak-r45-' . $timestamp;
    if (!copy($path, $backup)) {
        fwrite(STDERR, "ERROR: Could not create backup {$backup}. No writes performed.\n");
        exit(1);
    }
}

foreach ($toWrite as $path => $content) {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "ERROR: Could not create directory {$dir}\n");
        exit(1);
    }
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, "ERROR: Could not write {$path}\n");
        exit(1);
    }
}

echo "SP Cambo R45 local-only metering applied.\n\n";
foreach ($changes as $change) {
    echo "  + {$change}\n";
}
echo "\n✅ Input metering unchanged.\n";
echo "✅ SP local cache logic unchanged.\n";
echo "✅ Generated output metering is content-aware.\n";
echo "✅ Unknown response fallback is no longer halved.\n";
echo "✅ OmniRoute/provider usage remains ignored for customer billing.\n";
echo "✅ Backend recursive output-bound bug fixed.\n";
echo "✅ Regression tests added.\n\n";
echo "Run tests:\n";
echo "  cd gateway\n";
echo "  pnpm typecheck\n";
echo "  pnpm test\n";
echo "  pnpm build\n\n";
echo "  cd ../backend\n";
echo "  php artisan test --filter=LocalMeteringTest\n";
echo "  php artisan test --filter=GatewayBilling\n";
echo "\n";
