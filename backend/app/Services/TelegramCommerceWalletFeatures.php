<?php

namespace App\Services;

use App\Exceptions\InsufficientStoreBalanceException;
use App\Models\Package;
use App\Models\StoreWalletTopup;
use App\Models\TelegramAccount;
use App\Models\TelegramPurchase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

trait TelegramCommerceWalletFeatures
{
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
            'payment_method' => null,
            'purchase_id' => null,
        ], now()->addMinutes(12));

        $km = $this->isKhmer($account);
        $balanceMinor = $this->storeWallet->balanceMinor(
            $account->user,
            (string) $package->currency,
            (int) $package->currency_exponent,
        );
        $price = $this->money((int) $package->price_minor, (string) $package->currency, (int) $package->currency_exponent);
        $wallet = $this->money($balanceMinor, (string) $package->currency, (int) $package->currency_exponent);
        $enough = $balanceMinor >= (int) $package->price_minor;

        $this->bot->sendMessage(
            $account->chat_id,
            implode("\n", [
                $km ? '🛒✨ បញ្ជាក់ការទិញ' : '🛒✨ CHECKOUT',
                '',
                '📦 '.$package->name,
                '💵 '.($km ? 'តម្លៃ' : 'Price').': '.$price,
                '👛 Store Wallet: '.$wallet,
                '',
                $km ? 'ជ្រើសរើសវិធីបង់ប្រាក់ 👇' : 'Choose how you want to pay 👇',
            ]),
            ['inline_keyboard' => array_values(array_filter([
                $enough ? [[
                    'text' => $km ? '👛 បង់ពី Store Wallet' : '👛 Pay with Store Wallet',
                    'callback_data' => 'payw:'.$token,
                ]] : [[
                    'text' => $km ? '➕ បញ្ចូលប្រាក់ទៅ Wallet' : '➕ Add money to Wallet',
                    'callback_data' => 'wallet:topup',
                ]],
                [[
                    'text' => $km ? '🏦📱 បង់ដោយ Bakong KHQR' : '🏦📱 Pay with Bakong KHQR',
                    'callback_data' => 'payq:'.$token,
                ]],
                [
                    ['text' => $km ? '⬅️ ត្រឡប់' : '⬅️ Back', 'callback_data' => 'pkg:'.$package->id],
                    ['text' => $km ? '🏠 ទំព័រដើម' : '🏠 Home', 'callback_data' => 'home'],
                ],
            ]))],
        );
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

            $created = $this->orders->create(
                $account->user,
                (string) $package->slug,
                1,
                null,
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
                        ['inline_keyboard' => [
                            [['text' => $this->isKhmer($account) ? '➕💵 បញ្ចូលប្រាក់' : '➕💵 Add Money', 'callback_data' => 'wallet:topup']],
                            [['text' => $this->isKhmer($account) ? '🏦 បង់ KHQR' : '🏦 Pay with KHQR', 'callback_data' => 'payq:'.$token]],
                        ]],
                    );

                    return null;
                }

                $checkout['purchase_id'] = (string) $purchase->id;
                $checkout['payment_method'] = $method;
                Cache::put($key, $checkout, now()->addMinutes(20));

                $purchase->forceFill([
                    'status' => 'PAID',
                    'last_checked_at' => now(),
                ])->save();

                return $this->reconcile($purchase->fresh());
            }

            $checkout['purchase_id'] = (string) $purchase->id;
            $checkout['payment_method'] = $method;
            Cache::put($key, $checkout, now()->addMinutes(20));

            if ($order->status === 'FULFILLED') {
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
                $attempt->expires_at?->toDateTimeString(),
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
                [['text' => $km ? '➕💵 បញ្ចូលប្រាក់' : '➕💵 Add Money', 'callback_data' => 'wallet:topup']],
                [['text' => $km ? '🛍✨ ទិញកញ្ចប់' : '🛍✨ Shop Packages', 'callback_data' => 'store:1']],
                [['text' => $km ? '🏠 ទំព័រដើម' : '🏠 Home', 'callback_data' => 'home']],
            ]],
        );
    }

    public function sendWalletTopupOptions(TelegramAccount $account): void
    {
        $spec = $this->storeWallet->currencySpec();
        $km = $this->isKhmer($account);
        $buttons = [];

        foreach (array_chunk($this->walletTopups->presetAmountsMinor(), 2) as $row) {
            $buttons[] = array_map(fn (int $minor): array => [
                'text' => '💵 '.$this->money($minor, $spec['currency'], $spec['exponent']),
                'callback_data' => 'topup:'.$minor,
            ], $row);
        }

        $buttons[] = [
            ['text' => $km ? '👛 Wallet' : '👛 Wallet', 'callback_data' => 'wallet'],
            ['text' => $km ? '🏠 ទំព័រដើម' : '🏠 Home', 'callback_data' => 'home'],
        ];

        $this->bot->sendMessage(
            $account->chat_id,
            $km
                ? "➕💵✨ បញ្ចូលប្រាក់ទៅ Store Wallet\n\nជ្រើសចំនួនទឹកប្រាក់ 👇\nBot នឹងបង្ហាញ Bakong KHQR ជារូប QR សម្រាប់ Scan។"
                : "➕💵✨ ADD MONEY TO STORE WALLET\n\nChoose an amount 👇\nThe bot will show a Bakong KHQR image you can scan.",
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
            $topup->expires_at?->toDateTimeString(),
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
        if ($verified->status !== 'PAID') {
            $this->bot->sendMessage(
                $account->chat_id,
                $this->isKhmer($account)
                    ? '⏳ មិនទាន់ឃើញការទូទាត់ទេ។ បើអ្នកទើប Scan សូមរង់ចាំបន្តិច ហើយ Check ម្តងទៀត។'
                    : '⏳ Payment is not confirmed yet. If you just scanned, wait a moment and check again.',
                ['inline_keyboard' => [
                    [['text' => $this->isKhmer($account) ? '🔄 ពិនិត្យម្តងទៀត' : '🔄 Check Again', 'callback_data' => 'checktopup:'.$verified->id]],
                    [['text' => $this->isKhmer($account) ? '👛 Wallet' : '👛 Wallet', 'callback_data' => 'wallet']],
                ]],
            );
        }

        return $verified;
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

        $keyboard = [
            [['text' => '📋 Copy KHQR', 'copy_text' => ['text' => $qrPayload]]],
            [['text' => $km ? '✅🔄 ពិនិត្យការទូទាត់' : '✅🔄 Check Payment', 'callback_data' => $checkCallback]],
            [
                ['text' => '👛 Wallet', 'callback_data' => 'wallet'],
                ['text' => '🏠 Home', 'callback_data' => 'home'],
            ],
        ];

        $lines = [
            $topup ? '➕💵✨ STORE WALLET TOP-UP' : '🏦💳✨ BAKONG KHQR',
            '',
            '📦 '.$title,
            '💵 '.($km ? 'ចំនួនទឹកប្រាក់' : 'Amount').': '.$amount,
            '🧾 '.($km ? 'លេខយោង' : 'Reference').': '.$reference,
        ];
        if ($expiresAt) {
            $lines[] = '⏳ '.($km ? 'ផុតកំណត់' : 'Expires').': '.$expiresAt;
        }
        $lines[] = '';
        $lines[] = $km ? '📱 Scan QR ដោយ Bakong/Banking app របស់អ្នក។' : '📱 Scan this QR with your Bakong-compatible banking app.';
        $lines[] = $km ? '🔐 Server ជាអ្នកផ្ទៀងផ្ទាត់ការទូទាត់។' : '🔐 SP Cambo verifies the payment server-side.';
        $caption = implode("\n", $lines);

        try {
            $png = $this->qrImages->render($qrPayload);
            $this->bot->sendPhotoBytes($account->chat_id, $png, $caption, ['inline_keyboard' => $keyboard]);
        } catch (Throwable $exception) {
            report($exception);
            $this->bot->sendMessage(
                $account->chat_id,
                $caption."\n\n".($km ? '⚠️ QR image មិនអាចបង្ហាញបាន។ ប្រើ Copy KHQR ខាងក្រោម។' : '⚠️ QR image could not be rendered. Use Copy KHQR below.'),
                ['inline_keyboard' => $keyboard],
            );
        }
    }
}
