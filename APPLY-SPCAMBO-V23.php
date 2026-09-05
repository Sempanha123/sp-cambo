<?php

declare(strict_types=1);

/**
 * SP Cambo V23 — margin-safe local metering + hard token quota + reliable key checker.
 *
 * Run from the SP Cambo repository root, e.g. /var/www/sp-cambo:
 *   php ./APPLY-SPCAMBO-V23.php
 *
 * This patch is deliberately conservative:
 * - SP-local input calibration: 1.05x
 * - SP-local output calibration: 1.45x -> 1.50x
 * - TOKEN_QUOTA cache-read usage counts 1:1 inside the purchased package cap
 * - public key checker throttle: 10/min -> 60/min
 * - public checker no longer advertises cache as bonus quota
 * - adds regression tests
 *
 * Provider/OmniRoute usage remains irrelevant to customer billing.
 */

$root = realpath(getcwd());
if ($root === false) {
    fwrite(STDERR, "Could not resolve current directory.\n");
    exit(1);
}

$requiredDirs = ['gateway/src', 'backend/app/Services', 'frontend/app/pages/public'];
foreach ($requiredDirs as $dir) {
    if (!is_dir($root . DIRECTORY_SEPARATOR . $dir)) {
        fwrite(STDERR, "Run this script from the SP Cambo repository root. Missing: {$dir}\n");
        exit(1);
    }
}

$stamp = date('Ymd-His');
$changed = [];
$created = [];

function readFileOrFail(string $path): string
{
    $value = @file_get_contents($path);
    if ($value === false) {
        throw new RuntimeException("Could not read {$path}");
    }
    return $value;
}

function replaceOne(string $content, string $old, string $new, string $label): string
{
    if (str_contains($content, $new)) {
        return $content;
    }
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException("{$label}: expected exactly 1 source anchor, found {$count}. Source changed; aborting safely.");
    }
    return str_replace($old, $new, $content);
}

function replaceExpected(string $content, string $old, string $new, int $expected, string $label): string
{
    if (substr_count($content, $new) >= $expected) {
        return $content;
    }
    $count = substr_count($content, $old);
    if ($count !== $expected) {
        throw new RuntimeException("{$label}: expected {$expected} source anchors, found {$count}. Source changed; aborting safely.");
    }
    return str_replace($old, $new, $content);
}

function stagePatch(string $root, string $relative, callable $patcher, array &$staged): void
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $before = readFileOrFail($path);
    $after = $patcher($before);
    $staged[$relative] = ['path' => $path, 'before' => $before, 'after' => $after];
}

$staged = [];

try {
    stagePatch($root, 'gateway/src/protocol.ts', function (string $s): string {
        $s = replaceOne(
            $s,
            'export const LOCAL_OUTPUT_CALIBRATION_BPS = 14_500;',
            "export const LOCAL_INPUT_CALIBRATION_BPS = 10_500;\nexport const LOCAL_OUTPUT_CALIBRATION_BPS = 15_000;",
            'protocol calibration constants'
        );

        if (!str_contains($s, 'export function localInputBilledTokens(')) {
            $anchor = "/**\n * SP Cambo output-only safety calibration.";
            $helper = <<<'TS'
/**
 * Small SP-local input safety calibration used only for customer metering.
 * Round-to-nearest avoids turning tiny one-token estimates into two tokens,
 * while normal requests receive the intended 1.05x margin buffer.
 */
export function localInputBilledTokens(tokens: number): number {
  const safe = Math.max(0, Math.floor(tokens));
  if (safe === 0) return 0;
  return Math.max(safe, Math.round((safe * LOCAL_INPUT_CALIBRATION_BPS) / 10_000));
}

TS;
            $s = replaceOne($s, $anchor, $helper . $anchor, 'protocol input calibration helper');
        }

        $s = str_replace(
            " * 13_000 bps = 1.30x local generated-output estimate.",
            " * 15_000 bps = 1.50x local generated-output estimate (up from 1.45x).",
            $s
        );

        $s = replaceExpected(
            $s,
            '    input_tokens: Math.max(0, inputTokens),',
            '    input_tokens: localInputBilledTokens(inputTokens),',
            2,
            'protocol local input usage'
        );
        $s = replaceExpected(
            $s,
            '    cache_read_tokens: Math.max(0, cacheReadTokens),',
            '    cache_read_tokens: localInputBilledTokens(cacheReadTokens),',
            2,
            'protocol local cache-read usage'
        );

        return $s;
    }, $staged);

    stagePatch($root, 'gateway/src/app.ts', function (string $s): string {
        $s = replaceOne(
            $s,
            'import { localizeSseUsage, prepare, restorePublicModel, restorePublicModelInSse, spLocalOutputTokensFromSse, spLocalUsage, spLocalUsageFromOutputTokens, upstreamBody, withLocalUsage } from "./protocol.js";',
            'import { localInputBilledTokens, localizeSseUsage, prepare, restorePublicModel, restorePublicModelInSse, spLocalOutputTokensFromSse, spLocalUsage, spLocalUsageFromOutputTokens, upstreamBody, withLocalUsage } from "./protocol.js";',
            'gateway protocol import'
        );

        $old = <<<'TS'
    const localInput = promptCache.measure(
      inspection.key_id,
      path,
      prepared.publicModel,
      prepared.promptSegments,
      prepared.estimatedInput,
    );

TS;
        $new = $old . <<<'TS'
    // Preflight must reserve the same locally calibrated input units that final
    // settlement reports, otherwise a safety calibration could be clipped by
    // the reservation ceiling. Count-token utility responses remain raw estimates.
    const billedLocalInputTokens = localInputBilledTokens(localInput.input_tokens);
    const billedLocalCacheReadTokens = localInputBilledTokens(localInput.cache_read_tokens);

TS;
        $s = replaceOne($s, $old, $new, 'gateway preflight input calibration');

        $s = replaceOne(
            $s,
            '        customer_key: key, public_model: prepared.publicModel, estimated_input_tokens: localInput.input_tokens,\n        estimated_cache_read_tokens: localInput.cache_read_tokens,',
            '        customer_key: key, public_model: prepared.publicModel, estimated_input_tokens: billedLocalInputTokens,\n        estimated_cache_read_tokens: billedLocalCacheReadTokens,',
            'gateway preflight calibrated fields'
        );

        return $s;
    }, $staged);

    stagePatch($root, 'gateway/tests/app.test.ts', function (string $s): string {
        $s = replaceOne(
            $s,
            'import { estimateTokens } from "../src/protocol.js";',
            'import { estimateTokens, localInputBilledTokens } from "../src/protocol.js";',
            'gateway app test protocol import'
        );
        $pairs = [
            [
                'input_tokens: estimateTokens(JSON.stringify(body)), output_tokens: 3',
                'input_tokens: localInputBilledTokens(estimateTokens(JSON.stringify(body))), output_tokens: 3',
                'gateway JSON settlement expectation',
            ],
            [
                'input_tokens: estimateTokens(JSON.stringify(requestBody)), output_tokens: 3',
                'input_tokens: localInputBilledTokens(estimateTokens(JSON.stringify(requestBody))), output_tokens: 3',
                'gateway tool stream settlement expectation',
            ],
            [
                'input_tokens: estimateTokens(JSON.stringify({ ...body, stream: true })), output_tokens: 0',
                'input_tokens: localInputBilledTokens(estimateTokens(JSON.stringify({ ...body, stream: true }))), output_tokens: 0',
                'gateway empty stream settlement expectation',
            ],
            [
                'expect(control.lastPreflight?.estimated_input_tokens).toBe(estimateTokens(raw));',
                'expect(control.lastPreflight?.estimated_input_tokens).toBe(localInputBilledTokens(estimateTokens(raw)));',
                'gateway preflight calibrated-input expectation',
            ],
        ];
        foreach ($pairs as [$old, $new, $label]) {
            $s = replaceOne($s, $old, $new, $label);
        }
        return $s;
    }, $staged);

    stagePatch($root, 'backend/app/Services/InferenceBillingService.php', function (string $s): string {
        $s = replaceOne(
            $s,
            "    // R43 provider-independent local prompt-cache discount. A matching prompt\n    // prefix consumes 25% of normal Token quota, preserving a meaningful customer\n    // saving while keeping the service margin sustainable. The gateway decides cache hits\n    // only from hashes of the customer request received at SP Cambo.\n    private const LOCAL_CACHE_READ_BPS = 2_500;",
            "    // V23 hard package policy: local cache/reuse remains visible as an\n    // informational metric, but it never creates bonus TOKEN_QUOTA capacity.\n    // One cached/reused token consumes one purchased token unit.\n    private const LOCAL_CACHE_READ_BPS = 10_000;",
            'billing cache policy constant'
        );

        $s = str_replace(
            '/** Published SP Cambo local smart-reuse rate; provider cache metadata is never consulted. */',
            '/** TOKEN_QUOTA cache reads are 1:1; provider cache metadata is never consulted. */',
            $s
        );

        $oldReserve = <<<'PHPBLOCK'
        // R42 local cache-aware quota: uncached input and output spend 1:1. A
        // repeated prompt prefix detected by SP Cambo's own hash-only cache spends
        // the published local-cache fraction. No OmniRoute/provider cache signal
        // participates in this calculation.
        if (($snapshot['billing_mode'] ?? null) === 'TOKEN_QUOTA') {
            $cacheUnits = $this->localCacheUnits($snapshot, $estimatedCacheReadTokens);
            return max(1, $this->checkedAdd(
                $this->checkedAdd($estimatedInputTokens, $cacheUnits),
                $requestedMaxOutputTokens,
            ));
        }
PHPBLOCK;
        $newReserve = <<<'PHPBLOCK'
        // V23 hard package ceiling: TOKEN_QUOTA is a purchased logical-token cap.
        // Locally reused/cache-read input is still shown to the customer, but it
        // consumes the same quota 1:1 and can never expand a 10M package beyond
        // 10M SP-local metered units. Provider/OmniRoute cache counters are ignored.
        if (($snapshot['billing_mode'] ?? null) === 'TOKEN_QUOTA') {
            return max(1, $this->checkedAdd(
                $this->checkedAdd($estimatedInputTokens, $estimatedCacheReadTokens),
                $requestedMaxOutputTokens,
            ));
        }
PHPBLOCK;
        $s = replaceOne($s, $oldReserve, $newReserve, 'billing token-quota reservation');

        $oldActual = <<<'PHPBLOCK'
        // Token packages and dollar-denominated quota Credits use only the
        // SP Cambo local meter. Uncached input/output are 1:1; a locally detected
        // cache hit receives the configured cache-read discount.
        if (($snapshot['billing_mode'] ?? null) === 'TOKEN_QUOTA') {
            $input = max(0, (int) ($usage['input_tokens'] ?? 0));
            $cached = $this->localCacheUnits($snapshot, max(0, (int) ($usage['cache_read_tokens'] ?? 0)));
            $output = max(0, (int) ($usage['output_tokens'] ?? 0));
            return $this->checkedAdd($this->checkedAdd($input, $cached), $output);
        }
PHPBLOCK;
        $newActual = <<<'PHPBLOCK'
        // TOKEN_QUOTA settlement uses the same hard-cap rule as reservation:
        // locally metered input + reused/cache-read input + output all consume
        // purchased quota 1:1. Cache remains a transparency/performance metric,
        // not extra customer capacity.
        if (($snapshot['billing_mode'] ?? null) === 'TOKEN_QUOTA') {
            $input = max(0, (int) ($usage['input_tokens'] ?? 0));
            $cached = max(0, (int) ($usage['cache_read_tokens'] ?? 0));
            $output = max(0, (int) ($usage['output_tokens'] ?? 0));
            return $this->checkedAdd($this->checkedAdd($input, $cached), $output);
        }
PHPBLOCK;
        $s = replaceOne($s, $oldActual, $newActual, 'billing token-quota settlement');

        $s = str_replace(
            "        // R43 final smart-reuse policy is snapshotted into each entitlement lot.\n        // New catalogue purchases use 25%, while an already-purchased lot keeps\n        // the reuse rate promised when it was created. Provider/OmniRoute cache\n        // metadata still never participates in this calculation.",
            "        // Compatibility helper for snapshotted cache policies. V23 TOKEN_QUOTA\n        // reservation/settlement no longer calls this helper: cache reads are 1:1.\n        // Provider/OmniRoute cache metadata never participates in customer quota.",
            $s
        );

        return $s;
    }, $staged);

    stagePatch($root, 'backend/routes/api.php', function (string $s): string {
        return replaceOne(
            $s,
            "Route::post('keys/check', [ApiKeyController::class, 'check'])->middleware('throttle:10,1');",
            "Route::post('keys/check', [ApiKeyController::class, 'check'])->middleware('throttle:60,1');",
            'public key checker throttle'
        );
    }, $staged);

    stagePatch($root, 'frontend/app/pages/public/key-checker.vue', function (string $s): string {
        $oldRate = <<<'VUE'
    // V22.2 silent checker rate limit:
    // A checker 429 is not a credential failure. Keep the current successful
    // snapshot (if any), show no warning, and let the normal refresh timer
    // retry automatically. For a first/manual check, keep the secret only in
    // component memory so the next refresh can recover without another click.
    const spError = toSpApiError(error)
    if (spError.code === 'rate_limit_exceeded') {
      checkError.value = null
      refreshWarning.value = ''
      sessionSecret.value = secret
      autoRefresh.value = true
      return
    }
VUE;
        $newRate = <<<'VUE'
    // A 429 is never treated as an invalid credential. Keep the secret only in
    // this tab, retry automatically, and explain the first/manual miss instead
    // of leaving a blank result that mysteriously starts working later.
    const spError = toSpApiError(error)
    if (spError.code === 'rate_limit_exceeded') {
      sessionSecret.value = secret
      autoRefresh.value = true
      refreshWarning.value = ''
      checkError.value = mode === 'manual' && !keyStatus.value
        ? {
            title: 'Checker is busy',
            description: 'Too many checks came from this network. Retrying automatically in a few seconds.',
            retryable: true
          }
        : null
      return
    }
VUE;
        $s = replaceOne($s, $oldRate, $newRate, 'checker 429 behavior');

        if (!str_contains($s, 'const reuseSharePercent = computed(')) {
            $anchor = <<<'VUE'
const lastCheckedLabel = computed(() => {
  if (!lastRefreshedAt.value) return 'Not checked yet'
  const seconds = Math.max(0, Math.floor((liveClock.value - lastRefreshedAt.value.getTime()) / 1000))
  if (seconds < 5) return 'Checked just now'
  if (seconds < 60) return `Checked ${seconds}s ago`
  return `Checked ${Math.floor(seconds / 60)}m ago`
})

VUE;
            $extra = <<<'VUE'
const reuseSharePercent = computed(() => {
  const freshInput = Number(keyStatus.value?.tokens_used?.input ?? 0)
  const reusedInput = Number(keyStatus.value?.tokens_used?.cached_input ?? 0)
  const inputBase = Math.max(0, freshInput) + Math.max(0, reusedInput)
  return inputBase > 0 ? (Math.max(0, reusedInput) * 100) / inputBase : 0
})

const requestReuseShare = (request: NonNullable<PublicApiKeyStatus['recent_requests']>[number]) => {
  const freshInput = Number(request.input_tokens ?? 0)
  const reusedInput = Number(request.cached_input_tokens ?? 0)
  const inputBase = Math.max(0, freshInput) + Math.max(0, reusedInput)
  return inputBase > 0 ? `${((Math.max(0, reusedInput) * 100) / inputBase).toFixed(1)}%` : '—'
}

VUE;
            $s = replaceOne($s, $anchor, $anchor . $extra, 'checker reuse-share helpers');
        }

        $oldReuseMetric = <<<'VUE'
                <dt>Saved by cache</dt><dd class="text-success">
                  {{ formatUnits(keyStatus.tokens_used?.saved ?? '0') }}
                </dd>
VUE;
        $newReuseMetric = <<<'VUE'
                <dt>Reuse share</dt><dd class="text-info">
                  {{ reuseSharePercent.toFixed(1) }}%
                </dd>
VUE;
        $oldSavingsMetric = <<<'VUE'
                <dt>Savings rate</dt><dd class="text-success">
                  {{ Number(keyStatus.tokens_used?.savings_rate_percent ?? 0).toFixed(1) }}%
                </dd>
VUE;
        $newSavingsMetric = <<<'VUE'
                <dt>Quota policy</dt><dd class="text-highlighted">
                  Hard cap
                </dd>
VUE;

        $replacements = [
            ['                  10 checks/min', '                  60 checks/min', 'checker badge'],
            ["{ icon: 'i-lucide-activity', title: 'Local metering', text: 'Usage, reuse savings and recent request states.' }", "{ icon: 'i-lucide-activity', title: 'Local metering', text: 'Usage, context reuse and recent request states.' }", 'checker side copy'],
            ['                    Usage and smart reuse', '                    Usage and context reuse', 'checker usage title'],
            ['                  Automatic savings', '                  Hard quota safe', 'checker usage badge'],
            [$oldReuseMetric, $newReuseMetric, 'checker reuse metric'],
            [$oldSavingsMetric, $newSavingsMetric, 'checker quota policy metric'],
            ['                      Saved by cache', '                      Reuse share', 'checker recent header'],
            ["                      {{ request.saved_tokens === null || request.saved_tokens === undefined || request.saved_tokens === '0' ? '—' : formatUnits(request.saved_tokens) }}", '                      {{ requestReuseShare(request) }}', 'checker recent reuse cell'],
        ];
        foreach ($replacements as [$old, $new, $label]) {
            $s = replaceOne($s, $old, $new, $label);
        }

        $s = replaceOne(
            $s,
            '              <span>Provider or OmniRoute counters never control this customer balance.</span>',
            '              <span>Reused context is shown for transparency and still counts inside the same purchased token quota.</span>',
            'checker quota disclosure'
        );

        return $s;
    }, $staged);

    stagePatch($root, 'backend/tests/Unit/ApiKeyCheckTest.php', function (string $s): string {
        if (!str_contains($s, "\$this->assertNull(\$issued['key']->fresh()->last_used_at);")) {
            $s = replaceOne(
                $s,
                "        \$issued = \$this->issueKey(\$user, \$alias);\n        \$issued['key']->forceFill([",
                "        \$issued = \$this->issueKey(\$user, \$alias);\n\n        // Public possession checks must work before this key has ever made an inference request.\n        \$this->assertNull(\$issued['key']->fresh()->last_used_at);\n\n        \$issued['key']->forceFill([",
                'never-used key regression setup'
            );
        }
        if (!str_contains($s, "->assertJsonPath('data.last_used', null)")) {
            $s = replaceOne(
                $s,
                "            ->assertJsonPath('data.status', 'ACTIVE')\n            ->assertJsonPath('data.model_details.0.public_alias', 'claude-coding')",
                "            ->assertJsonPath('data.status', 'ACTIVE')\n            ->assertJsonPath('data.last_used', null)\n            ->assertJsonPath('data.model_details.0.public_alias', 'claude-coding')",
                'never-used key response assertion'
            );
        }
        return $s;
    }, $staged);

    // Validate every source patch before touching the filesystem.
    foreach ($staged as $relative => $row) {
        if ($row['after'] === '') {
            throw new RuntimeException("{$relative}: patch unexpectedly produced an empty file.");
        }
    }

    // Back up and write only after all anchors were validated.
    foreach ($staged as $relative => $row) {
        if ($row['after'] === $row['before']) {
            echo "[unchanged] {$relative}\n";
            continue;
        }
        $backup = $row['path'] . '.bak-v23-' . $stamp;
        if (!@copy($row['path'], $backup)) {
            throw new RuntimeException("Could not create backup {$backup}");
        }
        if (@file_put_contents($row['path'], $row['after']) === false) {
            throw new RuntimeException("Could not write {$row['path']}");
        }
        $changed[] = $relative;
        echo "[patched] {$relative}\n";
    }

    $gatewayTest = <<<'TS'
import { describe, expect, it } from "vitest";
import {
  LOCAL_INPUT_CALIBRATION_BPS,
  LOCAL_OUTPUT_CALIBRATION_BPS,
  localInputBilledTokens,
  localOutputBilledTokens,
  spLocalUsageFromOutputTokens,
} from "../src/protocol.js";

describe("SP Cambo V23 local profit metering", () => {
  it("uses a small input margin and the updated output margin", () => {
    expect(LOCAL_INPUT_CALIBRATION_BPS).toBe(10_500);
    expect(LOCAL_OUTPUT_CALIBRATION_BPS).toBe(15_000);
    expect(localInputBilledTokens(1)).toBe(1);
    expect(localInputBilledTokens(1_000)).toBe(1_050);
    expect(localOutputBilledTokens(1_000)).toBe(1_500);
  });

  it("applies the same local input calibration to fresh and reused prompt input", () => {
    expect(spLocalUsageFromOutputTokens(1_000, 500, 1_000)).toEqual({
      input_tokens: 1_050,
      output_tokens: 1_500,
      cache_read_tokens: 525,
      cache_write_tokens: 0,
      reasoning_tokens: 0,
    });
  });
});
TS;

    $gatewayTestPath = $root . '/gateway/tests/profit-metering.test.ts';
    if (!file_exists($gatewayTestPath)) {
        file_put_contents($gatewayTestPath, $gatewayTest . "\n");
        $created[] = 'gateway/tests/profit-metering.test.ts';
        echo "[created] gateway/tests/profit-metering.test.ts\n";
    } elseif (!str_contains(readFileOrFail($gatewayTestPath), 'SP Cambo V23 local profit metering')) {
        throw new RuntimeException('gateway/tests/profit-metering.test.ts already exists with unrelated content; not overwriting.');
    }

    $billingTest = <<<'PHPTEST'
<?php

namespace Tests\Unit;

use App\Services\InferenceBillingService;
use Tests\TestCase;

class InferenceBillingTokenQuotaTest extends TestCase
{
    public function test_cached_input_cannot_expand_a_token_package_beyond_purchased_units(): void
    {
        $billing = app(InferenceBillingService::class);
        $snapshot = ['billing_mode' => 'TOKEN_QUOTA'];

        // 6M fresh + 3M reused/cache-read + 1M output = exactly 10M.
        // The old 25% cache policy would have charged only 7.75M here.
        $this->assertSame(
            10_000_000,
            $billing->reservationUnits($snapshot, 6_000_000, 3_000_000, 1_000_000),
        );

        $this->assertSame(
            10_000_000,
            $billing->actualUnits($snapshot, [
                'input_tokens' => 6_000_000,
                'cache_read_tokens' => 3_000_000,
                'output_tokens' => 1_000_000,
                'cache_write_tokens' => 0,
                'reasoning_tokens' => 0,
            ]),
        );
    }
}
PHPTEST;

    $billingTestPath = $root . '/backend/tests/Unit/InferenceBillingTokenQuotaTest.php';
    if (!file_exists($billingTestPath)) {
        file_put_contents($billingTestPath, $billingTest . "\n");
        $created[] = 'backend/tests/Unit/InferenceBillingTokenQuotaTest.php';
        echo "[created] backend/tests/Unit/InferenceBillingTokenQuotaTest.php\n";
    } elseif (!str_contains(readFileOrFail($billingTestPath), 'cached_input_cannot_expand_a_token_package')) {
        throw new RuntimeException('backend/tests/Unit/InferenceBillingTokenQuotaTest.php already exists with unrelated content; not overwriting.');
    }

    // PHP syntax checks for changed backend PHP files and new test.
    $phpFiles = [
        $root . '/backend/app/Services/InferenceBillingService.php',
        $root . '/backend/routes/api.php',
        $root . '/backend/tests/Unit/ApiKeyCheckTest.php',
        $billingTestPath,
    ];
    foreach ($phpFiles as $phpFile) {
        $cmd = 'php -l ' . escapeshellarg($phpFile) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("PHP syntax check failed for {$phpFile}:\n" . implode("\n", $output));
        }
        echo "[php -l ok] " . str_replace($root . '/', '', $phpFile) . "\n";
        $output = [];
    }

    echo "\nV23 source patch completed.\n";
    echo "Changed files: " . count($changed) . "; created tests: " . count($created) . ".\n";
    echo "Next run gateway/backend/frontend tests and builds before restarting production services.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nV23 ABORTED: " . $e->getMessage() . "\n");
    fwrite(STDERR, "No further source patching should be attempted until the anchor mismatch is reviewed.\n");
    exit(1);
}
