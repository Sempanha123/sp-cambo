<?php
declare(strict_types=1);

/**
 * SP Cambo V22.3 — Telegram History callback hotfix
 *
 * Fixes the V22.2 symptom:
 *   "This button is no longer available."
 * when tapping an order in History.
 *
 * Cause: V22.2 can successfully render history:order:* buttons while an older
 * TelegramWebhookController still has no matching callback route, so the
 * controller falls through to its legacy final fallback toast.
 */

$root=__DIR__;
$hookPath=$root.'/backend/app/Http/Controllers/Api/V1/TelegramWebhookController.php';
$uiPath=$root.'/backend/app/Services/TelegramStorefrontUiService.php';

foreach([$hookPath,$uiPath] as $path){
    if(!is_file($path)){fwrite(STDERR,"ERROR: Missing {$path}\n");exit(1);}
}

$hook=(string)file_get_contents($hookPath);
$ui=(string)file_get_contents($uiPath);

if(!str_contains($ui,'public function sendOrderDetail(')){
    fwrite(STDERR,"ERROR: sendOrderDetail() is missing. Apply V22.2 first, then run V22.3.\n");
    exit(1);
}

if(str_contains($hook,"str_starts_with(\$data, 'history:order:')")){
    echo "OK: History order callback route already exists. No source change needed.\n";
    echo "If Telegram still shows the old toast, reload PHP-FPM/opcache.\n";
    exit(0);
}

$anchor=<<<'PHP'
        if ($data === 'history'
            || $data === 'keys'
            || $data === 'orders'
            || $data === 'orders:completed'
            || $data === 'orders:pending') {
PHP;

$insert=<<<'PHP'
        // V22.3: Order detail must be checked before the generic History route.
        // callback_data example: history:order:<order-ulid>:1
        if (str_starts_with($data, 'history:order:')) {
            $payload = substr($data, strlen('history:order:'));
            [$orderId, $pageValue] = array_pad(explode(':', $payload, 2), 2, '1');

            if ($orderId === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderId) !== 1) {
                throw new RuntimeException('That order button is invalid.');
            }

            $ui->sendOrderDetail(
                $account,
                $orderId,
                $messageId,
                max(1, (int) $pageValue),
            );
            $ack($bot, $callbackId);
            return;
        }

        // V22.3: History pagination, e.g. history:2
        if (str_starts_with($data, 'history:')) {
            $pageValue = substr($data, strlen('history:'));
            $page = filter_var($pageValue, FILTER_VALIDATE_INT);
            $ui->sendHistory($account, $messageId, $page === false ? 1 : max(1, (int) $page));
            $ack($bot, $callbackId);
            return;
        }

PHP;

if(!str_contains($hook,$anchor)){
    // Adaptive fallback: insert before the legacy final "button unavailable" response.
    $fallback="        \$ack(\$bot, \$callbackId, 'This button is no longer available.');";
    if(!str_contains($hook,$fallback)){
        fwrite(STDERR,"ERROR: Could not find History callback block or final callback fallback. No file changed.\n");
        exit(1);
    }
    $hook=str_replace($fallback,$insert.$fallback,$hook);
    $mode='inserted before final callback fallback';
}else{
    $hook=str_replace($anchor,$insert.$anchor,$hook);
    $mode='inserted before generic History callback';
}

$stamp=date('Ymd-His');
$backup=$hookPath.'.bak-v22.3-'.$stamp;
if(!copy($hookPath,$backup)){fwrite(STDERR,"ERROR: Backup failed.\n");exit(1);}
if(file_put_contents($hookPath,$hook)===false){@copy($backup,$hookPath);fwrite(STDERR,"ERROR: Write failed; backup restored.\n");exit(1);}

echo "UPDATED: {$hookPath}\n";
echo "BACKUP : {$backup}\n";
echo "MODE   : {$mode}\n\n";
echo "✅ V22.3 fixed History order-detail + pagination callbacks.\n\n";
echo "Validate:\n";
echo "  cd backend\n";
echo "  php -l app/Http/Controllers/Api/V1/TelegramWebhookController.php\n";
echo "  php -l app/Services/TelegramStorefrontUiService.php\n";
echo "  php artisan optimize:clear\n";
