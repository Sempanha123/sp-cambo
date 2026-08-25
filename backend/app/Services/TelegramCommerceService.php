<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\FulfillmentClaim;
use App\Models\ModelAlias;
use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\TelegramPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TelegramCommerceService
{
    private const STORE_PAGE_SIZE = 6;

    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly FulfillmentClaimService $claims,
        private readonly TelegramBotClient $bot,
    ) {}

    /**
     * Telegram is a standalone storefront. A private Telegram identity gets a
     * customer workspace automatically, so buying does not depend on website login.
     * Existing linked website accounts are reused and remain fully compatible.
     */
    public function ensureStorefrontAccount(
        string $telegramUserId,
        string $chatId,
        ?string $username,
        ?string $displayName = null,
    ): TelegramAccount {
        return DB::transaction(function () use ($telegramUserId, $chatId, $username, $displayName): TelegramAccount {
            $existing = TelegramAccount::query()
                ->with('user')
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->where('telegram_user_id', $telegramUserId)->orWhere('chat_id', $chatId))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->telegram_user_id !== $telegramUserId || $existing->chat_id !== $chatId) {
                    throw new RuntimeException('This Telegram identity is already attached to another active SP Cambo chat.');
                }
                $existing->forceFill([
                    'username' => $username,
                    'last_seen_at' => now(),
                ])->save();
                return $existing->fresh('user');
            }

            TelegramAccount::query()
                ->whereNotNull('revoked_at')
                ->where(fn ($q) => $q->where('telegram_user_id', $telegramUserId)->orWhere('chat_id', $chatId))
                ->lockForUpdate()
                ->get()
                ->each(fn (TelegramAccount $account) => $this->releaseIdentity($account));

            $email = 'telegram-'.$telegramUserId.'@users.spcambo.local';
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                $user = User::query()->create([
                    'name' => trim((string) ($displayName ?: ($username ? '@'.$username : 'Telegram customer'))),
                    'email' => $email,
                    'password' => Str::random(64),
                    'status' => 'ACTIVE',
                ]);
                $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            }

            $customerRole = Role::query()->firstOrCreate(['name' => 'CUSTOMER'], ['label' => 'Customer']);
            $user->roles()->syncWithoutDetaching([$customerRole->id]);
            $tenant = $user->requireTenant();

            return TelegramAccount::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tenant_id' => $tenant->id,
                    'telegram_user_id' => $telegramUserId,
                    'chat_id' => $chatId,
                    'username' => $username,
                    'locale' => 'en',
                    'announcements_enabled' => true,
                    'linked_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ]
            )->load('user');
        });
    }

    public function sendHome(TelegramAccount $account): void
    {
        $balance = $this->balanceSnapshot($account);
        $km = $this->isKhmer($account);
        $name = trim((string) ($account->user?->name ?: $account->username ?: 'SP Cambo customer'));
        $text = $km
            ? "👋 សួស្តី {$name}\n\n🧠 SP Cambo AI Store\nទិញ token/credit, ទទួល API key ដោយស្វ័យប្រវត្តិ និងពិនិត្យការប្រើប្រាស់បានភ្លាមៗ។\n\n💰 Token: {$balance['tokens']}\n💳 Credit: {$balance['credit']}"
            : "👋 Welcome {$name}\n\n🧠 SP Cambo AI Store\nBuy token/credit packages, receive an API key automatically, and track usage in real time.\n\n💰 Tokens: {$balance['tokens']}\n💳 Credit: {$balance['credit']}";

        $this->bot->sendMessage($account->chat_id, $text, $this->mainKeyboard($account));
    }

    /** @return array{text:string,reply_markup:array<string,mixed>} */
    public function storefront(TelegramAccount $account, int $page = 1): array
    {
        $query = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id');
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::STORE_PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $packages = $query->forPage($page, self::STORE_PAGE_SIZE)->get();
        $km = $this->isKhmer($account);

        if ($packages->isEmpty()) {
            return [
                'text' => $km
                    ? "🛍 SP Cambo Store\n\nមិនទាន់មានកញ្ចប់ API ដែលអាចទិញបានទេ។"
                    : "🛍 SP Cambo Store\n\nNo API-access packages are available right now.",
                'reply_markup' => ['inline_keyboard' => [[['text' => $km ? '🏠 ទំព័រដើម' : '🏠 Home', 'callback_data' => 'home']]]],
            ];
        }

        $lines = [
            $km ? '🛍 SP Cambo Store' : '🛍 SP Cambo Store',
            '',
            $km
                ? 'ជ្រើសកញ្ចប់ខាងក្រោម។ ការទូទាត់ត្រូវបានផ្ទៀងផ្ទាត់ដោយ server ហើយ API key នឹងផ្ញើមក chat នេះដោយស្វ័យប្រវត្តិ។'
                : 'Choose a package below. Payment is verified server-side and your API key is delivered automatically in this chat.',
            '',
        ];
        $keyboard = [];

        foreach ($packages as $package) {
            $price = $this->packagePrice($package);
            $lines[] = "• {$package->name} · {$price} · ".number_format((int) $package->advertised_units).' '.$package->unit_label;
            $keyboard[] = [[
                'text' => '📦 '.$package->name.' · '.$price,
                'callback_data' => 'pkg:'.$package->id,
            ]];
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 1) $nav[] = ['text' => '⬅️', 'callback_data' => 'store:'.($page - 1)];
            $nav[] = ['text' => "{$page}/{$pages}", 'callback_data' => 'noop'];
            if ($page < $pages) $nav[] = ['text' => '➡️', 'callback_data' => 'store:'.($page + 1)];
            $keyboard[] = $nav;
        }

        $keyboard[] = [
            ['text' => $km ? '🧠 ម៉ូដែល' : '🧠 Models', 'callback_data' => 'models'],
            ['text' => $km ? '💰 សមតុល្យ' : '💰 Balance', 'callback_data' => 'balance'],
        ];

        return ['text' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => $keyboard]];
    }

    public function sendStorefront(TelegramAccount $account, int $page = 1): void
    {
        $store = $this->storefront($account, $page);
        $this->bot->sendMessage($account->chat_id, $store['text'], $store['reply_markup']);
    }

    public function sendProduct(TelegramAccount $account, int $packageId): void
    {
        $package = Package::query()->published()->where('auto_creates_api_key', true)->with('modelAliases')->find($packageId);
        if (! $package) throw new RuntimeException('That package is no longer available.');
        $km = $this->isKhmer($account);
        $models = $package->modelAliases->pluck('public_alias')->implode(', ');
        $text = implode("\n", array_filter([
            '📦 '.$package->name,
            $package->subtitle,
            '',
            '💵 '.($km ? 'តម្លៃ' : 'Price').': '.$this->packagePrice($package),
            '🪙 '.($km ? 'ចំនួន' : 'Allowance').': '.number_format((int) $package->advertised_units).' '.$package->unit_label,
            '⏱ '.($km ? 'សុពលភាព' : 'Validity').': '.$this->durationLabel((int) $package->duration_seconds),
            $models !== '' ? '🧠 '.($km ? 'ម៉ូដែល' : 'Models').': '.$models : null,
            '',
            $km ? 'បន្ទាប់ពី Bakong KHQR បានបង់ជោគជ័យ API key នឹងបង្កើត និងផ្ញើមកដោយស្វ័យប្រវត្តិ។' : 'After Bakong KHQR payment is verified, your API key is created and delivered automatically.',
        ]));

        $this->bot->sendMessage($account->chat_id, $text, [
            'inline_keyboard' => [
                [['text' => $km ? '🛒 ទិញឥឡូវ' : '🛒 Buy now', 'callback_data' => 'buy:'.$package->id]],
                [['text' => $km ? '⬅️ ត្រឡប់ទៅហាង' : '⬅️ Back to store', 'callback_data' => 'store:1']],
            ],
        ]);
    }

    public function sendBalance(TelegramAccount $account): void
    {
        $balance = $this->balanceSnapshot($account);
        $km = $this->isKhmer($account);
        $checker = rtrim((string) config('app.frontend_url'), '/').'/public/key-checker';
        $this->bot->sendMessage($account->chat_id, implode("\n", [
            $km ? '💰 សមតុល្យ SP Cambo' : '💰 SP Cambo balance',
            '',
            ($km ? 'Token ដែលអាចប្រើបាន: ' : 'Spendable tokens: ').$balance['tokens'],
            ($km ? 'Credit ដែលអាចប្រើបាន: ' : 'Spendable credit: ').$balance['credit'],
            ($km ? 'កញ្ចប់សកម្ម: ' : 'Active lots: ').$balance['active_lots'],
            '',
            $km ? 'សម្រាប់ usage ជាក់លាក់តាម API key សូមប្រើ Key Checker។' : 'For exact per-key usage, open the public Key Checker.',
        ]), [
            'inline_keyboard' => [
                [['text' => $km ? '🔎 ពិនិត្យ API key' : '🔎 Open Key Checker', 'url' => $checker]],
                [['text' => $km ? '🛍 ទិញបន្ថែម' : '🛍 Buy more', 'callback_data' => 'store:1']],
            ],
        ]);
    }

    public function sendOrders(TelegramAccount $account): void
    {
        $km = $this->isKhmer($account);
        $purchases = TelegramPurchase::query()
            ->where('telegram_account_id', $account->id)
            ->with('order.items')
            ->latest()
            ->limit(10)
            ->get();

        if ($purchases->isEmpty()) {
            $this->bot->sendMessage($account->chat_id, $km ? '🧾 មិនទាន់មានការបញ្ជាទិញទេ។' : '🧾 No Telegram orders yet.', [
                'inline_keyboard' => [[['text' => $km ? '🛍 បើកហាង' : '🛍 Open store', 'callback_data' => 'store:1']]],
            ]);
            return;
        }

        $lines = [$km ? '🧾 ការបញ្ជាទិញថ្មីៗ' : '🧾 Recent Telegram orders', ''];
        foreach ($purchases as $purchase) {
            $order = $purchase->order;
            $name = $order?->items?->first()?->package_name ?? 'Package';
            $lines[] = "• {$order?->reference} · {$name} · {$purchase->status}";
        }
        $lines[] = '';
        $lines[] = $km ? 'ប្រើប៊ូតុងខាងក្រោមដើម្បីផ្ទៀងផ្ទាត់ការទូទាត់ចុងក្រោយ។' : 'Use the button below to re-check your latest pending payment.';

        $this->bot->sendMessage($account->chat_id, implode("\n", $lines), [
            'inline_keyboard' => [
                [['text' => $km ? '🔄 ពិនិត្យការទូទាត់' : '🔄 Check latest payment', 'callback_data' => 'check:latest']],
                [['text' => $km ? '🛍 បើកហាង' : '🛍 Open store', 'callback_data' => 'store:1']],
            ],
        ]);
    }

    public function sendModels(TelegramAccount $account): void
    {
        $km = $this->isKhmer($account);
        $models = ModelAlias::query()->published()->with('model.provider')->orderBy('display_name')->limit(30)->get();
        if ($models->isEmpty()) {
            $this->bot->sendMessage($account->chat_id, $km ? '🧠 មិនទាន់មានម៉ូដែលដែលអាចប្រើបានទេ។' : '🧠 No customer models are available yet.');
            return;
        }

        $lines = [$km ? '🧠 ម៉ូដែលដែលអាចប្រើបាន' : '🧠 Available models', ''];
        foreach ($models as $model) {
            $provider = $model->model?->provider?->name;
            $lines[] = '• '.$model->display_name.' ('.$model->public_alias.')'.($provider ? ' · '.$provider : '');
        }
        $lines[] = '';
        $lines[] = $km ? 'កញ្ចប់នៅក្នុង Store កំណត់ម៉ូដែលណាដែល API key អាចប្រើបាន។' : 'Each Store package defines which of these models its API key may use.';
        $this->bot->sendMessage($account->chat_id, implode("\n", $lines), [
            'inline_keyboard' => [[['text' => $km ? '🛍 មើលកញ្ចប់' : '🛍 View packages', 'callback_data' => 'store:1']]],
        ]);
    }

    public function sendLanguage(TelegramAccount $account): void
    {
        $this->bot->sendMessage($account->chat_id, '🌐 ជ្រើសរើសភាសា / Select language:', [
            'inline_keyboard' => [[
                ['text' => '🇰🇭 ខ្មែរ', 'callback_data' => 'lang:km'],
                ['text' => '🇺🇸 English', 'callback_data' => 'lang:en'],
            ]],
        ]);
    }

    public function setLocale(TelegramAccount $account, string $locale): TelegramAccount
    {
        $locale = in_array($locale, ['en', 'km'], true) ? $locale : 'en';
        $account->forceFill(['locale' => $locale, 'last_seen_at' => now()])->save();
        return $account->fresh('user');
    }

    public function setAnnouncements(TelegramAccount $account, bool $enabled): TelegramAccount
    {
        $account->forceFill(['announcements_enabled' => $enabled, 'last_seen_at' => now()])->save();
        return $account->fresh('user');
    }

    public function sendUpdatesStatus(TelegramAccount $account): void
    {
        $km = $this->isKhmer($account);
        $enabled = (bool) $account->announcements_enabled;
        $text = $km
            ? '🔔 ព័ត៌មានថ្មី: '.($enabled ? 'បើក' : 'បិទ')."\n\nSP Cambo អាចផ្ញើព័ត៌មានអំពីម៉ូដែលថ្មី កញ្ចប់ថ្មី និងការកែប្រែកញ្ចប់។"
            : '🔔 Store updates: '.($enabled ? 'ON' : 'OFF')."\n\nSP Cambo can notify you about newly published models, new packages and important package updates.";
        $this->bot->sendMessage($account->chat_id, $text, [
            'inline_keyboard' => [[
                [
                    'text' => $enabled ? ($km ? '🔕 បិទ' : '🔕 Turn off') : ($km ? '🔔 បើក' : '🔔 Turn on'),
                    'callback_data' => 'updates:'.($enabled ? 'off' : 'on'),
                ],
            ]],
        ]);
    }

    public function beginPurchaseByPackageId(TelegramAccount $account, int $packageId, string $updateId): TelegramPurchase
    {
        $package = Package::query()->published()->where('auto_creates_api_key', true)->find($packageId);
        if (! $package) {
            throw new RuntimeException('That product is no longer available. Open the store again and choose a current product.');
        }

        return $this->beginPurchase($account, $package->slug, $updateId);
    }

    public function beginPurchase(TelegramAccount $account, string $slug, string $updateId): TelegramPurchase
    {
        $package = Package::query()->published()->where('slug', trim($slug))->first();
        if (! $package || ! $package->auto_creates_api_key) {
            throw new RuntimeException('That product is not available for Telegram API-key delivery.');
        }

        $created = $this->orders->create($account->user, trim($slug), 1, null, "telegram:{$account->id}:{$updateId}:{$slug}");
        $order = $created['order'];

        $purchase = TelegramPurchase::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'tenant_id' => $account->tenant_id,
                'user_id' => $account->user_id,
                'telegram_account_id' => $account->id,
                'status' => $order->status === 'FULFILLED' ? 'PAID' : 'AWAITING_PAYMENT',
            ]
        );

        if ($order->status !== 'FULFILLED') {
            $attempt = $this->payments->createAttempt($order);
            $amount = $this->money((int) $attempt->amount_minor, (string) $attempt->currency, (int) $attempt->currency_exponent);
            $km = $this->isKhmer($account);
            $this->bot->sendMessage($account->chat_id, implode("\n", [
                $km ? '💳 Bakong KHQR Payment' : '💳 Bakong KHQR payment',
                '',
                ($km ? 'លេខបញ្ជាទិញ: ' : 'Order: ').$order->reference,
                ($km ? 'កញ្ចប់: ' : 'Package: ').$package->name,
                ($km ? 'ចំនួនទឹកប្រាក់: ' : 'Amount: ').$amount,
                ($km ? 'ផុតកំណត់: ' : 'Expires: ').$attempt->expires_at->toDateTimeString(),
                '',
                $km ? 'KHQR payload (ចុច Copy ខាងក្រោម):' : 'KHQR payload (tap Copy below):',
                (string) $attempt->qr_payload,
                '',
                $km ? 'Server នឹងពិនិត្យការទូទាត់ដោយស្វ័យប្រវត្តិរៀងរាល់នាទី។' : 'The server checks payment automatically every minute. You can also check now.',
            ]), [
                'inline_keyboard' => [
                    [[
                        'text' => $km ? '📋 ចម្លង KHQR' : '📋 Copy KHQR',
                        'copy_text' => ['text' => (string) $attempt->qr_payload],
                    ]],
                    [['text' => $km ? '✅ ខ្ញុំបានបង់ · ពិនិត្យឥឡូវ' : "✅ I've paid · Check now", 'callback_data' => 'check:'.$purchase->id]],
                    [['text' => $km ? '⬅️ ត្រឡប់ទៅហាង' : '⬅️ Back to store', 'callback_data' => 'store:1']],
                ],
            ]);
        } else {
            $this->deliver($purchase);
        }

        return $purchase->fresh();
    }

    public function checkPurchase(TelegramAccount $account, string $purchaseId): ?TelegramPurchase
    {
        if ($purchaseId === 'latest') return $this->checkLatest($account);
        $purchase = TelegramPurchase::query()
            ->where('telegram_account_id', $account->id)
            ->whereKey($purchaseId)
            ->first();
        return $purchase ? $this->reconcile($purchase) : null;
    }

    public function checkLatest(TelegramAccount $account): ?TelegramPurchase
    {
        $purchase = TelegramPurchase::query()->where('telegram_account_id', $account->id)->latest()->first();
        return $purchase ? $this->reconcile($purchase) : null;
    }

    public function reconcile(TelegramPurchase $purchase): TelegramPurchase
    {
        if ($purchase->delivered_at !== null) return $purchase;

        $order = Order::query()->with(['items', 'paymentAttempts'])->findOrFail($purchase->order_id);
        if ($order->status !== 'FULFILLED') {
            $attempt = $order->paymentAttempts()->latest()->first();
            if (! $attempt) return $purchase;
            $attempt = $this->payments->verify($attempt);
            $order = $attempt->order()->with('items')->firstOrFail();
        }

        $purchase->forceFill([
            'last_checked_at' => now(),
            'status' => $order->status === 'FULFILLED' ? 'PAID' : 'AWAITING_PAYMENT',
        ])->save();

        if ($order->status === 'FULFILLED') $this->deliver($purchase->fresh());
        return $purchase->fresh();
    }

    /** @return array{checked:int,failed:int} */
    public function reconcilePending(int $batch = 4): array
    {
        $ids = TelegramPurchase::query()
            ->whereNull('delivered_at')
            ->whereIn('status', ['AWAITING_PAYMENT', 'PAID', 'DELIVERY_FAILED'])
            ->orderByRaw('last_checked_at IS NOT NULL')
            ->orderBy('last_checked_at')
            ->limit(max(1, min($batch, 10)))
            ->pluck('id');

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->reconcile(TelegramPurchase::query()->findOrFail($id));
            } catch (Throwable $e) {
                $failed++;
                TelegramPurchase::query()->whereKey($id)->update([
                    'last_checked_at' => now(),
                    'last_error' => Str::limit($e->getMessage(), 1000),
                    'status' => 'DELIVERY_FAILED',
                ]);
                report($e);
            }
        }

        return ['checked' => $ids->count(), 'failed' => $failed];
    }

    private function deliver(TelegramPurchase $purchase): void
    {
        $account = $purchase->account()->with('user')->firstOrFail();
        $order = $purchase->order()->with('items')->firstOrFail();
        $claim = FulfillmentClaim::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $purchase->tenant_id)
            ->where('status', 'PENDING')
            ->first();

        if (! $claim) {
            $existing = FulfillmentClaim::query()->where('order_id', $order->id)->where('tenant_id', $purchase->tenant_id)->latest()->first();
            if ($existing?->status === 'CLAIMED' && $purchase->delivered_at === null) {
                throw new RuntimeException('The fulfillment secret is no longer available for Telegram delivery.');
            }
            throw new RuntimeException('No API activation claim exists for this Telegram order.');
        }

        $result = $this->claims->claim($account->user->requireTenant(), $claim, "telegram-delivery:{$purchase->id}");
        $secret = $result['secret'];
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Telegram delivery requires a newly issued API key secret.');
        }

        $key = $result['key'];
        $aliases = $key->modelAliases->pluck('public_alias')->values()->all();
        $anthropic = rtrim((string) config('services.spcambo.public_gateway_base_url', config('services.spcambo.gateway_base_url')), '/');
        $openai = $anthropic.'/v1';
        $checker = rtrim((string) config('app.frontend_url'), '/').'/public/key-checker';
        $defaultModel = $aliases[0] ?? 'MODEL_ALIAS';
        $km = $this->isKhmer($account);

        try {
            $this->bot->sendMessage($account->chat_id, implode("\n", [
                $km ? '✅ ការទូទាត់ជោគជ័យ · API access រួចរាល់' : '✅ Payment verified · API access is ready',
                '',
                ($km ? '🔑 API key (បង្ហាញតែម្តង): ' : '🔑 API key (shown once): ').$secret,
                ($km ? '🧠 ម៉ូដែល: ' : '🧠 Models: ').implode(', ', $aliases),
                '',
                $km ? 'រក្សា key នេះឥឡូវនេះ។ SP Cambo មិនអាចបង្ហាញ plaintext secret ម្តងទៀតបានទេ។' : 'Save this key now. SP Cambo cannot reveal the plaintext secret again.',
            ]), [
                'inline_keyboard' => [
                    [['text' => $km ? '🔎 ពិនិត្យ usage / balance' : '🔎 Check usage / balance', 'url' => $checker]],
                    [['text' => $km ? '🛍 ទិញកញ្ចប់ផ្សេង' : '🛍 Buy another package', 'callback_data' => 'store:1']],
                ],
            ]);

            $this->bot->sendMessage($account->chat_id, implode("\n", [
                '🧩 Claude Code setup',
                '',
                'Windows PowerShell',
                '$env:ANTHROPIC_BASE_URL="'.$anthropic.'"',
                '$env:ANTHROPIC_AUTH_TOKEN="'.$secret.'"',
                '$env:ANTHROPIC_MODEL="'.$defaultModel.'"',
                'claude',
                '',
                'macOS / Linux',
                'export ANTHROPIC_BASE_URL="'.$anthropic.'"',
                'export ANTHROPIC_AUTH_TOKEN="'.$secret.'"',
                'export ANTHROPIC_MODEL="'.$defaultModel.'"',
                'claude',
                '',
                'OpenAI / Codex base: '.$openai,
            ]));
        } catch (Throwable $e) {
            DB::transaction(function () use ($claim, $key): void {
                ApiKey::query()->whereKey($key->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
                FulfillmentClaim::query()->whereKey($claim->id)->update(['status' => 'PENDING', 'claimed_at' => null, 'api_key_id' => null]);
            });
            throw $e;
        }

        $purchase->forceFill([
            'status' => 'DELIVERED',
            'fulfillment_claim_id' => $claim->id,
            'api_key_id' => $key->id,
            'delivered_at' => now(),
            'last_error' => null,
        ])->save();
    }

    /** Legacy website-link support kept for existing users; the store no longer requires it. */
    public function createLinkToken(User $user): array
    {
        $tenant = $user->requireTenant();
        $secret = 'SPC-LINK-'.Str::upper(Str::random(12));
        $expiresAt = now()->addMinutes(10);

        DB::transaction(function () use ($user, $tenant, $secret, $expiresAt): void {
            TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('used_at')->delete();
            TelegramLinkToken::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'token_digest' => $this->linkDigest($secret),
                'expires_at' => $expiresAt,
            ]);
        });

        return ['token' => $secret, 'expires_at' => $expiresAt->toAtomString()];
    }

    public function link(string $secret, string $telegramUserId, string $chatId, ?string $username): TelegramAccount
    {
        return DB::transaction(function () use ($secret, $telegramUserId, $chatId, $username): TelegramAccount {
            $token = TelegramLinkToken::query()->where('token_digest', $this->linkDigest($secret))->whereNull('used_at')->lockForUpdate()->first();
            if (! $token || $token->expires_at->isPast()) {
                throw new RuntimeException('That SP Cambo link code is invalid or expired.');
            }

            $conflicts = TelegramAccount::query()
                ->where(fn ($q) => $q->where('telegram_user_id', $telegramUserId)->orWhere('chat_id', $chatId))
                ->where('user_id', '!=', $token->user_id)
                ->lockForUpdate()
                ->get();

            foreach ($conflicts as $conflict) {
                if ($conflict->revoked_at === null) {
                    throw new RuntimeException('This Telegram account already has an active SP Cambo storefront account.');
                }
                $this->releaseIdentity($conflict);
            }

            $account = TelegramAccount::query()->updateOrCreate(
                ['user_id' => $token->user_id],
                [
                    'tenant_id' => $token->tenant_id,
                    'telegram_user_id' => $telegramUserId,
                    'chat_id' => $chatId,
                    'username' => $username,
                    'locale' => 'en',
                    'announcements_enabled' => true,
                    'linked_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ]
            );
            $token->update(['used_at' => now()]);
            return $account;
        });
    }

    public function accountForChat(string $chatId): ?TelegramAccount
    {
        return TelegramAccount::query()->with('user')->where('chat_id', $chatId)->whereNull('revoked_at')->first();
    }

    public function planText(?TelegramAccount $account = null): string
    {
        if ($account) return $this->storefront($account)['text'];
        $packages = Package::query()->published()->where('auto_creates_api_key', true)->limit(20)->get();
        return $packages->isEmpty()
            ? 'SP Cambo Store — no packages are available right now.'
            : "SP Cambo Store\n\n".$packages->map(fn (Package $package): string => '• '.$package->name.' — '.$this->packagePrice($package))->implode("\n");
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $account = TelegramAccount::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($account) $this->releaseIdentity($account);
        });
    }

    /** @return array{tokens:string,credit:string,active_lots:int} */
    private function balanceSnapshot(TelegramAccount $account): array
    {
        $lots = EntitlementLot::query()
            ->where('user_id', $account->user_id)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
        $spendable = static fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);
        $tokens = $lots->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
        $credits = $lots->where('billing_mode', 'CREDIT_BALANCE');
        $creditText = $credits->isEmpty()
            ? '$0.00'
            : $credits->groupBy(fn (EntitlementLot $lot): string => ($lot->currency ?? 'USD').':'.(int) ($lot->currency_exponent ?? 6))
                ->map(function ($group) use ($spendable): string {
                    /** @var EntitlementLot $first */
                    $first = $group->first();
                    return $this->money((int) $group->sum($spendable), $first->currency ?? 'USD', (int) ($first->currency_exponent ?? 6));
                })->implode(' + ');

        return [
            'tokens' => number_format((int) $tokens),
            'credit' => $creditText,
            'active_lots' => $lots->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function mainKeyboard(TelegramAccount $account): array
    {
        $km = $this->isKhmer($account);
        return [
            'keyboard' => [
                [
                    ['text' => $km ? '🛍 ហាង' : '🛍 Store'],
                    ['text' => $km ? '💰 សមតុល្យ' : '💰 Balance'],
                ],
                [
                    ['text' => $km ? '🧠 ម៉ូដែល' : '🧠 Models'],
                    ['text' => $km ? '🧾 ការបញ្ជាទិញ' : '🧾 Orders'],
                ],
                [
                    ['text' => $km ? '🔔 ព័ត៌មានថ្មី' : '🔔 Updates'],
                    ['text' => $km ? '🌐 ភាសា' : '🌐 Language'],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => $km ? 'ជ្រើសមុខងារខាងក្រោម…' : 'Choose an action below…',
        ];
    }

    private function releaseIdentity(TelegramAccount $account): void
    {
        $tombstone = 'rvk:'.$account->id;
        $account->forceFill([
            'telegram_user_id' => $tombstone,
            'chat_id' => $tombstone,
            'revoked_at' => $account->revoked_at ?? now(),
        ])->save();
    }

    private function packagePrice(Package $package): string
    {
        return $this->money((int) $package->price_minor, (string) $package->currency, (int) $package->currency_exponent);
    }

    private function money(int $minor, string $currency, int $exponent): string
    {
        $scale = 10 ** $exponent;
        return number_format($minor / $scale, $exponent).' '.strtoupper($currency);
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds % 86400 === 0) return (int) ($seconds / 86400).' day(s)';
        if ($seconds % 3600 === 0) return (int) ($seconds / 3600).' hour(s)';
        return number_format($seconds).' seconds';
    }

    private function isKhmer(TelegramAccount $account): bool
    {
        return $account->locale === 'km';
    }

    private function linkDigest(string $secret): string
    {
        $key = (string) (config('services.telegram.link_secret') ?: config('app.key'));
        if ($key === '') throw new RuntimeException('Telegram link secret is not configured.');
        return hash_hmac('sha256', Str::upper(trim($secret)), $key);
    }
}
