<?php
declare(strict_types=1);

/**
 * SP Cambo V21.8
 *
 * UI polish requested:
 * - Hover background becomes extremely subtle site-wide.
 * - Recent requests rows have NO hover background at all.
 * - Verification result becomes a calm boxed panel (no glow).
 * - Running request status shows a rotating circle icon + numeric code.
 * - Existing request status remains numeric-only.
 *
 * Run from SP Cambo project root:
 *   php ./APPLY-SPCAMBO-CALM-HOVER-STATUS-V21.8.php
 */

$root = __DIR__;

$paths = [
    'main_css' => $root.'/frontend/app/assets/css/main.css',
    'checker' => $root.'/frontend/app/pages/public/key-checker.vue',
    'usage' => $root.'/frontend/app/pages/dashboard/usage.vue',
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
 * 1) GLOBAL HOVER — almost invisible, never glowing
 * ======================================================================= */

$css = $files['main_css'];

if (!str_contains($css, 'SP CAMBO V21.8 — ULTRA-CALM HOVER')) {
    $css .= <<<'CSS'


/* ==========================================================================
   SP CAMBO V21.8 — ULTRA-CALM HOVER

   Hover should be barely visible:
   - no glow
   - no lift
   - no scale
   - tiny surface tint only where a component already uses SP hover variables
   - active/selected states are NOT changed
   ========================================================================== */

@layer utilities {
  :root {
    --sp-panel-hover: color-mix(in oklab, var(--sp-panel, var(--ui-bg-elevated)) 98%, var(--ui-primary) 2%);
    --sp-nav-hover: color-mix(in oklab, var(--ui-primary) 2.5%, transparent);
    --sp-button-glow: none;
  }

  .dark {
    --sp-panel-hover: color-mix(in oklab, var(--sp-panel, var(--ui-bg-elevated)) 98%, var(--ui-primary) 2%);
    --sp-nav-hover: color-mix(in oklab, var(--ui-primary) 2.5%, transparent);
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
    $notes[] = 'global: hover tint reduced to ~2–2.5%, no glow/lift';
} else {
    $notes[] = 'global: V21.8 ultra-calm hover already present';
}

$files['main_css'] = $css;

/* ==========================================================================
 * 2) KEY CHECKER
 * ======================================================================= */

$checker = $files['checker'];

/*
 * Add spinner to numeric request status if the row is in a live state.
 * Works whether V21.7 already removed the text label or not.
 */
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
        $notes[] = 'key checker: live request status gets rotating circle icon';
    } else {
        $warnings[] = 'key checker: could not locate numeric request status markup for spinner';
    }
} else {
    $notes[] = 'key checker: running spinner already present';
}

/* Remove any remaining word/status label from Recent requests. */
$checker = preg_replace(
    '~\s*<span\s+class=["\']sp-request-status__label["\']>[\s\S]*?</span>~',
    '',
    $checker
) ?? $checker;

/* Append calm, page-scoped overrides. */
if (!str_contains($checker, 'V21.8 checker calm surfaces')) {
    $checker .= <<<'VUE'


<style scoped>
/* V21.8 checker calm surfaces */

/* Verification result: a clean box, not a glowing hero. */
.sp-checker-result {
  border: 1px solid var(--kc-line) !important;
  background:
    linear-gradient(
      180deg,
      color-mix(in oklab, var(--kc-panel) 97%, var(--checker-tone) 3%),
      color-mix(in oklab, var(--kc-panel) 99%, var(--checker-tone) 1%)
    ) !important;
  box-shadow: none !important;
  filter: none !important;
}

.sp-checker-result__glow {
  display: none !important;
}

/* Keep the icon readable, but make its surface quiet. */
.sp-checker-status-icon {
  border: 1px solid color-mix(in oklab, var(--checker-tone) 18%, var(--kc-line)) !important;
  background: color-mix(in oklab, var(--checker-tone) 7%, transparent) !important;
  box-shadow: none !important;
}

/* Recent requests should not react visually when the mouse moves across rows. */
.sp-checker-request-row,
.sp-checker-request-row:hover {
  background: transparent !important;
  box-shadow: none !important;
  filter: none !important;
  transform: none !important;
}

/* Numeric status only. Running gets a tiny rotating circle next to the code. */
.sp-request-status {
  min-width: 3.9rem !important;
  flex-direction: row !important;
  align-items: center !important;
  justify-content: center !important;
  gap: .35rem !important;
  box-shadow: none !important;
}

.sp-request-status__label {
  display: none !important;
}

.sp-request-status__spinner {
  flex: 0 0 auto;
  color: var(--kc-cyan);
}

:global(html.light) .sp-checker-result {
  background:
    linear-gradient(
      180deg,
      color-mix(in oklab, white 97%, var(--checker-tone) 3%),
      color-mix(in oklab, white 99%, var(--checker-tone) 1%)
    ) !important;
}
</style>
VUE;
    $notes[] = 'key checker: Verification result converted to calm boxed panel';
    $notes[] = 'key checker: Recent requests hover background removed completely';
}

$files['checker'] = $checker;

/* ==========================================================================
 * 3) DASHBOARD USAGE REQUEST LOG — running spinner too
 * ======================================================================= */

$usage = $files['usage'];

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
        $notes[] = 'Usage: live request status gets rotating circle icon';
    } else {
        $warnings[] = 'Usage: could not locate request status markup for running spinner';
    }
}

$usage = preg_replace(
    '~\s*<span\s+class=["\']sp-request-status__label["\']>[\s\S]*?</span>~',
    '',
    $usage
) ?? $usage;

if (!str_contains($usage, 'V21.8 usage running status')) {
    $usage .= <<<'VUE'


<style scoped>
/* V21.8 usage running status */
.sp-request-status {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: .35rem;
  min-width: 3.9rem;
  box-shadow: none !important;
}

.sp-request-status__label {
  display: none !important;
}

.sp-request-status__spinner {
  flex: 0 0 auto;
}
</style>
VUE;
}

$files['usage'] = $usage;

/* ==========================================================================
 * WRITE
 * ======================================================================= */

$timestamp = date('Ymd-His');
$written = 0;

foreach ($paths as $name => $path) {
    if ($files[$name] === $original[$name]) {
        continue;
    }

    $backup = $path.'.bak-v21.8-'.$timestamp;
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

echo "\nSP Cambo V21.8 complete.\n";
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

echo "\nExpected UI:\n";
echo "  - hover backgrounds are almost invisible site-wide\n";
echo "  - Recent requests rows have zero hover background\n";
echo "  - Verification result is a clean bordered box, no glow\n";
echo "  - live/running request -> rotating circle + numeric status code\n";
echo "  - finished request -> numeric code only\n\n";

echo "Build:\n";
echo "  cd frontend\n";
echo "  rm -rf .nuxt .output\n";
echo "  pnpm typecheck\n";
echo "  pnpm build\n";
