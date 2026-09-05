<?php
declare(strict_types=1);

/**
 * SP Cambo V22.2 — Silent API Key Checker rate-limit handling
 *
 * Goal:
 * - NEVER show:
 *   "This key could not be verified"
 *   "Too many checks: The checker allows 10 requests per minute..."
 *   for rate_limit_exceeded.
 * - Keep the backend rate limit itself for protection.
 * - Keep the last successful result on screen.
 * - If a manual check hits 429 before a result exists, silently keep the
 *   credential in memory and retry through normal auto-refresh.
 *
 * Run from SP Cambo project root:
 *   php ./APPLY-SPCAMBO-KEYCHECKER-SILENT-429-V22.2.php
 */

$root = __DIR__;
$path = $root.'/frontend/app/pages/public/key-checker.vue';

if (!is_file($path)) {
    fwrite(STDERR, "ERROR: Missing {$path}\n");
    fwrite(STDERR, "Put this installer in the SP Cambo project root.\n");
    exit(1);
}

$src = (string) file_get_contents($path);
$original = $src;
$notes = [];

/* --------------------------------------------------------------------------
 * Insert a special silent 429 branch at the top of performCheck catch().
 * ----------------------------------------------------------------------- */

if (!str_contains($src, 'V22.2 silent checker rate limit')) {
    $old = <<<'TS'
  } catch (error) {
    if (version !== requestVersion) return

    const presentation = errorPresentation(error)
TS;

    $new = <<<'TS'
  } catch (error) {
    if (version !== requestVersion) return

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

    const presentation = errorPresentation(error)
TS;

    if (str_contains($src, $old)) {
        $src = str_replace($old, $new, $src);
        $notes[] = 'Inserted silent 429 recovery in performCheck()';
    } else {
        fwrite(STDERR, "ERROR: Could not find performCheck catch anchor. No file changed.\n");
        exit(1);
    }
} else {
    $notes[] = 'Silent 429 recovery already present';
}

/* --------------------------------------------------------------------------
 * Remove the visible rate-limit wording from errorPresentation so a future
 * refactor cannot accidentally show it through that helper.
 * ----------------------------------------------------------------------- */

$rateBlock = <<<'TS'
  if (spError.code === 'rate_limit_exceeded') {
    return {
      title: 'Too many checks',
      description: 'The checker allows 10 requests per minute. Wait a moment, then try again.',
      retryable: true
    }
  }
TS;

if (str_contains($src, $rateBlock)) {
    $src = str_replace($rateBlock, '', $src);
    $notes[] = 'Removed visible Too many checks presentation text';
}

/* --------------------------------------------------------------------------
 * Defensive cleanup: if the exact old phrases exist elsewhere in the template,
 * remove them so they cannot be rendered as static content.
 * ----------------------------------------------------------------------- */
$src = str_replace(
    [
        'This key could not be verified',
        'Too many checks: The checker allows 10 requests per minute. Wait a moment, then try again.',
        'The checker allows 10 requests per minute. Wait a moment, then try again.',
    ],
    [
        'Verification unavailable',
        '',
        '',
    ],
    $src
);

if ($src === $original) {
    echo "Nothing to change. V22.2 already appears applied.\n";
    exit(0);
}

$backup = $path.'.bak-v22.2-'.date('Ymd-His');
if (!copy($path, $backup)) {
    fwrite(STDERR, "ERROR: Could not create backup {$backup}\n");
    exit(1);
}

if (file_put_contents($path, $src) === false) {
    @copy($backup, $path);
    fwrite(STDERR, "ERROR: Could not write {$path}; backup restored.\n");
    exit(1);
}

echo "UPDATED: {$path}\n";
echo "BACKUP : {$backup}\n\n";

foreach ($notes as $note) {
    echo "  + {$note}\n";
}

echo "\nExpected behavior:\n";
echo "  429 checker rate limit -> silent\n";
echo "  No 'This key could not be verified' for 429\n";
echo "  No 'Too many checks...' message\n";
echo "  Last successful result stays visible\n";
echo "  Auto refresh keeps/restarts and retries normally\n";
echo "  Real invalid/revoked/not-found key errors still display\n\n";

echo "Build:\n";
echo "  cd frontend\n";
echo "  rm -rf .nuxt .output\n";
echo "  pnpm typecheck\n";
echo "  pnpm build\n";
