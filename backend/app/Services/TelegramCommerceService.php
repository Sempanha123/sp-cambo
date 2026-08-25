<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\FulfillmentClaim;
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
                $existing->forceFill(['username' => $username])->save();
                return $existing->fresh('user');
            }

            // Reclaim unique identifiers from historical revoked rows only.
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
                    'linked_at' => now(),
                    'revoked_at' => null,
                ]
            )->load('user');
        });
    }

    /** @return array{text:string,reply_markup:array<string,mixed>} */
    public function storefront(): array
    {
        $packages = Package::query()
            ->published()
            ->where('auto_creates_api_key', true)
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->limit(20)
            ->get();

        if ($packages->isEmpty()) {
            return [
                'text' => "SP Cambo Store\n\nNo API-access products are available right now. An admin must publish at least one sell-ready package.",
                'reply_markup' => ['inline_keyboard' => []],
            ];
        }

        $lines = [
            'SP Cambo Store',
            '',
            'Choose a product below. Payment is verified server-side and your API key is delivered automatically in this private chat.',
            '',
        ];
        $keyboard = [];

        foreach ($packages as $package) {
            $price = $this->packagePrice($package);
            $lines[] = "• {$package->name} — {$price} — {$package->advertised_units} {$package->unit_label}";
            $keyboard[] = [[
                'text' => "Buy {$package->name} · {$price}",
                'callback_data' => 'buy:'.$package->id,
            ]];
        }

        $lines[] = '';
        $lines[] = 'After delivery, paste the key into the public Key Checker to see remaining balance and recent usage without signing in.';

        return ['text' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => $keyboard]];
    }

    public function sendStorefront(TelegramAccount $account): void
    {
        $store = $this->storefront();
        $this->bot->sendMessage($account->chat_id, $store['text'], $store['reply_markup']);
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
            $scale = 10 ** (int) $attempt->currency_exponent;
            $amount = number_format(((int) $attempt->amount_minor) / $scale, (int) $attempt->currency_exponent);
            $this->bot->sendMessage($account->chat_id, implode("\n", [
                "Order {$order->reference}",
                "Product: {$package->name}",
                "Amount: {$amount} {$attempt->currency}",
                '',
                'Pay with Bakong KHQR using this payload:',
                (string) $attempt->qr_payload,
                '',
                'Payment is checked automatically. You can also tap the button below to check now.',
            ]), [
                'inline_keyboard' => [[
                    ['text' => "I've paid — check now", 'callback_data' => 'check:'.$purchase->id],
                ]],
            ]);
        } else {
            $this->deliver($purchase);
        }

        return $purchase->fresh();
    }

    public function checkPurchase(TelegramAccount $account, string $purchaseId): ?TelegramPurchase
    {
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
        $account = $purchase->account()->firstOrFail();
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

        try {
            $this->bot->sendMessage($account->chat_id, implode("\n", [
                '✅ Payment verified. Your SP Cambo access is active.',
                '',
                "API key (shown once): {$secret}",
                "Models: ".implode(', ', $aliases),
                "Claude Code base URL: {$anthropic}",
                "OpenAI/Codex base URL: {$openai}",
                '',
                'Claude Code · Windows PowerShell',
                '$env:ANTHROPIC_BASE_URL="'.$anthropic.'"',
                '$env:ANTHROPIC_AUTH_TOKEN="'.$secret.'"',
                '$env:ANTHROPIC_MODEL="'.$defaultModel.'"',
                'claude',
                '',
                'Claude Code · macOS/Linux',
                'export ANTHROPIC_BASE_URL="'.$anthropic.'"',
                'export ANTHROPIC_AUTH_TOKEN="'.$secret.'"',
                'export ANTHROPIC_MODEL="'.$defaultModel.'"',
                'claude',
                '',
                "Usage checker (no login): {$checker}",
                'Paste this API key there to see remaining balance and recent metered requests. The checker never needs your website password.',
            ]), [
                'inline_keyboard' => [
                    [['text' => 'Buy another product', 'callback_data' => 'store']],
                ],
            ]);
        } catch (Throwable $e) {
            // A one-time secret that was not delivered must not become unrecoverable.
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
                    'linked_at' => now(),
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

    public function planText(): string
    {
        return $this->storefront()['text'];
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $account = TelegramAccount::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($account) $this->releaseIdentity($account);
        });
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
        $scale = 10 ** (int) $package->currency_exponent;
        $amount = number_format(((int) $package->price_minor) / $scale, (int) $package->currency_exponent);
        return "{$amount} {$package->currency}";
    }

    private function linkDigest(string $secret): string
    {
        $key = (string) (config('services.telegram.link_secret') ?: config('app.key'));
        if ($key === '') throw new RuntimeException('Telegram link secret is not configured.');
        return hash_hmac('sha256', Str::upper(trim($secret)), $key);
    }
}
