<?php

namespace App\Services;

use App\Exceptions\InsufficientStoreBalanceException;
use App\Jobs\DeleteExpiredTelegramQrMessage;
use App\Models\Package;
use App\Models\StoreWalletTopup;
use App\Models\TelegramAccount;
use App\Models\TelegramPurchase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

trait TelegramCommerceWalletFeatures
{
    public function sendCompactHome(TelegramAccount $account): void
    {
        $this->removeLegacyReplyKeyboardOnce($account);

        $balance = $this->balanceSnapshot($account);
        $wallet = $this->storeWallet->summary($account->user);
        $walletText = $this->money($wallet['balance_minor'], $wallet['currency'], $wallet['exponent']);
        $km = $this->isKhmer($account);
        $name = trim((string) ($account->user?->name ?: $account->username ?: 'SP Cambo customer'));

        $text = $km
            ? "🤖✨ SP CAMBO AI STORE\n\n👋 សួស្តី {$name}\n👛 Wallet: {$walletText} · 🪙 {$balance['tokens']} Tokens\n💳 API Credit: {$balance['credit']}\n\nជ្រើសរើសមុខងារខាងក្រោម 👇"
            : "🤖✨ SP CAMBO AI STORE\n\n👋 Welcome {$name}\n👛 Wallet: {$walletText} · 🪙 {$balance['tokens']} Tokens\n💳 API Credit: {$balance['credit']}\n\nChoose an action below 👇";

        // Home is the only screen that shows the complete navigation menu.
        // Inline buttons stay attached to this message instead of occupying the
        // Telegram composer area while the customer is browsing or checking out.
        $this->bot->sendMessage($account->chat_id, $text, [
            'inline_keyboard' => [
                [
                    ['text' => $km ? '🛍 ទិញ' : '🛍 Buy', 'callback_data' => 'store:1'],
                    ['text' => '👛 Wallet', 'callback_data' => 'wallet'],
                    ['text' => $km ? '💰 សមតុល្យ' : '💰 Balance', 'callback_data' => 'balance'],
                ],
                [
                    ['text' => '🔑 API Keys', 'callback_data' => 'keys'],
                    ['text' => $km ? '🧾 ការបញ្ជាទិញ' : '🧾 Orders', 'callback_data' => 'orders'],
                    ['text' => $km ? '🧠 ម៉ូដែល' : '🧠 Models', 'callback_data' => 'models'],
                ],
                [
                    ['text' => $km ? '🌐 ភាសា' : '🌐 Language', 'callback_data' => 'language'],
                    ['text' => $km ? '📞 ជំនួយ' : '📞 Support', 'callback_data' => 'support'],
                ],
            ],
        ]);
    }

    /**
     * Remove the old persistent ReplyKeyboard once per account after upgrading
     * to the message-attached inline Home menu. The tiny cleanup message is
     * immediately deleted, so users do not keep a permanent bottom keyboard.
     */
    private function removeLegacyReplyKeyboardOnce(TelegramAccount $account): void
    {
        $cacheKey = 'telegram:inline-home:reply-keyboard-removed:v1:'.$account->id;
        if (Cache::get($cacheKey) === true) {
            return;
        }

        try {
            $message = $this->bot->sendMessage(
                $account->chat_id,
                '✨',
                ['remove_keyboard' => true],
            );

            $messageId = (int) ($message['message_id'] ?? 0);
            if ($messageId > 0) {
                $this->bot->deleteMessage($account->chat_id, $messageId);
            }

            Cache::put($cacheKey, true, now()->addDays(30));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function sendCheckout(TelegramAccount $account, int $packageId): void
    {
        $package = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->find($packageId);

        if (! $package) {
            throw new RuntimeException('That package is no longer available.');
        }

        $token = Str::lower(Str::random(18));
        Cache::put('telegram:checkout:'.$token, [
            'account_id' => (string) $account->id,
            'package_id' => (int) $package->id,
            'promotion_code' => null,
            'payment_method' => null,
            'purchase_id' => null,
        ], now()->addMinutes(12));

        $this->showCheckout($account, $token);
    }

    public function requestPromotionCode(TelegramAccount $account, string $token): void
    {
        $checkout = Cache::get('telegram:checkout:'.$token);
        if (! is_array($checkout) || ($checkout['account_id'] ?? null) !== (string) $account->id) {
            throw new RuntimeException('This checkout expired. Open the package again.');
        }
        if (! empty($checkout['purchase_id'])) {
            throw new RuntimeException('This checkout already has an order.');
        }

        Cache::put('telegram:promo-input:'.$account->id, $token, now()->addMinutes(10));

        $km = $this->isKhmer($account);
        $hasPromotion = trim((string) ($checkout['promotion_code'] ?? '')) !== '';

        $buttons = $hasPromotion
            ? [[
                ['text' => $km ? '⬅️ រក្សា Promo ចាស់' : '⬅️ Keep Current', 'callback_data' => 'promocancel:'.$token],
                ['text' => $km ? '🗑 ដក Promo' : '🗑 Remove Promo', 'callback_data' => 'promoclear:'.$token],
            ]]
            : [[
                ['text' => $km ? '❌ បោះបង់' : '❌ Cancel', 'callback_data' => 'promocancel:'.$token],
                ['text' => $km ? '🚫 មិនប្រើ Promo' : '🚫 No Promo', 'callback_data' => 'promoskip:'.$token],
            ]];

        $buttons[] = [['text' => '🏠 Home', 'callback_data' => 'home']];

        $this->bot->sendMessage(
            $account->chat_id,
            $km
                ? "🎟✨ PROMO CODE\n\nវាយ Promo Code ហើយផ្ញើមក bot។\nឧទាហរណ៍: SAVE10\n\nបើមិនចង់ប្រើ Promo អ្នកអាចចុចប៊ូតុងខាងក្រោម។"
                : "🎟✨ PROMO CODE\n\nType your promotion code and send it to the bot.\nExample: SAVE10\n\nYou can also cancel or continue without a promo below.",
            ['inline_keyboard' => $buttons],
        );
    }

    public function handlePromotionInput(TelegramAccount $account, string $text): bool
    {
        $waitKey = 'telegram:promo-input:'.$account->id;
        $token = Cache::get($waitKey);
        if (! is_string($token) || $token === '') {
            return false;
        }

        $code = mb_strtoupper(trim($text));
        if ($code === '/CANCEL') {
            Cache::forget($waitKey);
            $this->showCheckout($account, $token);
            return true;
        }

        $checkoutKey = 'telegram:checkout:'.$token;
        $checkout = Cache::get($checkoutKey);
        if (! is_array($checkout) || ($checkout['account_id'] ?? null) !== (string) $account->id) {
            Cache::forget($waitKey);
            $this->bot->sendMessage($account->chat_id, '⌛ Checkout expired. Open Store and try again.');
            return true;
        }

        $package = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->find((int) ($checkout['package_id'] ?? 0));

        if (! $package) {
            Cache::forget($waitKey);
            return true;
        }

        $promotion = app(PromotionService::class)->evaluate(
            $code,
            $package,
            $account->user,
            (int) $package->price_minor,
        );

        if (! $promotion['valid']) {
            $km = $this->isKhmer($account);

            $this->bot->sendMessage(
                $account->chat_id,
                '❌ '.$promotion['reason']."\n\n".($km ? 'អ្នកអាចផ្ញើ Code មួយទៀត ឬបន្តដោយមិនប្រើ Promo។' : 'Send another code, or continue without a promotion.'),
                ['inline_keyboard' => [
                    [
                        ['text' => $km ? '🔁 សាក Code ផ្សេង' : '🔁 Try Another', 'callback_data' => 'promo:'.$token],
                        ['text' => $km ? '🚫 មិនប្រើ Promo' : '🚫 No Promo', 'callback_data' => 'promoskip:'.$token],
                    ],
                    [['text' => $km ? '⬅️ Checkout' : '⬅️ Checkout', 'callback_data' => 'promocancel:'.$token]],
                ]],
            );
            return true;
        }

        $checkout['promotion_code'] = $promotion['code'];
        Cache::put($checkoutKey, $checkout, now()->addMinutes(12));
        Cache::forget($waitKey);
        $this->showCheckout($account, $token);

        return true;
    }

    public function clearPromotionCode(TelegramAccount $account, string $token): void
    {
        $key = 'telegram:checkout:'.$token;
        $checkout = Cache::get($key);
        if (! is_array($checkout) || ($checkout['account_id'] ?? null) !== (string) $account->id) {
            return;
        }

        $checkout['promotion_code'] = null;
        Cache::put($key, $checkout, now()->addMinutes(12));
        Cache::forget('telegram:promo-input:'.$account->id);
        $this->showCheckout($account, $token);
    }

    public function cancelPromotionInput(TelegramAccount $account, string $token): void
    {
        Cache::forget('telegram:promo-input:'.$account->id);
        $this->showCheckout($account, $token);
    }

    public function skipPromotionInput(TelegramAccount $account, string $token): void
    {
        $key = 'telegram:checkout:'.$token;
        $checkout = Cache::get($key);

        if (is_array($checkout) && ($checkout['account_id'] ?? null) === (string) $account->id) {
            $checkout['promotion_code'] = null;
            Cache::put($key, $checkout, now()->addMinutes(12));
        }

        Cache::forget('telegram:promo-input:'.$account->id);
        $this->showCheckout($account, $token);
    }

    private function showCheckout(TelegramAccount $account, string $token): void
    {
        $key = 'telegram:checkout:'.$token;
        $checkout = Cache::get($key);
        if (! is_array($checkout) || ($checkout['account_id'] ?? null) !== (string) $account->id) {
            $this->bot->sendMessage(
                $account->chat_id,
                $this->isKhmer($account)
                    ? '⌛ Checkout នេះផុតកំណត់ហើយ។ សូមជ្រើសកញ្ចប់ម្តងទៀត។'
                    : '⌛ This checkout expired. Please choose the package again.',
            );
            return;
        }

        $package = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->find((int) ($checkout['package_id'] ?? 0));
        if (! $package) {
            throw new RuntimeException('That package is no longer available.');
        }

        $km = $this->isKhmer($account);
        $subtotalMinor = (int) $package->price_minor;
        $promotion = null;
        $promotionCode = trim((string) ($checkout['promotion_code'] ?? ''));

        if ($promotionCode !== '') {
            $promotion = app(PromotionService::class)->evaluate(
                $promotionCode,
                $package,
                $account->user,
                $subtotalMinor,
            );
            if (! $promotion['valid']) {
                $checkout['promotion_code'] = null;
                Cache::put($key, $checkout, now()->addMinutes(12));
                $promotion = null;
            }
        }

        $totalMinor = $promotion !== null ? (int) $promotion['total_minor'] : $subtotalMinor;
        $discountMinor = $promotion !== null ? (int) $promotion['discount_minor'] : 0;
        $balanceMinor = $this->storeWallet->balanceMinor(
            $account->user,
            (string) $package->currency,
            (int) $package->currency_exponent,
        );

        $subtotal = $this->money($subtotalMinor, (string) $package->currency, (int) $package->currency_exponent);
        $total = $this->money($totalMinor, (string) $package->currency, (int) $package->currency_exponent);
        $wallet = $this->money($balanceMinor, (string) $package->currency, (int) $package->currency_exponent);

        $lines = [
            $km ? '🛒✨ បញ្ជាក់ការទិញ' : '🛒✨ CHECKOUT',
            '',
            '📦 '.$package->name,
            '💵 '.($km ? 'តម្លៃ' : 'Price').': '.$subtotal,
        ];

        if ($promotion !== null) {
            $lines[] = '🎟 '.$promotion['code'].' · '.$promotion['label'];
            if ($discountMinor > 0) {
                $discount = $this->money($discountMinor, (string) $package->currency, (int) $package->currency_exponent);
                $lines[] = '🔥 '.($km ? 'បញ្ចុះតម្លៃ' : 'Discount').': -'.$discount;
            }
            if (($promotion['bonus_units'] ?? null) !== null) {
                $lines[] = '🎁 Bonus: '.number_format((int) $promotion['bonus_units']);
            }
            $lines[] = '✅ '.($km ? 'សរុប' : 'Total').': '.$total;
        }

        $lines[] = '👛 Wallet: '.$wallet;
        $lines[] = '';
        $lines[] = $totalMinor === 0
            ? ($km ? '🎁 Promo នេះធ្វើឱ្យការបញ្ជាទិញឥតគិតថ្លៃ។' : '🎁 This promotion makes the order free.')
            : ($km ? 'ជ្រើសវិធីបង់ប្រាក់ 👇' : 'Choose payment method 👇');

        $firstRow = [[
            'text' => $promotion !== null ? '🎟 Promo ✓' : '🎟 Promo',
            'callback_data' => 'promo:'.$token,
        ]];

        if ($totalMinor === 0) {
            $firstRow[] = [
                'text' => $km ? '🎁 ទទួលយក' : '🎁 Activate',
                'callback_data' => 'payw:'.$token,
            ];
        } else {
            $firstRow[] = $balanceMinor >= $totalMinor
                ? ['text' => '👛 Wallet', 'callback_data' => 'payw:'.$token]
                : ['text' => '➕ Wallet', 'callback_data' => 'wallet:topup'];
            $firstRow[] = ['text' => '🏦 KHQR', 'callback_data' => 'payq:'.$token];
        }

        $keyboard = [$firstRow];
        if ($promotion !== null) {
            $keyboard[] = [
                ['text' => '❌ Promo', 'callback_data' => 'promoclear:'.$token],
                ['text' => $km ? '⬅️ ត្រឡប់' : '⬅️ Back', 'callback_data' => 'pkg:'.$package->id],
                ['text' => '🏠 Home', 'callback_data' => 'home'],
            ];
        } else {
            $keyboard[] = [
                ['text' => $km ? '⬅️ ត្រឡប់' : '⬅️ Back', 'callback_data' => 'pkg:'.$package->id],
                ['text' => '🏠 Home', 'callback_data' => 'home'],
            ];
        }

        $this->bot->sendMessage($account->chat_id, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function beginCheckout(
        TelegramAccount $account,
        string $token,
        string $method,
        string $updateId,
    ): ?TelegramPurchase {
        if (! in_array($method, ['WALLET', 'KHQR'], true)) {
            throw new RuntimeException('Unsupported Telegram payment method.');
        }

        $lock = Cache::lock('telegram:checkout-lock:'.$token, 12);
        if (! $lock->get()) {
            throw new RuntimeException('Checkout is busy. Please try again in a moment.');
        }

        try {
            $key = 'telegram:checkout:'.$token;
            $checkout = Cache::get($key);
            if (! is_array($checkout) || ($checkout['account_id'] ?? null) !== (string) $account->id) {
                throw new RuntimeException('This checkout expired. Open the package again.');
            }

            if (! empty($checkout['purchase_id'])) {
                $purchase = TelegramPurchase::query()->findOrFail((string) $checkout['purchase_id']);
                if (($checkout['payment_method'] ?? null) !== $method) {
                    $this->bot->sendMessage(
                        $account->chat_id,
                        $this->isKhmer($account)
                            ? 'ℹ️ អ្នកបានជ្រើសវិធីបង់ប្រាក់រួចហើយ។ សូមបើក Store ម្តងទៀត បើចង់ប្ដូរ។'
                            : 'ℹ️ A payment method was already selected. Open the Store again if you want another method.',
                    );
                }
                return $purchase;
            }

            $package = Package::query()
                ->published()
                ->where('auto_creates_api_key', true)
                ->find((int) ($checkout['package_id'] ?? 0));
            if (! $package) {
                throw new RuntimeException('That package is no longer available.');
            }

            $promotionCode = trim((string) ($checkout['promotion_code'] ?? ''));
            $created = $this->orders->create(
                $account->user,
                (string) $package->slug,
                1,
                $promotionCode !== '' ? $promotionCode : null,
                'telegram:checkout:'.$token,
            );
            $order = $created['order'];

            $purchase = TelegramPurchase::query()->firstOrCreate(
                ['order_id' => $order->id],
                [
                    'tenant_id' => $account->tenant_id,
                    'user_id' => $account->user_id,
                    'telegram_account_id' => $account->id,
                    'status' => $order->status === 'FULFILLED' ? 'PAID' : 'AWAITING_PAYMENT',
                    'payment_method' => $method,
                ],
            );
            $purchase->forceFill(['payment_method' => $method])->save();

            $checkout['purchase_id'] = (string) $purchase->id;
            $checkout['payment_method'] = $method;
            Cache::put($key, $checkout, now()->addMinutes(20));

            if ($order->status === 'FULFILLED') {
                $purchase->forceFill(['status' => 'PAID', 'last_checked_at' => now()])->save();
                return $this->reconcile($purchase->fresh());
            }

            if ($method === 'WALLET') {
                try {
                    $order = $this->storeWallet->payOrder($account->user, $order);
                } catch (InsufficientStoreBalanceException $exception) {
                    $purchase->delete();
                    $checkout['purchase_id'] = null;
                    $checkout['payment_method'] = null;
                    Cache::put($key, $checkout, now()->addMinutes(12));

                    $this->bot->sendMessage(
                        $account->chat_id,
                        $this->isKhmer($account)
                            ? '😿 Store Wallet មិនគ្រប់គ្រាន់ទេ។ បញ្ចូលប្រាក់សិន ហើយសាកម្តងទៀត។'
                            : '😿 Your Store Wallet balance is too low. Add money first, then try again.',
                        ['inline_keyboard' => [[
                            ['text' => $this->isKhmer($account) ? '➕💵 បញ្ចូលប្រាក់' : '➕💵 Add Money', 'callback_data' => 'wallet:topup'],
                            ['text' => $this->isKhmer($account) ? '🏦 KHQR' : '🏦 KHQR', 'callback_data' => 'payq:'.$token],
                        ]]],
                    );
                    return null;
                }

                $purchase->forceFill(['status' => 'PAID', 'last_checked_at' => now()])->save();
                return $this->reconcile($purchase->fresh());
            }

            $attempt = $this->payments->createAttempt($order);
            $this->sendKhqrQr(
                $account,
                (string) $attempt->qr_payload,
                (string) $purchase->id,
                (string) $order->reference,
                (string) $package->name,
                (int) $attempt->amount_minor,
                (string) $attempt->currency,
                (int) $attempt->currency_exponent,
                $attempt->expires_at?->toAtomString(),
            );

            return $purchase->fresh();
        } finally {
            $lock->release();
        }
    }

    public function sendStoreWallet(TelegramAccount $account): void
    {
        $summary = $this->storeWallet->summary($account->user);
        $km = $this->isKhmer($account);
        $wallet = $this->money($summary['balance_minor'], $summary['currency'], $summary['exponent']);
        $api = $this->balanceSnapshot($account);

        $this->bot->sendMessage(
            $account->chat_id,
            implode("\n", [
                '👛✨ SP CAMBO STORE WALLET',
                '',
                '💵 '.($km ? 'សមតុល្យទិញកញ្ចប់' : 'Purchase balance').': '.$wallet,
                '🪙 API Tokens: '.$api['tokens'],
                '💳 API Credit: '.$api['credit'],
                '',
                $km
                    ? 'ℹ️ Store Wallet ប្រើសម្រាប់ទិញកញ្ចប់ក្នុង SP Cambo Store។ វាដាច់ដោយឡែកពី API Tokens/Credit។'
                    : 'ℹ️ Store Wallet is purchase-only balance for SP Cambo products. It is separate from API Tokens/Credit.',
            ]),
            ['inline_keyboard' => [
                [[
                    'text' => $km ? '➕💵 បញ្ចូលប្រាក់' : '➕💵 Add Money',
                    'callback_data' => 'wallet:topup',
                ], [
                    'text' => $km ? '🛍 ទិញ' : '🛍 Shop',
                    'callback_data' => 'store:1',
                ]],
                [['text' => '🏠 Home', 'callback_data' => 'home']],
            ]],
        );
    }

    public function sendWalletTopupOptions(TelegramAccount $account): void
    {
        $spec = $this->storeWallet->currencySpec();
        $km = $this->isKhmer($account);
        $buttons = [];

        foreach (array_chunk($this->walletTopups->presetAmountsMinor(), 3) as $row) {
            $buttons[] = array_map(fn (int $minor): array => [
                'text' => '💵 '.$this->money($minor, $spec['currency'], $spec['exponent']),
                'callback_data' => 'topup:'.$minor,
            ], $row);
        }

        $buttons[] = [
            ['text' => '👛 Wallet', 'callback_data' => 'wallet'],
            ['text' => '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->bot->sendMessage(
            $account->chat_id,
            $km
                ? "➕💵 STORE WALLET\n\nជ្រើសចំនួនទឹកប្រាក់ 👇"
                : "➕💵 STORE WALLET\n\nChoose an amount 👇",
            ['inline_keyboard' => $buttons],
        );
    }

    public function beginWalletTopup(TelegramAccount $account, int $amountMinor): StoreWalletTopup
    {
        $topup = $this->walletTopups->create($account, $amountMinor);
        $this->sendKhqrQr(
            $account,
            (string) $topup->qr_payload,
            (string) $topup->id,
            (string) $topup->reference,
            $this->isKhmer($account) ? 'បញ្ចូល Store Wallet' : 'Store Wallet top-up',
            (int) $topup->amount_minor,
            (string) $topup->currency,
            (int) $topup->currency_exponent,
            $topup->expires_at?->toAtomString(),
            true,
        );

        return $topup;
    }

    public function checkWalletTopup(TelegramAccount $account, string $id): ?StoreWalletTopup
    {
        $topup = StoreWalletTopup::query()
            ->whereKey($id)
            ->where('user_id', $account->user_id)
            ->where('telegram_account_id', $account->id)
            ->first();

        if (! $topup) {
            return null;
        }

        $verified = $this->walletTopups->verify($topup);
        if ($verified->status === 'PAID') {
            $this->deleteTopupQr($verified);
            return $verified;
        }

        $this->bot->sendMessage(
            $account->chat_id,
            $this->isKhmer($account)
                ? '⏳ មិនទាន់ឃើញការទូទាត់ទេ។ រង់ចាំបន្តិច ហើយពិនិត្យម្តងទៀត។'
                : '⏳ Payment is not confirmed yet. Wait a moment and check again.',
            ['inline_keyboard' => [[
                ['text' => $this->isKhmer($account) ? '🔄 ពិនិត្យ' : '🔄 Check', 'callback_data' => 'checktopup:'.$verified->id],
                ['text' => '👛 Wallet', 'callback_data' => 'wallet'],
            ]]],
        );

        return $verified;
    }

    public function deletePurchaseQrIfFinished(TelegramPurchase $purchase): void
    {
        if ($purchase->delivered_at !== null || in_array($purchase->status, ['PAID', 'DELIVERED'], true)) {
            $this->deletePurchaseQr($purchase);
        }
    }

    private function deleteTopupQr(StoreWalletTopup $topup): void
    {
        if (! $topup->telegram_qr_message_id || $topup->telegram_qr_deleted_at !== null) {
            return;
        }

        $account = $topup->telegramAccount()->first();
        if (! $account) {
            return;
        }

        try {
            $this->bot->deleteMessage($account->chat_id, (int) $topup->telegram_qr_message_id);
            $topup->forceFill(['telegram_qr_deleted_at' => now()])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deletePurchaseQr(TelegramPurchase $purchase): void
    {
        if (! $purchase->telegram_qr_message_id || $purchase->telegram_qr_deleted_at !== null) {
            return;
        }

        $account = $purchase->account()->first();
        if (! $account) {
            return;
        }

        try {
            $this->bot->deleteMessage($account->chat_id, (int) $purchase->telegram_qr_message_id);
            $purchase->forceFill(['telegram_qr_deleted_at' => now()])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sendKhqrQr(
        TelegramAccount $account,
        string $qrPayload,
        string $subjectId,
        string $reference,
        string $title,
        int $amountMinor,
        string $currency,
        int $exponent,
        ?string $expiresAt,
        bool $topup = false,
    ): void {
        $km = $this->isKhmer($account);
        $amount = $this->money($amountMinor, $currency, $exponent);
        $checkCallback = $topup ? 'checktopup:'.$subjectId : 'check:'.$subjectId;
        $expiry = $expiresAt !== null ? CarbonImmutable::parse($expiresAt) : now()->addMinutes(5)->toImmutable();
        $seconds = max(0, $expiry->getTimestamp() - now()->getTimestamp());
        $minutes = max(1, (int) ceil($seconds / 60));

        if ($seconds <= 0) {
            $this->bot->sendMessage(
                $account->chat_id,
                $km ? '⌛ KHQR បានផុតកំណត់។ សូមបង្កើតការទូទាត់ថ្មី។' : '⌛ This KHQR has expired. Create a new payment.',
            );
            return;
        }

        $keyboard = [
            [
                ['text' => '📋 Copy KHQR', 'copy_text' => ['text' => $qrPayload]],
                ['text' => $km ? '✅ ពិនិត្យ' : '✅ Check', 'callback_data' => $checkCallback],
            ],
            [
                ['text' => '👛 Wallet', 'callback_data' => 'wallet'],
                ['text' => '🏠 Home', 'callback_data' => 'home'],
            ],
        ];

        $caption = implode("\n", [
            $topup ? '➕💵 STORE WALLET TOP-UP' : '🏦💳 BAKONG KHQR',
            '',
            '📦 '.$title,
            '💵 '.$amount,
            '🧾 '.$reference,
            '⏳ '.($km ? 'នៅសល់ប្រហែល ' : 'About ').$minutes.($km ? ' នាទី' : ' min remaining'),
            '',
            $km ? '📱 Scan ជាមួយ Bakong/Bank App' : '📱 Scan with Bakong / banking app',
        ]);

        try {
            $png = $this->qrImages->render($qrPayload);
            $message = $this->bot->sendPhotoBytes(
                $account->chat_id,
                $png,
                $caption,
                ['inline_keyboard' => $keyboard],
            );
        } catch (Throwable $exception) {
            report($exception);
            $message = $this->bot->sendMessage(
                $account->chat_id,
                $caption."\n\n".($km ? '⚠️ មិនអាចបង្ហាញ QR image។ ប្រើ Copy KHQR។' : '⚠️ QR image could not be rendered. Use Copy KHQR.'),
                ['inline_keyboard' => $keyboard],
            );
        }

        $messageId = (int) ($message['message_id'] ?? 0);
        if ($messageId <= 0) {
            return;
        }

        $values = [
            'telegram_qr_message_id' => $messageId,
            'telegram_qr_expires_at' => $expiry,
            'telegram_qr_deleted_at' => null,
        ];

        if ($topup) {
            StoreWalletTopup::query()->whereKey($subjectId)->update($values);
            $subjectType = 'topup';
        } else {
            TelegramPurchase::query()->whereKey($subjectId)->update($values);
            $subjectType = 'purchase';
        }

        // A sync queue would execute the delayed job immediately, deleting the QR at once.
        // Production can use database/redis/etc.; SP Cambo already runs a queue worker.
        if ((string) config('queue.default') !== 'sync') {
            DeleteExpiredTelegramQrMessage::dispatch($subjectType, $subjectId)->delay($expiry);
        }
    }
}
