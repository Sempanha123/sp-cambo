<?php
declare(strict_types=1);

/**
 * SP Cambo V22.1
 *
 * 1) Public Key Checker Recent requests -> refresh every 8 seconds.
 * 2) Dashboard Usage/Request log -> refresh every 8 seconds.
 * 3) Running request rows -> rotating loader-circle beside numeric status.
 * 4) Local-only output metering -> slightly stronger 1.30x safety.
 *
 * IMPORTANT:
 * - Provider / OmniRoute usage counters remain ignored for customer billing.
 * - Input metering and local cache metering are unchanged.
 * - The 1.30x adjustment applies only to SP Cambo's locally measured OUTPUT.
 *
 * Run from project root:
 *   php ./APPLY-SPCAMBO-V22.1.php
 */

$root = __DIR__;

$paths = [
    'checker' => $root.'/frontend/app/pages/public/key-checker.vue',
    'usage' => $root.'/frontend/app/pages/dashboard/usage.vue',
    'protocol' => $root.'/gateway/src/protocol.ts',
    'gateway_test' => $root.'/gateway/tests/app.test.ts',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: Missing {$name}: {$path}\n");
        fwrite(STDERR, "Put this installer in the SP Cambo project root.\n");
        exit(1);
    }
}

$files = [];
$original = [];
foreach ($paths as $name => $path) {
    $files[$name] = (string) file_get_contents($path);
    $original[$name] = $files[$name];
}

$notes = [];
$warnings = [];

/* ========================================================================== 
 * 1) KEY CHECKER -> 8 seconds
 * ======================================================================= */

$checker = $files['checker'];

if (preg_match('/const CHECK_INTERVAL_MS = ([0-9_]+)/', $checker, $m)) {
    if ($m[1] !== '8_000') {
        $checker = preg_replace(
            '/const CHECK_INTERVAL_MS = [0-9_]+/',
            'const CHECK_INTERVAL_MS = 8_000',
            $checker,
            1
        ) ?? $checker;
        $notes[] = 'Key Checker Recent requests: refresh interval -> 8 seconds';
    } else {
        $notes[] = 'Key Checker Recent requests: already 8 seconds';
    }
} else {
    $warnings[] = 'Key Checker: CHECK_INTERVAL_MS constant not found';
}

/* Ensure the running row has the spinner even on older/local markup. */
if (!str_contains($checker, 'sp-request-status__spinner')) {
    $pattern = '~(<div\s+class=["\']sp-request-status["\'][^>]*>\s*)(<strong\s+class=["\']sp-request-status__code["\']>\s*\{\{\s*requestStatusCode\(request\)\s*\}\}\s*</strong>)~';
    $replacement = <<<'VUE'
$1<UIcon
                          v-if="runningStates.includes(request.state)"
                          name="i-lucide-loader-circle"
                          class="sp-request-status__spinner size-3.5 animate-spin"
                          aria-hidden="true"
                        />
                        $2
VUE;
    $candidate = preg_replace($pattern, $replacement, $checker, 1, $count);
    if ($candidate !== null && $count > 0) {
        $checker = $candidate;
        $notes[] = 'Key Checker: added rotating circle for running request';
    } else {
        $warnings[] = 'Key Checker: could not find numeric request status markup for spinner';
    }
} else {
    $notes[] = 'Key Checker: running spinner already present';
}

$files['checker'] = $checker;

/* ========================================================================== 
 * 2) DASHBOARD USAGE / REQUEST LOG -> 8 seconds
 * ======================================================================= */

$usage = $files['usage'];

$activityPattern = '~activityTimer\s*=\s*setInterval\(\(\)\s*=>\s*\{\s*if\s*\(liveRefresh\.value\)\s*void\s*activity\.refresh\(\)\s*\}\s*,\s*[0-9_]+\s*\)~s';
if (preg_match($activityPattern, $usage)) {
    $usage = preg_replace(
        $activityPattern,
        "activityTimer = setInterval(() => {\n    if (liveRefresh.value) void activity.refresh()\n  }, 8_000)",
        $usage,
        1
    ) ?? $usage;
    $notes[] = 'Usage Request log: activity refresh interval -> 8 seconds';
} else {
    $warnings[] = 'Usage: activity refresh timer block not found';
}

/* Ensure Request log running status has rotating circle. */
if (!str_contains($usage, 'sp-request-status__spinner')) {
    $pattern = '~(<div\s+[^>]*class=["\']sp-request-status["\'][^>]*>\s*)(<strong\s+class=["\']sp-request-status__code["\']>\s*\{\{\s*requestResultCode\(item\)\s*\}\}\s*</strong>)~';
    $replacement = <<<'VUE'
$1<UIcon
                    v-if="liveStates.includes(item.state)"
                    name="i-lucide-loader-circle"
                    class="sp-request-status__spinner size-3.5 animate-spin"
                    aria-hidden="true"
                  />
                  $2
VUE;
    $candidate = preg_replace($pattern, $replacement, $usage, 1, $count);
    if ($candidate !== null && $count > 0) {
        $usage = $candidate;
        $notes[] = 'Usage Request log: added rotating circle for running request';
    } else {
        $warnings[] = 'Usage: could not find numeric Request log status markup for spinner';
    }
} else {
    $notes[] = 'Usage Request log: running spinner already present';
}

/* Reinforce spinner animation without changing completed status style. */
if (!str_contains($usage, 'V22.1 request-log running spinner')) {
    $usage .= <<<'VUE'


<style scoped>
/* V22.1 request-log running spinner */
.sp-request-status__spinner {
  flex: 0 0 auto;
  transform-origin: center;
}
</style>
VUE;
}

$files['usage'] = $usage;

/* ========================================================================== 
 * 3) LOCAL OUTPUT METERING -> 1.30x
 *
 * If R45/R44 already installed, increase its existing safety factor.
 * If the repo is still on R42, install a minimal local-output calibration.
 * ======================================================================= */

$protocol = $files['protocol'];
$hadCalibration = str_contains($protocol, 'LOCAL_OUTPUT_CALIBRATION_BPS');

if ($hadCalibration) {
    if (preg_match('/export const LOCAL_OUTPUT_CALIBRATION_BPS\s*=\s*([0-9_]+)\s*;/', $protocol, $m)) {
        $numeric = (int) str_replace('_', '', $m[1]);
        if ($numeric < 13000) {
            $protocol = preg_replace(
                '/export const LOCAL_OUTPUT_CALIBRATION_BPS\s*=\s*[0-9_]+\s*;/',
                'export const LOCAL_OUTPUT_CALIBRATION_BPS = 13_000;',
                $protocol,
                1
            ) ?? $protocol;
            $notes[] = sprintf('Gateway local OUTPUT safety: %.2fx -> 1.30x', $numeric / 10000);
        } else {
            $notes[] = sprintf('Gateway local OUTPUT safety already %.2fx; V22.1 did not reduce it', $numeric / 10000);
        }
    } else {
        $warnings[] = 'Gateway: LOCAL_OUTPUT_CALIBRATION_BPS exists but value could not be parsed';
    }

    /* Update common R45 regression expectation: 622 * 1.30 = 809. */
    $files['gateway_test'] = str_replace(
        'expect(localOutputBilledTokens(622)).toBe(747);',
        'expect(localOutputBilledTokens(622)).toBe(809);',
        $files['gateway_test']
    );
    $files['gateway_test'] = str_replace(
        'expect(localOutputBilledTokens(622)).toBe(933);',
        'expect(localOutputBilledTokens(622)).toBe(809);',
        $files['gateway_test']
    );
} else {
    /* Current main/R42: add one deterministic output-only calibration helper. */
    $anchor = 'export type PromptSegment = { digest: string; tokens: number };';
    if (str_contains($protocol, $anchor)) {
        $helper = <<<'TS'
export const LOCAL_OUTPUT_CALIBRATION_BPS = 13_000;

/**
 * SP Cambo output-only safety calibration.
 *
 * This uses ONLY the output already measured locally by SP Cambo. Provider and
 * OmniRoute usage counters remain completely irrelevant to customer billing.
 * 13_000 bps = 1.30x local generated-output estimate.
 */
export function localOutputBilledTokens(tokens: number): number {
  const safe = Math.max(0, Math.floor(tokens));
  if (safe === 0) return 0;
  return Math.ceil((safe * LOCAL_OUTPUT_CALIBRATION_BPS) / 10_000);
}

TS;
        $protocol = str_replace($anchor, $anchor."\n\n".$helper, $protocol);
        $notes[] = 'Gateway: installed 1.30x local-only OUTPUT calibration';
    } else {
        $warnings[] = 'Gateway: PromptSegment anchor not found; could not add output calibration helper';
    }

    /* Non-stream JSON settlement. */
    $old = 'output_tokens: Math.max(0, outputTokens),';
    $new = 'output_tokens: localOutputBilledTokens(outputTokens),';

    $first = strpos($protocol, $old);
    if ($first !== false) {
        $protocol = substr_replace($protocol, $new, $first, strlen($old));
        $notes[] = 'Gateway: non-stream output now uses 1.30x local safety';
    } else {
        $warnings[] = 'Gateway: non-stream output_tokens assignment not found';
    }

    /* Streaming settlement: spLocalUsageFromOutputTokens contains the second assignment. */
    $second = strpos($protocol, $old, $first !== false ? $first + strlen($new) : 0);
    if ($second !== false) {
        $protocol = substr_replace($protocol, $new, $second, strlen($old));
        $notes[] = 'Gateway: streaming output now uses 1.30x local safety';
    } else {
        /* If first replacement shifted search, search inside function specifically. */
        $fn = strpos($protocol, 'export function spLocalUsageFromOutputTokens');
        if ($fn !== false) {
            $second = strpos($protocol, $old, $fn);
            if ($second !== false) {
                $protocol = substr_replace($protocol, $new, $second, strlen($old));
                $notes[] = 'Gateway: streaming output now uses 1.30x local safety';
            } else {
                $warnings[] = 'Gateway: streaming output_tokens assignment not found';
            }
        } else {
            $warnings[] = 'Gateway: spLocalUsageFromOutputTokens function not found';
        }
    }

    /* Current-main tests: raw local 2 -> calibrated 3. */
    $files['gateway_test'] = str_replace(
        'output_tokens: 2, cache_read_tokens: 0, cache_write_tokens: 0, reasoning_tokens: 0',
        'output_tokens: 3, cache_read_tokens: 0, cache_write_tokens: 0, reasoning_tokens: 0',
        $files['gateway_test']
    );
    $files['gateway_test'] = str_replace(
        'output_tokens: 2 });',
        'output_tokens: 3 });',
        $files['gateway_test']
    );
}

$files['protocol'] = $protocol;

/* ========================================================================== 
 * WRITE CHANGED FILES
 * ======================================================================= */

$timestamp = date('Ymd-His');
$written = 0;

foreach ($paths as $name => $path) {
    if ($files[$name] === $original[$name]) {
        continue;
    }

    $backup = $path.'.bak-v22.1-'.$timestamp;
    if (!copy($path, $backup)) {
        fwrite(STDERR, "ERROR: Could not create backup for {$path}\n");
        exit(1);
    }

    if (file_put_contents($path, $files[$name]) === false) {
        @copy($backup, $path);
        fwrite(STDERR, "ERROR: Could not write {$path}; backup restored.\n");
        exit(1);
    }

    echo "UPDATED: {$path}\n";
    echo "BACKUP : {$backup}\n";
    $written++;
}

echo "\nSP Cambo V22.1 complete.\n";
echo "Files changed: {$written}\n\n";

foreach ($notes as $note) {
    echo "  + {$note}\n";
}

if ($warnings !== []) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "  ! {$warning}\n";
    }
}

echo "\nFinal behavior:\n";
echo "  Recent requests refresh  : every 8s\n";
echo "  Usage Request log refresh: every 8s\n";
echo "  Running status           : rotating circle + numeric code\n";
echo "  Local OUTPUT safety      : 1.30x\n";
echo "  Input/cache metering     : unchanged\n";
echo "  Provider usage billing   : NEVER used\n\n";

echo "Validate frontend:\n";
echo "  cd frontend\n";
echo "  rm -rf .nuxt .output\n";
echo "  pnpm typecheck\n";
echo "  pnpm build\n\n";

echo "Validate gateway:\n";
echo "  cd ../gateway\n";
echo "  pnpm typecheck\n";
echo "  pnpm test\n";
echo "  pnpm build\n";
