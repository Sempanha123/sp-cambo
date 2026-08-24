<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\FulfillmentClaim;
use App\Models\Order;
use App\Models\Package;
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

    /** @return array{token:string,expires_at:string} */
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
            $token = TelegramLinkToken::query()
                ->where('token_digest', $this->linkDigest($secret))
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if (! $token || $token->expires_at->isPast()) {
                throw new RuntimeException('That SP Cambo link code is invalid or expired.');
            }

            TelegramAccount::query()
                ->where(fn ($q) => $q->where('telegram_user_id', $telegramUserId)->orWhere('chat_id', $chatId))
                ->where('user_id', '!=', $token->user_id)
                ->delete();

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
        $packages = Package::query()->published()->orderBy('sort_order')->limit(12)->get();
        if ($packages->isEmpty()) return "No purchasable plans are available right now.";

        $lines = ["SP Cambo plans", ""];
        foreach ($packages as $package) {
            $scale = 10 ** (int) $package->currency_exponent;
            $amount = number_format(((int) $package->price_minor) / $scale, (int) $package->currency_exponent);
            $lines[] = "{$package->slug} — {$package->name} — {$amount} {$package->currency} — {$package->advertised_units} {$package->unit_label}";
        }
        $lines[] = "";
        $lines[] = "Buy with: /buy PLAN_SLUG";
        return implode("\n", $lines);
    }

    public function beginPurchase(TelegramAccount $account, string $slug, string $updateId): TelegramPurchase
    {
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
                "Amount: {$amount} {$attempt->currency}",
                "Pay with Bakong KHQR using this payload:",
                (string) $attempt->qr_payload,
                "",
                "After payment use /check. SP Cambo also reconciles Telegram purchases automatically.",
            ]));
        } else {
            $this->deliver($purchase);
        }

        return $purchase->fresh();
    }

    public function checkLatest(TelegramAccount $account): ?TelegramPurchase
    {
        $purchase = TelegramPurchase::query()->where('telegram_account_id', $account->id)->latest()->first();
        if (! $purchase) return null;
        return $this->reconcile($purchase);
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

        $purchase->forceFill(['last_checked_at' => now(), 'status' => $order->status === 'FULFILLED' ? 'PAID' : 'AWAITING_PAYMENT'])->save();
        if ($order->status === 'FULFILLED') $this->deliver($purchase->fresh());
        return $purchase->fresh();
    }

    public function reconcilePending(int $batch = 4): array
    {
        $ids = TelegramPurchase::query()->whereNull('delivered_at')->whereIn('status', ['AWAITING_PAYMENT', 'PAID', 'DELIVERY_FAILED'])
            ->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->limit(max(1, min($batch, 10)))->pluck('id');
        $failed = 0;
        foreach ($ids as $id) {
            try { $this->reconcile(TelegramPurchase::query()->findOrFail($id)); }
            catch (Throwable $e) {
                $failed++;
                TelegramPurchase::query()->whereKey($id)->update(['last_checked_at' => now(), 'last_error' => Str::limit($e->getMessage(), 1000), 'status' => 'DELIVERY_FAILED']);
                report($e);
            }
        }
        return ['checked' => $ids->count(), 'failed' => $failed];
    }

    private function deliver(TelegramPurchase $purchase): void
    {
        $account = $purchase->account()->firstOrFail();
        $order = $purchase->order()->with('items')->firstOrFail();
        $claim = FulfillmentClaim::query()->where('order_id', $order->id)->where('tenant_id', $purchase->tenant_id)->where('status', 'PENDING')->first();
        if (! $claim) {
            $existing = FulfillmentClaim::query()->where('order_id', $order->id)->where('tenant_id', $purchase->tenant_id)->latest()->first();
            if ($existing?->status === 'CLAIMED' && $purchase->delivered_at === null) {
                throw new RuntimeException('The fulfillment secret is no longer available for Telegram delivery.');
            }
            throw new RuntimeException('No API activation claim exists for this Telegram order.');
        }

        $result = $this->claims->claim($account->user->requireTenant(), $claim, "telegram-delivery:{$purchase->id}");
        $secret = $result['secret'];
        if (! is_string($secret) || $secret === '') throw new RuntimeException('Telegram delivery requires a newly issued API key secret.');
        $key = $result['key'];
        $aliases = $key->modelAliases->pluck('public_alias')->values()->all();
        $anthropic = rtrim((string) config('services.spcambo.public_gateway_base_url', config('services.spcambo.gateway_base_url')), '/');
        $openai = $anthropic.'/v1';

        try {
            $this->bot->sendMessage($account->chat_id, implode("\n", [
                "Payment verified. Your SP Cambo access is active.",
                "API key (shown once): {$secret}",
                "Claude Code base URL: {$anthropic}",
                "OpenAI/Codex base URL: {$openai}",
                "Models: ".implode(', ', $aliases),
                "",
                "Claude Code PowerShell:",
                '$env:ANTHROPIC_BASE_URL="'.$anthropic.'"',
                '$env:ANTHROPIC_AUTH_TOKEN="'.$secret.'"',
                '$env:ANTHROPIC_MODEL="'.($aliases[0] ?? 'MODEL_ALIAS').'"',
                'claude',
            ]));
        } catch (Throwable $e) {
            // A one-time secret that was not delivered must not become an unrecoverable claim.
            DB::transaction(function () use ($claim, $key): void {
                ApiKey::query()->whereKey($key->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
                FulfillmentClaim::query()->whereKey($claim->id)->update(['status' => 'PENDING', 'claimed_at' => null, 'api_key_id' => null]);
            });
            throw $e;
        }

        $purchase->forceFill(['status' => 'DELIVERED', 'fulfillment_claim_id' => $claim->id, 'api_key_id' => $key->id, 'delivered_at' => now(), 'last_error' => null])->save();
    }

    private function linkDigest(string $secret): string
    {
        $key = (string) (config('services.telegram.link_secret') ?: config('app.key'));
        if ($key === '') throw new RuntimeException('Telegram link secret is not configured.');
        return hash_hmac('sha256', Str::upper(trim($secret)), $key);
    }
}
