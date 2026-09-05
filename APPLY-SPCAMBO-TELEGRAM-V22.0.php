<?php
declare(strict_types=1);

/**
 * SP Cambo V22.0 — Telegram cute messages + channel CTA
 *
 * Fixes:
 * 1) Channel alerts now carry inline URL buttons.
 * 2) Verified purchase channel alert opens the exact package when buyable.
 * 3) If no buyable package exists, alert opens the SP Cambo Store Bot.
 * 4) Purchaser does NOT receive their own public purchase-activity DM.
 *    Other enabled bot subscribers still receive it.
 * 5) Payment-success/API-key delivery is cleaner and cuter.
 * 6) Model-created/model-updated notifications become short + cute.
 *
 * This is an adaptive source patch for the current SP Cambo main layout.
 *
 * Apply from project root:
 *   php ./APPLY-SPCAMBO-TELEGRAM-V22.0.php
 */

$root = __DIR__;

$paths = [
    'channel_job' => $root.'/backend/app/Jobs/SendTelegramAlertChannelMessage.php',
    'router' => $root.'/backend/app/Services/TelegramNotificationRouter.php',
    'announcement' => $root.'/backend/app/Services/TelegramAnnouncementService.php',
    'purchase_alert' => $root.'/backend/app/Services/TelegramPurchaseAlertService.php',
    'commerce' => $root.'/backend/app/Services/TelegramCommerceService.php',
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
 * 1) CHANNEL JOB — support reply_markup
 * ======================================================================= */

$job = $files['channel_job'];

if (!str_contains($job, 'public array $replyMarkup = [];')) {
    $oldCtor = <<<'PHP'
    public function __construct(
        public readonly int $channelId,
        public readonly string $text,
    ) {}
PHP;

    $newCtor = <<<'PHP'
    /**
     * Keep a declared default so already-queued jobs created before V22.0 can
     * unserialize safely without having this property in their old payload.
     *
     * @var array<string,mixed>
     */
    public array $replyMarkup = [];

    /** @param array<string,mixed> $replyMarkup */
    public function __construct(
        public readonly int $channelId,
        public readonly string $text,
        array $replyMarkup = [],
    ) {
        $this->replyMarkup = $replyMarkup;
    }
PHP;

    if (str_contains($job, $oldCtor)) {
        $job = str_replace($oldCtor, $newCtor, $job);
        $notes[] = 'channel job: added reply_markup payload';
    } else {
        $warnings[] = 'channel job: constructor differs from expected current main';
    }
}

$oldSend = '$bot->sendMessage((string) $channel->chat_id, mb_substr($this->text, 0, 4000));';
$newSend = '$bot->sendMessage((string) $channel->chat_id, mb_substr($this->text, 0, 4000), $this->replyMarkup);';

if (str_contains($job, $oldSend)) {
    $job = str_replace($oldSend, $newSend, $job);
    $notes[] = 'channel job: Telegram sendMessage now includes inline keyboard';
} elseif (str_contains($job, $newSend)) {
    $notes[] = 'channel job: reply_markup send already active';
} else {
    $warnings[] = 'channel job: sendMessage anchor not found';
}

$files['channel_job'] = $job;

/* ==========================================================================
 * 2) NOTIFICATION ROUTER — channel button + cute channel messages
 * ======================================================================= */

$router = $files['router'];

if (!str_contains($router, 'use App\\Models\\Package;')) {
    $router = str_replace(
        'use App\\Models\\TelegramNotificationSetting;',
        "use App\\Models\\TelegramNotificationSetting;\nuse App\\Models\\Package;\nuse App\\Models\\ModelAlias;",
        $router
    );
}

/* Pass button markup to channel dispatcher. */
$oldRoute = '$this->dispatchToChannels($this->announcementText($announcement));';
$newRoute = '$this->dispatchToChannels($this->announcementText($announcement), $this->announcementKeyboard($announcement));';

if (str_contains($router, $oldRoute)) {
    $router = str_replace($oldRoute, $newRoute, $router);
    $notes[] = 'router: automatic channel announcement now includes CTA keyboard';
} elseif (str_contains($router, $newRoute)) {
    $notes[] = 'router: channel CTA routing already active';
} else {
    $warnings[] = 'router: automatic dispatchToChannels call not found';
}

/* Keep manual channel sender valid with optional keyboard. */
$oldSig = 'private function dispatchToChannels(string $text): int';
$newSig = 'private function dispatchToChannels(string $text, array $replyMarkup = []): int';

if (str_contains($router, $oldSig)) {
    $router = str_replace($oldSig, $newSig, $router);
}

$oldDispatch = <<<'PHP'
            SendTelegramAlertChannelMessage::dispatch(
                (int) $channel->id,
                mb_substr($text, 0, 4000),
            )->afterCommit();
PHP;

$newDispatch = <<<'PHP'
            SendTelegramAlertChannelMessage::dispatch(
                (int) $channel->id,
                mb_substr($text, 0, 4000),
                $replyMarkup,
            )->afterCommit();
PHP;

if (str_contains($router, $oldDispatch)) {
    $router = str_replace($oldDispatch, $newDispatch, $router);
    $notes[] = 'router: forwards CTA keyboard into queued channel job';
} elseif (str_contains($router, '$replyMarkup,')) {
    $notes[] = 'router: queued channel markup already forwarded';
} else {
    $warnings[] = 'router: SendTelegramAlertChannelMessage dispatch block differs';
}

/* Add channel-safe keyboard helper. */
if (!str_contains($router, 'private function announcementKeyboard(TelegramAnnouncement $announcement): array')) {
    $anchor = '    private function announcementText(TelegramAnnouncement $announcement): string';

    $helper = <<<'PHP'
    /**
     * Channel-safe CTA. Telegram channels cannot use Store Bot callback_data
     * because that callback would be handled in the channel context. A URL
     * deep-link moves the customer into the private Store Bot instead.
     *
     * @return array<string,mixed>
     */
    private function announcementKeyboard(TelegramAnnouncement $announcement): array
    {
        $username = ltrim(trim((string) config('services.telegram.bot_username')), '@');

        if ($username === '') {
            return [];
        }

        $package = null;

        if ($announcement->package_id !== null) {
            $package = Package::query()
                ->published()
                ->where('auto_creates_api_key', true)
                ->find($announcement->package_id);
        }

        if (! $package && strtoupper((string) $announcement->kind) === 'PURCHASE_ACTIVITY') {
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
                $start = 'package_'.\Illuminate\Support\Str::limit($safeSlug, 48, '');
            }
        }

        return [
            'inline_keyboard' => [[[
                'text' => $package ? '🛒✨ Buy this package' : '🤖✨ Open SP Cambo Bot',
                'url' => 'https://t.me/'.$username.'?start='.$start,
            ]]],
        ];
    }

PHP;

    if (str_contains($router, $anchor)) {
        $router = str_replace($anchor, $helper.$anchor, $router);
        $notes[] = 'router: added exact-package / Store Bot URL CTA helper';
    } else {
        $warnings[] = 'router: announcementText anchor not found';
    }
}

/*
 * Replace announcementText with a compact renderer.
 * Keep package/promo bodies, but force purchase/model notifications short.
 */
$start = strpos($router, '    private function announcementText(TelegramAnnouncement $announcement): string');
if ($start !== false) {
    $braceStart = strpos($router, '{', $start);
    if ($braceStart !== false) {
        $depth = 0;
        $end = null;
        $len = strlen($router);
        for ($i = $braceStart; $i < $len; $i++) {
            $ch = $router[$i];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }

        if ($end !== null) {
            $newMethod = <<<'PHP'
    private function announcementText(TelegramAnnouncement $announcement): string
    {
        $kind = strtoupper((string) $announcement->kind);

        if ($kind === 'PURCHASE_ACTIVITY') {
            $meta = is_array($announcement->metadata) ? $announcement->metadata : [];

            return implode("\n", array_filter([
                '🎉🛍 NEW ORDER!',
                '',
                isset($meta['masked_customer']) ? '👤✨ '.$meta['masked_customer'].' grabbed a package!' : null,
                isset($meta['package_name']) ? '📦🎁 '.$meta['package_name'] : null,
                isset($meta['price'], $meta['currency']) ? '💵 '.$meta['price'].' '.$meta['currency'] : null,
                isset($meta['quota']) ? '🪙 '.$meta['quota'] : null,
                isset($meta['validity']) ? '⏳ '.$meta['validity'] : null,
                '',
                '✅🚀 Verified & delivered',
                '🛒✨ Want one too? Tap below!',
            ]));
        }

        if (in_array($kind, ['NEW_MODEL', 'MODEL_UPDATE'], true)) {
            $name = trim((string) $announcement->title);

            if ($announcement->model_alias_id !== null) {
                $alias = ModelAlias::query()->find($announcement->model_alias_id);
                if ($alias) {
                    $name = trim((string) ($alias->display_name ?: $alias->public_alias));
                }
            }

            if ($name === '') {
                $name = 'New AI model';
            }

            return implode("\n", [
                $kind === 'MODEL_UPDATE' ? '🧠✨ MODEL UPDATED' : '🧠✨ NEW MODEL',
                '',
                '🤖 '.$name,
                '✅ Ready on SP Cambo',
                '🛍✨ Tap below to view packages',
            ]);
        }

        $icon = match ($kind) {
            'NEW_PACKAGE' => '📦✨',
            'PACKAGE_UPDATE' => '📦🔄',
            'RESTOCK', 'STOCK_ADDED' => '📥✨',
            'PROMOTION' => '🏷🎉',
            default => '🔔✨',
        };

        return $icon.' SP CAMBO'
            ."\n\n".trim((string) $announcement->title)
            ."\n".trim((string) $announcement->body);
    }
PHP;

            $router = substr($router, 0, $start).$newMethod.substr($router, $end);
            $notes[] = 'router: purchase/model channel messages shortened + made cuter';
        } else {
            $warnings[] = 'router: could not find end of announcementText method';
        }
    }
}

$files['router'] = $router;

/* ==========================================================================
 * 3) PURCHASE ALERT SERVICE — exclude buyer from their own public DM
 * ======================================================================= */

$purchase = $files['purchase_alert'];

if (!str_contains($purchase, 'use App\\Models\\TelegramAccount;')) {
    $purchase = str_replace(
        'use App\\Models\\TelegramAnnouncement;',
        "use App\\Models\\TelegramAnnouncement;\nuse App\\Models\\TelegramAccount;",
        $purchase
    );
}

$oldWebsite = <<<'PHP'
            // Do not exclude the buyer. An enabled Store Bot subscriber should see
            // the same verified purchase activity as every other subscriber.
            $this->announcements->purchaseActivity($order);
PHP;

$newWebsite = <<<'PHP'
            // The buyer already receives the private verified-payment delivery.
            // Exclude only that buyer from the public subscriber activity DM.
            // Everyone else who enabled announcements still receives it.
            $buyer = TelegramAccount::query()
                ->where('user_id', $order->user_id)
                ->whereNull('revoked_at')
                ->first();

            $this->announcements->purchaseActivity($order, $buyer);
PHP;

if (str_contains($purchase, $oldWebsite)) {
    $purchase = str_replace($oldWebsite, $newWebsite, $purchase);
    $notes[] = 'purchase alerts: linked buyer excluded from own public subscriber DM';
} elseif (str_contains($purchase, 'purchaseActivity($order, $buyer)')) {
    $notes[] = 'purchase alerts: website buyer exclusion already active';
} else {
    $warnings[] = 'purchase alerts: website orderFulfilled anchor differs';
}

$oldTelegram = '$this->announcements->purchaseActivity($order);';
$newTelegram = '$this->announcements->purchaseActivity($order, $purchase->account);';

/* Only replace inside telegramPurchaseDelivered after website block has changed. */
$telegramMethodPos = strpos($purchase, 'public function telegramPurchaseDelivered(');
if ($telegramMethodPos !== false) {
    $callPos = strpos($purchase, $oldTelegram, $telegramMethodPos);
    if ($callPos !== false) {
        $purchase = substr_replace($purchase, $newTelegram, $callPos, strlen($oldTelegram));
        $notes[] = 'purchase alerts: Telegram Store buyer excluded from own public subscriber DM';
    } elseif (strpos($purchase, $newTelegram, $telegramMethodPos) !== false) {
        $notes[] = 'purchase alerts: Telegram Store buyer exclusion already active';
    } else {
        $warnings[] = 'purchase alerts: telegramPurchaseDelivered call differs';
    }
}

$files['purchase_alert'] = $purchase;

/* ==========================================================================
 * 4) ANNOUNCEMENT SERVICE — short cute model DM for bot subscribers
 * ======================================================================= */

$announcement = $files['announcement'];

$oldBody = <<<'PHP'
        $body = $announcement->kind === 'PURCHASE_ACTIVITY'
            ? $this->purchaseActivityBody($announcement, $km)
            : $announcement->body;
PHP;

$newBody = <<<'PHP'
        $kind = strtoupper((string) $announcement->kind);
        $body = match ($kind) {
            'PURCHASE_ACTIVITY' => $this->purchaseActivityBody($announcement, $km),
            'NEW_MODEL', 'MODEL_UPDATE' => $this->cuteModelBody($announcement, $km),
            default => $announcement->body,
        };
PHP;

if (str_contains($announcement, $oldBody)) {
    $announcement = str_replace($oldBody, $newBody, $announcement);
    $notes[] = 'announcement bot DM: model alerts now use short cute renderer';
} elseif (str_contains($announcement, '$this->cuteModelBody($announcement, $km)')) {
    $notes[] = 'announcement bot DM: cute model renderer already active';
} else {
    $warnings[] = 'announcement service: message body switch differs';
}

if (!str_contains($announcement, 'private function cuteModelBody(')) {
    $anchor = '    private function purchaseActivityBody(TelegramAnnouncement $announcement, bool $km): string';

    $helper = <<<'PHP'
    private function cuteModelBody(TelegramAnnouncement $announcement, bool $km): string
    {
        $name = trim((string) $announcement->title);

        if ($announcement->model_alias_id !== null) {
            $alias = ModelAlias::query()->find($announcement->model_alias_id);
            if ($alias) {
                $name = trim((string) ($alias->display_name ?: $alias->public_alias));
            }
        }

        if ($name === '') {
            $name = $km ? 'ម៉ូដែល AI ថ្មី' : 'New AI model';
        }

        $updated = strtoupper((string) $announcement->kind) === 'MODEL_UPDATE';

        return implode("\n", [
            $updated
                ? ($km ? '🧠✨ ម៉ូដែលបានអាប់ដេត' : '🧠✨ MODEL UPDATED')
                : ($km ? '🧠✨ ម៉ូដែលថ្មី' : '🧠✨ NEW MODEL'),
            '',
            '🤖 '.$name,
            $km ? '✅ រួចរាល់នៅ SP Cambo' : '✅ Ready on SP Cambo',
            $km ? '🛍✨ ចុចខាងក្រោមដើម្បីមើលកញ្ចប់' : '🛍✨ Tap below to view packages',
        ]);
    }

PHP;

    if (str_contains($announcement, $anchor)) {
        $announcement = str_replace($anchor, $helper.$anchor, $announcement);
        $notes[] = 'announcement service: added cute model message helper';
    } else {
        $warnings[] = 'announcement service: purchaseActivityBody anchor not found';
    }
}

$files['announcement'] = $announcement;

/* ==========================================================================
 * 5) COMMERCE SERVICE — clean/cute private payment-success delivery
 * ======================================================================= */

$commerce = $files['commerce'];

$successNeedle = $commerce;
$paymentTextPos = strpos($commerce, "'✅ PAYMENT SUCCESSFUL'");
if ($paymentTextPos !== false) {
    $sendStart = strrpos(substr($commerce, 0, $paymentTextPos), '$this->bot->sendMessage(');
    $keyboardStart = strpos($commerce, ']), [', $paymentTextPos);

    if ($sendStart !== false && $keyboardStart !== false) {
        /* Find start of implode array after sendMessage. */
        $arrayStart = strpos($commerce, 'implode(', $sendStart);

        if ($arrayStart !== false && $arrayStart < $keyboardStart) {
            $newArray = <<<'PHP'
implode("\n", array_filter([
            $km ? '✅✨ ការទូទាត់ជោគជ័យ' : '✅✨ PAYMENT SUCCESSFUL',
            $km ? '🎉 API access របស់អ្នករួចរាល់ហើយ!' : '🎉 Your SP Cambo API access is ready!',
            '',
            $km ? '🔑✨ API KEY' : '🔑✨ API KEY',
            $delivery['secret'],
            '',
            $km ? '🧠✨ ម៉ូដែលរបស់អ្នក' : '🧠✨ YOUR MODELS',
            implode("\n", array_map(static fn (string $alias): string => '• '.$alias, $aliases)),
            '',
            '🚀✨ QUICK START',
            '',
            '💻 Claude Code · Windows PowerShell',
            '$env:ANTHROPIC_BASE_URL="'.$anthropic.'"',
            '$env:ANTHROPIC_AUTH_TOKEN="'.$delivery['secret'].'"',
            '$env:ANTHROPIC_MODEL="'.$defaultModel.'"',
            'claude',
            '',
            '🐧 Claude Code · macOS / Linux',
            'export ANTHROPIC_BASE_URL="'.$anthropic.'"',
            'export ANTHROPIC_AUTH_TOKEN="'.$delivery['secret'].'"',
            'export ANTHROPIC_MODEL="'.$defaultModel.'"',
            'claude',
            '',
            '⚡ OpenAI / Codex',
            'Base: '.$openai,
            '',
            $km ? '🔒 រក្សា API key នេះជាឯកជន។' : '🔒 Keep this API key private.',
            $km ? '✨ រួចរាល់សម្រាប់ប្រើ!' : '✨ You’re ready to build!',
        ]))
PHP;

            /*
             * Replace only from "implode(...)" through the closing ")" that
             * immediately precedes the keyboard argument marker "]), [".
             *
             * Existing source has:
             *   sendMessage(chat, implode("\n", [...]), [
             */
            $replaceEnd = $keyboardStart + 2; // include "])" but not ", ["
            $commerce = substr($commerce, 0, $arrayStart)
                .$newArray
                .substr($commerce, $replaceEnd);

            $notes[] = 'commerce: payment-success/API-key delivery cleaned and made cuter';
        } else {
            $warnings[] = 'commerce: could not locate payment-success implode array';
        }
    } else {
        $warnings[] = 'commerce: payment-success sendMessage boundaries not found';
    }
} elseif (str_contains($commerce, '✨ You’re ready to build!')) {
    $notes[] = 'commerce: cute payment-success delivery already active';
} else {
    $warnings[] = 'commerce: PAYMENT SUCCESSFUL anchor not found';
}

$files['commerce'] = $commerce;

/* ==========================================================================
 * WRITE CHANGED FILES
 * ======================================================================= */

$timestamp = date('Ymd-His');
$written = 0;

foreach ($paths as $name => $path) {
    if ($files[$name] === $original[$name]) {
        continue;
    }

    $backup = $path.'.bak-v22.0-'.$timestamp;
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

echo "\nSP Cambo Telegram V22.0 complete.\n";
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

echo "\nExpected behavior:\n";
echo "  ✅ Channel verified order has [Buy this package] URL button.\n";
echo "  ✅ No buyable package -> [Open SP Cambo Bot] button.\n";
echo "  ✅ Buyer does not receive their own public purchase-activity DM.\n";
echo "  ✅ Other Bot subscribers still receive the public purchase alert.\n";
echo "  ✅ Channel broadcast still goes to enabled channels.\n";
echo "  ✅ Payment-success private message is cleaner/cuter.\n";
echo "  ✅ Model update/new-model alert is short/cute.\n\n";

echo "IMPORTANT .env:\n";
echo "  TELEGRAM_STOREFRONT_BOT_USERNAME=YourActualBotUsername\n";
echo "  (without @)\n\n";

echo "Validate:\n";
echo "  cd backend\n";
echo "  php -l app/Jobs/SendTelegramAlertChannelMessage.php\n";
echo "  php -l app/Services/TelegramNotificationRouter.php\n";
echo "  php -l app/Services/TelegramAnnouncementService.php\n";
echo "  php -l app/Services/TelegramPurchaseAlertService.php\n";
echo "  php -l app/Services/TelegramCommerceService.php\n";
echo "  php artisan optimize:clear\n";
echo "  php artisan test --filter=Telegram\n";
