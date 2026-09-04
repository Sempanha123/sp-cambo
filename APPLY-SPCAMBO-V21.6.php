<?php
declare(strict_types=1);

/**
 * SP Cambo V21.6
 *
 * Adaptive patch. It does NOT require the old V21.5 claim-key anchor.
 *
 * Changes:
 *  1. Global calm hover: remove hover glow/filter/transform across the site.
 *     Existing background/border hover feedback remains.
 *  2. Public key checker: a background auto-refresh 429 is silent and does NOT
 *     switch auto-refresh off. The last successful data remains on screen.
 *  3. Request/inference status UI: show numeric status code only on the known
 *     request surfaces (key checker, Usage, Playground) and on request-specific
 *     states in SpStatusBadge. Payment/order/account statuses are left semantic.
 *  4. Telegram announcement CTA: exact buyable package -> Store Bot deep link;
 *     unavailable/no package -> Store Bot home. URL buttons work in channels.
 *
 * Usage:
 *   php ./APPLY-SPCAMBO-V21.6.php
 */

$root = __DIR__;

$paths = [
    'main_css' => $root.'/frontend/app/assets/css/main.css',
    'checker' => $root.'/frontend/app/pages/public/key-checker.vue',
    'usage' => $root.'/frontend/app/pages/dashboard/usage.vue',
    'playground' => $root.'/frontend/app/pages/dashboard/playground.vue',
    'status_badge' => $root.'/frontend/app/components/SpStatusBadge.vue',
    'telegram' => $root.'/backend/app/Services/TelegramAnnouncementService.php',
];

foreach ($paths as $name => $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "ERROR: Missing {$name}: {$path}\n");
        fwrite(STDERR, "Put APPLY-SPCAMBO-V21.6.php in the SP Cambo project root.\n");
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
 * 1) GLOBAL CALM HOVER
 * ======================================================================= */

if (! str_contains($files['main_css'], 'SP CAMBO V21.6 — CALM GLOBAL INTERACTIONS')) {
    $files['main_css'] .= <<<'CSS'


/* ==========================================================================
   SP CAMBO V21.6 — CALM GLOBAL INTERACTIONS

   Hover is feedback, not illumination:
   - no hover bloom/glow
   - no hover lift/scale
   - no hover blur/filter
   - keep each component's existing small background/border/color change
   - do NOT remove :focus-visible accessibility treatment
   ========================================================================== */

@layer utilities {
  :root {
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

    :where(
      button,
      a[href],
      [role="button"],
      [role="radio"],
      [role="tab"],
      .sp-action-primary
    ):hover::before,
    :where(
      button,
      a[href],
      [role="button"],
      [role="radio"],
      [role="tab"],
      .sp-action-primary
    ):hover::after {
      filter: none !important;
      box-shadow: none !important;
      text-shadow: none !important;
    }
  }

  /* Explicitly neutralise older SP Cambo action-glow helpers. */
  .sp-action-primary,
  .sp-action-primary:hover,
  .sp-action-primary:active {
    box-shadow: none !important;
    filter: none !important;
    transform: none !important;
  }
}
CSS;
    $notes[] = 'global CSS: hover glow/lift/filter removed site-wide';
} else {
    $notes[] = 'global CSS: V21.6 calm-hover rules already present';
}

/* ==========================================================================
 * 2) KEY CHECKER — SILENT RATE-LIMIT DURING AUTO REFRESH
 * ======================================================================= */

$checker = $files['checker'];

$oldCatch = <<<'TS'
    const presentation = errorPresentation(error)
    if (mode === 'refresh' && keyStatus.value) {
      refreshWarning.value = `${presentation.title}: ${presentation.description}`
      if (toSpApiError(error).code === 'rate_limit_exceeded') autoRefresh.value = false
    } else {
      checkError.value = presentation
      sessionSecret.value = ''
    }
TS;

$newCatch = <<<'TS'
    const presentation = errorPresentation(error)
    if (mode === 'refresh' && keyStatus.value) {
      // Background refresh is intentionally quiet. A transient 429 or network
      // miss keeps the last successful snapshot on screen and leaves the timer
      // enabled so the next normal refresh can recover automatically.
      refreshWarning.value = ''
      autoRefresh.value = true
    } else {
      checkError.value = presentation
      sessionSecret.value = ''
    }
TS;

if (str_contains($checker, $oldCatch)) {
    $checker = str_replace($oldCatch, $newCatch, $checker);
    $notes[] = 'key checker: auto-refresh 429 no longer pauses refresh';
} elseif (str_contains($checker, 'Background refresh is intentionally quiet.')) {
    $notes[] = 'key checker: quiet auto-refresh logic already present';
} else {
    // Adaptive fallback: remove the line that disables auto refresh on 429.
    $before = $checker;
    $checker = preg_replace(
        '/^\s*if\s*\(\s*toSpApiError\(error\)\.code\s*===\s*[\'"]rate_limit_exceeded[\'"]\s*\)\s*autoRefresh\.value\s*=\s*false\s*;?\s*$/m',
        '',
        $checker
    ) ?? $checker;

    if ($checker !== $before) {
        $notes[] = 'key checker: removed local 429 auto-refresh shutdown using adaptive fallback';
    } else {
        $warnings[] = 'key checker: could not find the old 429 shutdown line; local file may already differ';
    }
}

/* Remove the "Showing the last successful result" refresh warning card. */
$before = $checker;
$checker = preg_replace(
    '~\s*<UAlert\s+v-if=["\']refreshWarning["\'][\s\S]*?title=["\']Showing the last successful result["\'][\s\S]*?/>\s*~',
    "\n",
    $checker,
    1
) ?? $checker;

if ($checker !== $before) {
    $notes[] = 'key checker: removed last-successful-result warning alert';
} elseif (! str_contains($checker, 'Showing the last successful result')) {
    $notes[] = 'key checker: refresh warning alert already absent';
} else {
    $warnings[] = 'key checker: refresh warning alert uses unexpected local markup';
}

/* Remove lifecycle word beneath numeric request code. */
$before = $checker;
$checker = preg_replace(
    '~\s*<span\s+class=["\']sp-request-status__label["\']>\s*\{\{\s*stateLabel\(request\.state\)\s*\}\}\s*</span>~',
    '',
    $checker
) ?? $checker;

if ($checker !== $before) {
    $notes[] = 'key checker: request status now numeric only';
} elseif (! str_contains($checker, 'stateLabel(request.state)')) {
    $notes[] = 'key checker: numeric-only request status already active';
} else {
    $warnings[] = 'key checker: request status label uses unexpected local markup';
}

/* Make the single numeric badge centered rather than a two-line badge. */
$checker .= str_contains($checker, 'V21.6 numeric-only request status')
    ? ''
    : <<<'VUE'


<style scoped>
/* V21.6 numeric-only request status */
.sp-checker-page .sp-request-status {
  min-width: 3.75rem;
  flex-direction: row;
  align-items: center;
  justify-content: center;
  gap: 0;
}
</style>
VUE;

$files['checker'] = $checker;

/* ==========================================================================
 * 3) DASHBOARD USAGE — NUMERIC REQUEST STATUS ONLY
 * ======================================================================= */

$usage = $files['usage'];

/* Remove internal-state tooltip, because hovering should not reveal SETTLED/etc. */
$usage = preg_replace(
    '/\s*:title=["\']`Internal state: \$\{stateLabel\(item\.state\)\}`["\']/',
    '',
    $usage
) ?? $usage;

/* Remove Success/Running/Finalizing/Failed text beside code. */
$before = $usage;
$usage = preg_replace(
    '~\s*<span\s+class=["\']sp-request-status__label["\']>\s*\{\{\s*requestResultLabel\(item\)\s*\}\}\s*</span>~',
    '',
    $usage
) ?? $usage;

if ($usage !== $before) {
    $notes[] = 'Usage: request lifecycle label removed; numeric code only';
} elseif (! str_contains($usage, 'requestResultLabel(item)')) {
    $notes[] = 'Usage: numeric-only request status already active';
} else {
    $warnings[] = 'Usage: local request-result markup differs; numeric label may need manual inspection';
}

/* Remove obvious customer-facing "settled" wording that is not needed. */
$usage = str_replace('hint="Actual settled charge"', 'hint="Actual charged amount"', $usage);
$usage = str_replace('Settled customer charge only.', 'Final customer charge only.', $usage);

$files['usage'] = $usage;

/* ==========================================================================
 * 4) PLAYGROUND — LAST REQUEST NUMERIC CODE ONLY
 * ======================================================================= */

$playground = $files['playground'];

$oldStateLines = <<<'TS'
const stateColor = (state: RequestActivity['state']) => state === 'settled' ? 'success' : ['failed', 'released'].includes(state) ? 'error' : state === 'reconciling' ? 'warning' : 'primary'
const stateLabel = (state: RequestActivity['state']) => state === 'reconciling' ? 'Billing pending' : state === 'settled' ? 'Settled' : state === 'released' ? 'Released' : state === 'failed' ? 'Failed' : state === 'streaming' ? 'Streaming' : state === 'connecting' ? 'Connecting' : 'Reserved'
TS;

$newStateLines = <<<'TS'
const requestStatusCode = (row: RequestActivity) => {
  const error = String(row.error_code ?? '').toLowerCase()
  const explicit = error.match(/(?:^|\D)([45]\d{2})(?:\D|$)/)?.[1]
  if (explicit) return explicit
  if (error.includes('timeout')) return '504'
  if (error.includes('rate') || error.includes('quota') || error.includes('too_many')) return '429'
  if (error.includes('unauthorized') || error.includes('authentication')) return '401'
  if (error.includes('forbidden') || error.includes('not_allowed')) return '403'
  if (error.includes('not_found')) return '404'
  if (row.state === 'settled') return '200'
  if (row.state === 'reconciling') return '202'
  if (['reserved', 'connecting', 'streaming'].includes(row.state)) return '102'
  return ['failed', 'released'].includes(row.state) ? '500' : '100'
}
const requestStatusTone = (row: RequestActivity) => {
  const code = Number(requestStatusCode(row))
  if (code >= 400) return 'error'
  if (code === 202) return 'warning'
  if (code >= 200) return 'success'
  return 'info'
}
TS;

if (str_contains($playground, $oldStateLines)) {
    $playground = str_replace($oldStateLines, $newStateLines, $playground);
    $notes[] = 'Playground: added numeric last-request status mapping';
} elseif (str_contains($playground, 'const requestStatusCode = (row: RequestActivity) =>')) {
    $notes[] = 'Playground: numeric status mapping already present';
} else {
    // Adaptive regex for locally reformatted one-line helpers.
    $candidate = preg_replace(
        '/^const stateColor = .*?\Rconst stateLabel = .*?\R/m',
        $newStateLines."\n",
        $playground,
        1,
        $count
    );
    if ($candidate !== null && $count > 0) {
        $playground = $candidate;
        $notes[] = 'Playground: replaced local state helpers using adaptive matcher';
    } else {
        $warnings[] = 'Playground: could not locate stateColor/stateLabel helper pair';
    }
}

$oldBadge = '<UBadge :color="stateColor(lastRequest.state)" variant="subtle" size="sm">{{ stateLabel(lastRequest.state) }}</UBadge>';
$newBadge = '<UBadge :color="requestStatusTone(lastRequest)" variant="subtle" size="sm" class="sp-numeric font-mono">{{ requestStatusCode(lastRequest) }}</UBadge>';

if (str_contains($playground, $oldBadge)) {
    $playground = str_replace($oldBadge, $newBadge, $playground);
    $notes[] = 'Playground: Last request badge is numeric only';
} elseif (str_contains($playground, 'requestStatusCode(lastRequest)')) {
    $notes[] = 'Playground: Last request numeric badge already present';
} else {
    $candidate = preg_replace(
        '~<UBadge\b(?=[^>]*stateColor\(lastRequest\.state\))[^>]*>\s*\{\{\s*stateLabel\(lastRequest\.state\)\s*\}\}\s*</UBadge>~',
        $newBadge,
        $playground,
        1,
        $count
    );
    if ($candidate !== null && $count > 0) {
        $playground = $candidate;
        $notes[] = 'Playground: numeric Last request badge applied with adaptive matcher';
    } else {
        $warnings[] = 'Playground: Last request badge uses unexpected local markup';
    }
}

$files['playground'] = $playground;

/* ==========================================================================
 * 5) SHARED STATUS BADGE — REQUEST-SPECIFIC STATES ONLY
 *
 * We deliberately do NOT convert account/order/payment words such as ACTIVE,
 * PAID, FULFILLED or generic FAILED into fake HTTP codes.
 * ======================================================================= */

$statusBadge = $files['status_badge'];
$requestStatusReplacements = [
    "received: { color: 'info', label: 'Received' }" => "received: { color: 'info', label: '100' }",
    "reserved: { color: 'info', label: 'Reserved' }" => "reserved: { color: 'info', label: '102' }",
    "connecting: { color: 'info', label: 'Connecting' }" => "connecting: { color: 'info', label: '102' }",
    "streaming: { color: 'info', label: 'Streaming' }" => "streaming: { color: 'info', label: '102' }",
    "reconciling: { color: 'warning', label: 'Billing pending' }" => "reconciling: { color: 'warning', label: '202' }",
    "settled: { color: 'success', label: 'Settled' }" => "settled: { color: 'success', label: '200' }",
    "released: { color: 'neutral', label: 'Released' }" => "released: { color: 'error', label: '500' }",
];

$changedShared = 0;
foreach ($requestStatusReplacements as $from => $to) {
    if (str_contains($statusBadge, $from)) {
        $statusBadge = str_replace($from, $to, $statusBadge);
        $changedShared++;
    }
}

/* Numeric only: no spinner icon for the streaming lifecycle state. */
$statusBadge = str_replace(
    ':icon="isStreaming ? undefined : resolved.icon"',
    ':icon="resolved.icon"',
    $statusBadge
);
$statusBadge = preg_replace(
    '~\s*<UIcon\s+v-if=["\']isStreaming["\'][\s\S]*?aria-hidden=["\']true["\']\s*/>~',
    '',
    $statusBadge
) ?? $statusBadge;

if ($changedShared > 0) {
    $notes[] = "SpStatusBadge: {$changedShared} request-only lifecycle states converted to numbers";
} elseif (str_contains($statusBadge, "settled: { color: 'success', label: '200' }")) {
    $notes[] = 'SpStatusBadge: request lifecycle numbers already active';
} else {
    $warnings[] = 'SpStatusBadge: request lifecycle definitions differ from current GitHub main';
}

$files['status_badge'] = $statusBadge;

/* ==========================================================================
 * 6) TELEGRAM CHANNEL-SAFE CTA
 * ======================================================================= */

$telegram = $files['telegram'];

if (! str_contains($telegram, '$keyboard = $this->announcementKeyboard($announcement, $km);')) {
    $messagePos = strpos($telegram, 'private function message(');
    $start = $messagePos === false
        ? false
        : strpos($telegram, "        if (\$announcement->kind === 'PURCHASE_ACTIVITY') {", $messagePos);
    $end = $start === false
        ? false
        : strpos($telegram, '        $body = ', $start);

    if ($start !== false && $end !== false && $end > $start) {
        $telegram = substr($telegram, 0, $start)
            ."        \$keyboard = \$this->announcementKeyboard(\$announcement, \$km);\n\n"
            .substr($telegram, $end);
        $notes[] = 'Telegram: announcement keyboard switched to channel-safe CTA helper';
    } else {
        $warnings[] = 'Telegram: could not locate old inline keyboard block; helper call was not inserted';
    }
} else {
    $notes[] = 'Telegram: announcement keyboard helper already active';
}

if (! str_contains($telegram, 'private function announcementKeyboard(')) {
    $anchor = '    private function purchaseActivityBody(TelegramAnnouncement $announcement, bool $km): string';

    $helper = <<<'PHP'
    /**
     * Channel-safe announcement CTA.
     *
     * A published/buyable package opens that exact product in the Store Bot.
     * Missing, unpublished or non-buyable package opens the Store Bot home.
     *
     * URL buttons are required for channel posts. callback_data remains only as
     * a private-chat compatibility fallback when the bot username is not set.
     *
     * @return array<int,array<int,array<string,string>>>
     */
    private function announcementKeyboard(TelegramAnnouncement $announcement, bool $km): array
    {
        $username = ltrim(trim((string) config('services.telegram.bot_username')), '@');
        $package = null;

        if ($announcement->package_id !== null) {
            $package = Package::query()
                ->published()
                ->where('auto_creates_api_key', true)
                ->find($announcement->package_id);
        }

        if (! $package && $announcement->kind === 'PURCHASE_ACTIVITY') {
            $meta = is_array($announcement->metadata) ? $announcement->metadata : [];
            $slug = trim((string) ($meta['package_slug'] ?? ''));

            if ($slug !== '') {
                $package = Package::query()
                    ->published()
                    ->where('auto_creates_api_key', true)
                    ->where('slug', $slug)
                    ->first();
            }
        }

        $start = 'store';
        if ($package) {
            $safeSlug = (string) preg_replace('/[^a-z0-9_-]/i', '', (string) $package->slug);
            if ($safeSlug !== '') {
                $start = 'package_'.Str::limit($safeSlug, 48, '');
            }
        }

        if ($username !== '') {
            return [[[
                'text' => $package
                    ? ($km ? '🛒 ទិញកញ្ចប់នេះ' : '🛒 Buy this package')
                    : ($km ? '🤖 បើក SP Cambo Bot' : '🤖 Open SP Cambo Bot'),
                'url' => 'https://t.me/'.$username.'?start='.$start,
            ]]];
        }

        return [[[
            'text' => $package
                ? ($km ? '🛒 ទិញកញ្ចប់នេះ' : '🛒 Buy this package')
                : ($km ? '🛍️ បើកហាង' : '🛍️ Open Store'),
            'callback_data' => $package ? 'buy:'.$package->id : 'store:1',
        ]]];
    }

PHP;

    if (str_contains($telegram, $anchor)) {
        $telegram = str_replace($anchor, $helper.$anchor, $telegram);
        $notes[] = 'Telegram: exact-product / bot-home deep-link helper added';
    } else {
        $warnings[] = 'Telegram: purchaseActivityBody anchor not found; helper not inserted';
    }
} else {
    $notes[] = 'Telegram: CTA helper already present';
}

$files['telegram'] = $telegram;

/* ==========================================================================
 * WRITE ONLY FILES THAT ACTUALLY CHANGED
 * ======================================================================= */

$timestamp = date('Ymd-His');
$written = 0;

foreach ($paths as $name => $path) {
    if ($files[$name] === $original[$name]) {
        continue;
    }

    $backup = $path.'.bak-v21.6-'.$timestamp;
    if (! copy($path, $backup)) {
        fwrite(STDERR, "ERROR: Could not create backup for {$path}. Stopping before writing this file.\n");
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

echo "\nSP Cambo V21.6 complete.\n";
echo "Files changed: {$written}\n\n";

foreach ($notes as $note) {
    echo "  + {$note}\n";
}

if ($warnings !== []) {
    echo "\nWARNINGS (patch continued; it did not abort):\n";
    foreach ($warnings as $warning) {
        echo "  ! {$warning}\n";
    }
}

echo "\nExpected customer behavior:\n";
echo "  - no strong hover glow/lift site-wide\n";
echo "  - checker background 429 stays silent and auto-refresh remains enabled\n";
echo "  - request lifecycle surfaces show 100/102/200/202/4xx/5xx only\n";
echo "  - Telegram product alert opens exact product when buyable\n";
echo "  - no product -> opens SP Cambo Store Bot\n\n";

echo "Validate frontend:\n";
echo "  cd frontend\n";
echo "  rm -rf .nuxt .output\n";
echo "  pnpm typecheck\n";
echo "  pnpm build\n\n";

echo "Validate backend:\n";
echo "  cd ../backend\n";
echo "  php -l app/Services/TelegramAnnouncementService.php\n";
echo "  php artisan optimize:clear\n";
echo "  php artisan test --filter=Telegram\n\n";

echo "For Telegram channel URL buttons, .env must contain:\n";
echo "  TELEGRAM_STOREFRONT_BOT_USERNAME=YourBotUsername\n";
