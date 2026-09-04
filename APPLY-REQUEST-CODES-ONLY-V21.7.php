<?php
declare(strict_types=1);

/**
 * SP Cambo V21.7 — request status codes only
 *
 * Fixes:
 * - Removes customer-facing SETTLED / RELEASED / RUNNING / SUCCESS text from
 *   inference-request status surfaces.
 * - RELEASED is NOT automatically treated as HTTP 500.
 * - If a released request has no real error_code, customer-facing result = 200.
 * - If a real error_code exists, it is mapped to 4xx/5xx when identifiable.
 * - Adds a CSS fail-safe that hides old .sp-request-status__label text even if
 *   a locally customized template still contains it.
 *
 * This does NOT alter reservation/billing database states. Internal states such
 * as SETTLED/RELEASED remain available to the backend.
 *
 * Run from SP Cambo project root:
 *   php ./APPLY-REQUEST-CODES-ONLY-V21.7.php
 */

$root = __DIR__;

$paths = [
    'main_css' => $root.'/frontend/app/assets/css/main.css',
    'usage' => $root.'/frontend/app/pages/dashboard/usage.vue',
    'checker' => $root.'/frontend/app/pages/public/key-checker.vue',
    'playground' => $root.'/frontend/app/pages/dashboard/playground.vue',
    'badge' => $root.'/frontend/app/components/SpStatusBadge.vue',
];

foreach ($paths as $name => $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "ERROR: Missing {$name}: {$path}\n");
        fwrite(STDERR, "Place this installer in the SP Cambo project root.\n");
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

/* --------------------------------------------------------------------------
 * Shared request-code policy
 *
 * Key rule:
 *   RELEASED + no error_code = 200
 *
 * A reservation can be released because no final billable usage record was
 * written; that lifecycle state alone is not proof of an HTTP/server failure.
 * ----------------------------------------------------------------------- */

$usageCode = <<<'TS'
const requestResultCode = (item: RequestActivity) => {
  const rawError = String(item.error_code ?? '').trim()
  const error = rawError.toLowerCase()

  // Preserve an explicit HTTP-style error code when the backend recorded one.
  const explicitCode = rawError.match(/(?:^|\D)([45]\d{2})(?:\D|$)/)?.[1]
  if (explicitCode) return explicitCode

  // Benign client-side stop/close signals are not server failures.
  if (
    error.includes('client_closed')
    || error.includes('client_disconnected')
    || error.includes('client_disconnect')
    || error.includes('client_aborted')
    || error.includes('client_cancelled')
    || error.includes('client_canceled')
  ) return '200'

  if (error.includes('timeout')) return '504'
  if (error.includes('rate') || error.includes('quota') || error.includes('too_many')) return '429'
  if (error.includes('unauthorized') || error.includes('authentication')) return '401'
  if (error.includes('forbidden') || error.includes('not_allowed')) return '403'
  if (error.includes('not_found')) return '404'

  // A real recorded error remains an error even if the reservation was released.
  if (rawError !== '') return '500'

  switch (item.state) {
    case 'settled':
    case 'released':
      return '200'
    case 'reconciling':
      return '202'
    case 'streaming':
    case 'connecting':
    case 'reserved':
      return '102'
    case 'received':
      return '100'
    case 'failed':
      return '500'
    default:
      return '200'
  }
}
TS;

$checkerCode = <<<'TS'
const requestStatusCode = (request: NonNullable<PublicApiKeyStatus['recent_requests']>[number]) => {
  const rawError = String(request.error_code ?? '').trim()
  const error = rawError.toLowerCase()

  const explicitCode = rawError.match(/(?:^|\D)([45]\d{2})(?:\D|$)/)?.[1]
  if (explicitCode) return explicitCode

  if (
    error.includes('client_closed')
    || error.includes('client_disconnected')
    || error.includes('client_disconnect')
    || error.includes('client_aborted')
    || error.includes('client_cancelled')
    || error.includes('client_canceled')
  ) return '200'

  if (error.includes('timeout')) return '504'
  if (error.includes('rate') || error.includes('quota') || error.includes('too_many')) return '429'
  if (error.includes('unauthorized') || error.includes('authentication')) return '401'
  if (error.includes('forbidden') || error.includes('not_allowed')) return '403'
  if (error.includes('not_found')) return '404'
  if (rawError !== '') return '500'

  if (request.state === 'settled' || request.state === 'released') return '200'
  if (request.state === 'reconciling') return '202'
  if (request.state === 'streaming' || request.state === 'reserved' || request.state === 'connecting') return '102'
  if (request.state === 'received') return '100'
  if (request.state === 'failed') return '500'

  return request.status === 'error' ? '500' : '200'
}
TS;

$playgroundCode = <<<'TS'
const requestStatusCode = (row: RequestActivity) => {
  const rawError = String(row.error_code ?? '').trim()
  const error = rawError.toLowerCase()

  const explicitCode = rawError.match(/(?:^|\D)([45]\d{2})(?:\D|$)/)?.[1]
  if (explicitCode) return explicitCode

  if (
    error.includes('client_closed')
    || error.includes('client_disconnected')
    || error.includes('client_disconnect')
    || error.includes('client_aborted')
    || error.includes('client_cancelled')
    || error.includes('client_canceled')
  ) return '200'

  if (error.includes('timeout')) return '504'
  if (error.includes('rate') || error.includes('quota') || error.includes('too_many')) return '429'
  if (error.includes('unauthorized') || error.includes('authentication')) return '401'
  if (error.includes('forbidden') || error.includes('not_allowed')) return '403'
  if (error.includes('not_found')) return '404'
  if (rawError !== '') return '500'

  if (row.state === 'settled' || row.state === 'released') return '200'
  if (row.state === 'reconciling') return '202'
  if (['reserved', 'connecting', 'streaming'].includes(row.state)) return '102'
  if (row.state === 'failed') return '500'
  return '100'
}

const requestStatusTone = (row: RequestActivity) => {
  const code = Number(requestStatusCode(row))
  if (code >= 400) return 'error'
  if (code === 202) return 'warning'
  if (code >= 200) return 'success'
  return 'info'
}
TS;

/* ==========================================================================
 * 1) Usage page
 * ======================================================================= */

$usage = $files['usage'];

$pattern = '~const requestResultCode = \(item: RequestActivity\) => \{[\s\S]*?\n\}\n\nconst requestResultTone =~';
if (preg_match($pattern, $usage)) {
    $usage = preg_replace(
        $pattern,
        $usageCode."\n\nconst requestResultTone =",
        $usage,
        1
    ) ?? $usage;
    $notes[] = 'Usage: replaced request result-code policy';
} else {
    $warnings[] = 'Usage: requestResultCode block not found; local file may already differ';
}

/* Remove customer-facing lifecycle/semantic label beside numeric code. */
$before = $usage;
$usage = preg_replace(
    '~\s*<span\s+class=["\']sp-request-status__label["\']>\s*\{\{\s*requestResultLabel\(item\)\s*\}\}\s*</span>~',
    '',
    $usage
) ?? $usage;
if ($usage !== $before) {
    $notes[] = 'Usage: removed Success/Running/Finalizing/Failed label text';
}

/* Remove any lifecycle tooltip. */
$usage = preg_replace(
    '/\s*:title=["\']`Internal state: \$\{stateLabel\(item\.state\)\}`["\']/',
    '',
    $usage
) ?? $usage;

/* Remove helper that only exists to produce customer-facing words. */
$usage = preg_replace(
    '~\nconst requestResultLabel = \(item: RequestActivity\) => \{[\s\S]*?\n\}\n(?=\nconst stateLabel|\n</script>)~',
    "\n",
    $usage,
    1
) ?? $usage;

$files['usage'] = $usage;

/* ==========================================================================
 * 2) Public key checker
 * ======================================================================= */

$checker = $files['checker'];

$pattern = '~const requestStatusCode = \(request: NonNullable<PublicApiKeyStatus\[\'recent_requests\'\]>\[number\]\) => \{[\s\S]*?\n\}\n\nconst requestStatusTone =~';
if (preg_match($pattern, $checker)) {
    $checker = preg_replace(
        $pattern,
        $checkerCode."\n\nconst requestStatusTone =",
        $checker,
        1
    ) ?? $checker;
    $notes[] = 'Key checker: replaced request result-code policy';
} else {
    $warnings[] = 'Key checker: requestStatusCode block not found; local file may already differ';
}

$before = $checker;
$checker = preg_replace(
    '~\s*<span\s+class=["\']sp-request-status__label["\']>\s*\{\{\s*stateLabel\(request\.state\)\s*\}\}\s*</span>~',
    '',
    $checker
) ?? $checker;
if ($checker !== $before) {
    $notes[] = 'Key checker: removed SETTLED/RELEASED/etc label under code';
}

$files['checker'] = $checker;

/* ==========================================================================
 * 3) Playground
 * ======================================================================= */

$playground = $files['playground'];

/* V21.6-style existing numeric helper. */
$pattern = '~const requestStatusCode = \(row: RequestActivity\) => \{[\s\S]*?\n\}\nconst requestStatusTone = \(row: RequestActivity\) => \{[\s\S]*?\n\}~';
if (preg_match($pattern, $playground)) {
    $playground = preg_replace($pattern, $playgroundCode, $playground, 1) ?? $playground;
    $notes[] = 'Playground: updated numeric status policy';
} else {
    /* Original stateColor/stateLabel pair. */
    $patternOld = '~const stateColor = \(state: RequestActivity\[\'state\'\]\) => .*?\nconst stateLabel = \(state: RequestActivity\[\'state\'\]\) => .*?\n~';
    if (preg_match($patternOld, $playground)) {
        $playground = preg_replace($patternOld, $playgroundCode."\n", $playground, 1) ?? $playground;
        $notes[] = 'Playground: replaced lifecycle word helpers with numeric status helpers';
    } else {
        $warnings[] = 'Playground: status helper block not found';
    }
}

/* Replace old word badge or ensure existing numeric badge has no text label. */
$oldBadge = '<UBadge :color="stateColor(lastRequest.state)" variant="subtle" size="sm">{{ stateLabel(lastRequest.state) }}</UBadge>';
$newBadge = '<UBadge :color="requestStatusTone(lastRequest)" variant="subtle" size="sm" class="sp-numeric font-mono">{{ requestStatusCode(lastRequest) }}</UBadge>';
if (str_contains($playground, $oldBadge)) {
    $playground = str_replace($oldBadge, $newBadge, $playground);
    $notes[] = 'Playground: Last request now shows number only';
} elseif (! str_contains($playground, 'requestStatusCode(lastRequest)')) {
    $candidate = preg_replace(
        '~<UBadge\b(?=[^>]*stateColor\(lastRequest\.state\))[^>]*>\s*\{\{\s*stateLabel\(lastRequest\.state\)\s*\}\}\s*</UBadge>~',
        $newBadge,
        $playground,
        1,
        $count
    );
    if ($candidate !== null && $count > 0) {
        $playground = $candidate;
        $notes[] = 'Playground: Last request numeric badge applied adaptively';
    } else {
        $warnings[] = 'Playground: Last request badge not found';
    }
}

$files['playground'] = $playground;

/* ==========================================================================
 * 4) Shared SpStatusBadge request lifecycle fallback
 * ======================================================================= */

$badge = $files['badge'];

$map = [
    "received: { color: 'info', label: 'Received' }" => "received: { color: 'info', label: '100' }",
    "received: { color: 'info', label: '100' }" => "received: { color: 'info', label: '100' }",

    "reserved: { color: 'info', label: 'Reserved' }" => "reserved: { color: 'info', label: '102' }",
    "reserved: { color: 'info', label: '102' }" => "reserved: { color: 'info', label: '102' }",

    "connecting: { color: 'info', label: 'Connecting' }" => "connecting: { color: 'info', label: '102' }",
    "connecting: { color: 'info', label: '102' }" => "connecting: { color: 'info', label: '102' }",

    "streaming: { color: 'info', label: 'Streaming' }" => "streaming: { color: 'info', label: '102' }",
    "streaming: { color: 'info', label: '102' }" => "streaming: { color: 'info', label: '102' }",

    "reconciling: { color: 'warning', label: 'Billing pending' }" => "reconciling: { color: 'warning', label: '202' }",
    "reconciling: { color: 'warning', label: '202' }" => "reconciling: { color: 'warning', label: '202' }",

    "settled: { color: 'success', label: 'Settled' }" => "settled: { color: 'success', label: '200' }",
    "settled: { color: 'success', label: '200' }" => "settled: { color: 'success', label: '200' }",

    // RELEASED is a billing lifecycle state, not proof of a server error.
    "released: { color: 'neutral', label: 'Released' }" => "released: { color: 'success', label: '200' }",
    "released: { color: 'error', label: '500' }" => "released: { color: 'success', label: '200' }",
    "released: { color: 'success', label: '200' }" => "released: { color: 'success', label: '200' }",
];

foreach ($map as $from => $to) {
    $badge = str_replace($from, $to, $badge);
}

$files['badge'] = $badge;
$notes[] = 'SpStatusBadge: request lifecycle fallback uses numeric codes; released -> 200';

/* ==========================================================================
 * 5) CSS fail-safe
 *
 * Even if a local template still contains the old second line, hide it globally.
 * This selector is request-status-specific and does not affect order/payment badges.
 * ======================================================================= */

$css = $files['main_css'];
if (! str_contains($css, 'SP CAMBO V21.7 — REQUEST CODES ONLY')) {
    $css .= <<<'CSS'


/* ==========================================================================
   SP CAMBO V21.7 — REQUEST CODES ONLY
   Customer inference-request badges display the numeric result only.
   Backend lifecycle states remain unchanged.
   ========================================================================== */

.sp-request-status__label {
  display: none !important;
}

.sp-request-status {
  min-width: 3.65rem !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 0 !important;
}

.sp-request-status__code {
  font-variant-numeric: tabular-nums;
  font-family: var(--font-mono);
}
CSS;
    $notes[] = 'Global CSS: added fail-safe to hide old request lifecycle label text';
}

$files['main_css'] = $css;

/* ==========================================================================
 * WRITE CHANGES
 * ======================================================================= */

$timestamp = date('Ymd-His');
$written = 0;

foreach ($paths as $name => $path) {
    if ($files[$name] === $original[$name]) {
        continue;
    }

    $backup = $path.'.bak-v21.7-'.$timestamp;
    if (! copy($path, $backup)) {
        fwrite(STDERR, "ERROR: Could not back up {$path}\n");
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

echo "\nSP Cambo V21.7 complete.\n";
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

echo "\nExpected request status display:\n";
echo "  settled                  -> 200\n";
echo "  released + no error      -> 200\n";
echo "  benign client close      -> 200\n";
echo "  reserved/connecting/live -> 102\n";
echo "  reconciling              -> 202\n";
echo "  timeout                   -> 504\n";
echo "  rate/quota                -> 429\n";
echo "  explicit real error       -> 4xx/5xx or 500\n";
echo "  failed                    -> 500\n";
echo "\nNo customer-facing SETTLED / RELEASED line should remain in request badges.\n";
echo "Internal database lifecycle states are NOT changed.\n\n";

echo "Validate:\n";
echo "  cd frontend\n";
echo "  rm -rf .nuxt .output\n";
echo "  pnpm typecheck\n";
echo "  pnpm build\n";
