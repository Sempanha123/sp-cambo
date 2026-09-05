<?php
declare(strict_types=1);

/**
 * SP Cambo V21.9 — Key Checker cleanup + ultra-soft hover
 *
 * Changes:
 * - DELETE the "Key-level limits" card from public key checker.
 * - DELETE the visible "Auto refresh · 10s/15s" chip.
 * - KEEP background auto-refresh logic running.
 * - Reduce SP Cambo hover tint from ~2% to ~0.5% (almost invisible).
 * - Keep Recent requests row hover at exactly none.
 * - Preserve V21.8 running spinner / numeric request statuses.
 *
 * Run from project root:
 *   php ./APPLY-SPCAMBO-KEYCHECKER-CALM-V21.9.php
 */

$root = __DIR__;

$checkerPath = $root.'/frontend/app/pages/public/key-checker.vue';
$cssPath = $root.'/frontend/app/assets/css/main.css';

foreach ([$checkerPath, $cssPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: Missing {$path}\n");
        fwrite(STDERR, "Put this installer in the SP Cambo project root.\n");
        exit(1);
    }
}

$checker = (string) file_get_contents($checkerPath);
$css = (string) file_get_contents($cssPath);

$originalChecker = $checker;
$originalCss = $css;

$notes = [];
$warnings = [];

/* ==========================================================================
 * 1) Remove Key-level limits card
 * ======================================================================= */

/*
 * Match one UCard whose content contains the exact "Key-level limits" heading.
 * This is intentionally scoped to that card only.
 */
$pattern = '~\s*<UCard\b[^>]*class=["\'][^"\']*sp-app-card[^"\']*["\'][^>]*>(?:(?!</UCard>)[\s\S])*?Key-level limits(?:(?!</UCard>)[\s\S])*?</UCard>\s*~';

$candidate = preg_replace($pattern, "\n", $checker, 1, $count);
if ($candidate !== null && $count > 0) {
    $checker = $candidate;
    $notes[] = 'key checker: deleted Key-level limits card';
} elseif (!str_contains($checker, 'Key-level limits')) {
    $notes[] = 'key checker: Key-level limits card already absent';
} else {
    /*
     * Adaptive fallback: find the nearest UCard before the heading and its next
     * closing UCard. Current key-checker cards do not nest UCard inside this one.
     */
    $headingPos = strpos($checker, 'Key-level limits');
    $cardStart = $headingPos === false ? false : strrpos(substr($checker, 0, $headingPos), '<UCard');
    $cardEnd = $headingPos === false ? false : strpos($checker, '</UCard>', $headingPos);

    if ($cardStart !== false && $cardEnd !== false) {
        $cardEnd += strlen('</UCard>');
        $checker = substr($checker, 0, $cardStart).substr($checker, $cardEnd);
        $notes[] = 'key checker: deleted Key-level limits card using adaptive fallback';
    } else {
        $warnings[] = 'could not safely locate the complete Key-level limits card';
    }
}

/* Remove helper functions that were only used by the deleted limits card. */
if (!str_contains($checker, 'formatLimit(') || substr_count($checker, 'formatLimit(') === 1) {
    $checker = preg_replace(
        '~\nconst formatLimit = \(value: number \| null \| undefined\) => value === null \|\| value === undefined[\s\S]*?\n  : formatUnits\(value\)\n~',
        "\n",
        $checker,
        1
    ) ?? $checker;
}

if (!str_contains($checker, 'formatBytes(') || substr_count($checker, 'formatBytes(') === 1) {
    $checker = preg_replace(
        '~\nconst formatBytes = \(value: number \| null \| undefined\) => \{[\s\S]*?\n\}\n~',
        "\n",
        $checker,
        1
    ) ?? $checker;
}

/* ==========================================================================
 * 2) Remove visible Auto refresh chip, KEEP timer/autoRefresh logic
 * ======================================================================= */

$autoPattern = '~\s*<div\s+v-if=["\']sessionSecret["\']\s+class=["\']sp-checker-auto-refresh["\']\s*>[\s\S]*?</div>\s*~';

$candidate = preg_replace($autoPattern, "\n", $checker, 1, $autoCount);
if ($candidate !== null && $autoCount > 0) {
    $checker = $candidate;
    $notes[] = 'key checker: removed visible Auto refresh chip; background refresh remains enabled';
} elseif (
    !str_contains($checker, 'Auto refresh · 10s')
    && !str_contains($checker, 'Auto refresh · 15s')
    && !str_contains($checker, 'sp-checker-auto-refresh')
) {
    $notes[] = 'key checker: Auto refresh chip already absent';
} else {
    /* More tolerant local markup fallback. */
    $candidate = preg_replace(
        '~\s*<div\b[^>]*class=["\'][^"\']*sp-checker-auto-refresh[^"\']*["\'][^>]*>[\s\S]*?</div>\s*~',
        "\n",
        $checker,
        1,
        $fallbackCount
    );

    if ($candidate !== null && $fallbackCount > 0) {
        $checker = $candidate;
        $notes[] = 'key checker: removed Auto refresh chip using adaptive fallback';
    } else {
        $warnings[] = 'could not locate the visible Auto refresh chip';
    }
}

/*
 * Do NOT touch:
 *   const autoRefresh = ref(...)
 *   refreshTimer
 *   watch([autoRefresh, sessionSecret], ...)
 * This preserves silent background refresh.
 */

/* ==========================================================================
 * 3) Key Checker page-scoped final hover rule
 * ======================================================================= */

if (!str_contains($checker, 'V21.9 checker no-row-hover')) {
    $checker .= <<<'VUE'


<style scoped>
/* V21.9 checker no-row-hover */

/* Recent requests: absolutely no mouse-hover surface change. */
.sp-checker-request-row,
.sp-checker-request-row:hover {
  background: transparent !important;
  background-color: transparent !important;
  background-image: none !important;
  box-shadow: none !important;
  filter: none !important;
  transform: none !important;
}

/* Verification result remains a quiet box. */
.sp-checker-result,
.sp-checker-result:hover {
  box-shadow: none !important;
  filter: none !important;
  transform: none !important;
}

.sp-checker-result__glow {
  display: none !important;
}
</style>
VUE;
    $notes[] = 'key checker: reinforced zero hover background for Recent requests';
}

/* ==========================================================================
 * 4) GLOBAL hover tint ~0.5%
 * ======================================================================= */

/*
 * If V21.8 exists, override its variables at the end so source order wins.
 * We intentionally do NOT use a generic background-color: !important on every
 * button, because that would destroy solid primary button surfaces.
 */
if (!str_contains($css, 'SP CAMBO V21.9 — NEAR-INVISIBLE HOVER')) {
    $css .= <<<'CSS'


/* ==========================================================================
   SP CAMBO V21.9 — NEAR-INVISIBLE HOVER

   Keep hover discoverable but extremely quiet.
   Selected/active states remain stronger and are not changed here.
   ========================================================================== */

@layer utilities {
  :root,
  .dark {
    --sp-panel-hover: color-mix(
      in oklab,
      var(--sp-panel, var(--ui-bg-elevated)) 99.5%,
      var(--ui-primary) 0.5%
    );
    --sp-nav-hover: color-mix(in oklab, var(--ui-primary) 0.5%, transparent);
    --sp-button-glow: none;
  }

  @media (hover: hover) and (pointer: fine) {
    :where(
      button,
      a[href],
      [role="button"],
      [role="radio"],
      [role="tab"],
      .sp-action-primary,
      .sp-app-card,
      [data-slot="card"]
    ):hover {
      box-shadow: none !important;
      filter: none !important;
      text-shadow: none !important;
      transform: none !important;
    }
  }

  .sp-action-primary,
  .sp-action-primary:hover,
  .sp-action-primary:active {
    box-shadow: none !important;
    filter: none !important;
    transform: none !important;
  }
}
CSS;
    $notes[] = 'global: hover tint reduced to 0.5% (near invisible)';
} else {
    $notes[] = 'global: V21.9 near-invisible hover already present';
}

/* ==========================================================================
 * WRITE
 * ======================================================================= */

$timestamp = date('Ymd-His');
$written = 0;

$write = function (string $path, string $before, string $after) use ($timestamp, &$written): void {
    if ($before === $after) return;

    $backup = $path.'.bak-v21.9-'.$timestamp;
    if (!copy($path, $backup)) {
        throw new RuntimeException("Could not create backup: {$backup}");
    }

    if (file_put_contents($path, $after) === false) {
        @copy($backup, $path);
        throw new RuntimeException("Could not write {$path}; backup restored.");
    }

    echo "UPDATED: {$path}\n";
    echo "BACKUP : {$backup}\n";
    $written++;
};

try {
    $write($checkerPath, $originalChecker, $checker);
    $write($cssPath, $originalCss, $css);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
    exit(1);
}

echo "\nSP Cambo V21.9 complete.\n";
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

echo "\nExpected result:\n";
echo "  - Key-level limits box: removed\n";
echo "  - Auto refresh text/chip: removed\n";
echo "  - Background auto refresh: still running\n";
echo "  - Recent requests hover: none\n";
echo "  - Other site hover: ~0.5% tint, almost invisible\n";
echo "  - Running numeric spinner from V21.8 remains\n\n";

echo "Build:\n";
echo "  cd frontend\n";
echo "  rm -rf .nuxt .output\n";
echo "  pnpm typecheck\n";
echo "  pnpm build\n";
