<?php

namespace App\Services;

use App\Exceptions\InsufficientStoreBalanceException;
use App\Models\Order;
use App\Models\StoreWallet;
use App\Models\StoreWalletEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreWalletService
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    /** @return array{currency:string,exponent:int} */
    public function currencySpec(): array
    {
        $currency = strtoupper(trim((string) config('services.bakong.currency', 'USD')));
        if (! in_array($currency, ['USD', 'KHR'], true)) {
            throw new RuntimeException('SP Cambo Store Wallet currently supports USD or KHR.');
        }

        return [
            'currency' => $currency,
            'exponent' => $currency === 'KHR' ? 0 : 2,
        ];
    }

    public function wallet(User $user, ?string $currency = null, ?int $exponent = null, bool $lock = false): StoreWallet
    {
        $spec = $this->currencySpec();
        $currency = strtoupper($currency ?: $spec['currency']);
        $exponent ??= $spec['exponent'];

        StoreWallet::query()->firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            ['currency_exponent' => $exponent, 'balance_minor' => 0],
        );

        $query = StoreWallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency);

        if ($lock) {
            $query->lockForUpdate();
        }

        $wallet = $query->firstOrFail();
        if ((int) $wallet->currency_exponent !== $exponent) {
            throw new RuntimeException('Store Wallet currency exponent does not match the configured currency.');
        }

        return $wallet;
    }

    public function balanceMinor(User $user, ?string $currency = null, ?int $exponent = null): int
    {
        return (int) $this->wallet($user, $currency, $exponent)->balance_minor;
    }

    /** @return array{currency:string,exponent:int,balance_minor:int} */
    public function summary(User $user): array
    {
        $spec = $this->currencySpec();

        return [
            ...$spec,
            'balance_minor' => $this->balanceMinor($user, $spec['currency'], $spec['exponent']),
        ];
    }

    public function credit(
        User $user,
        int $amountMinor,
        string $idempotencyKey,
        ?string $sourceType = null,
        ?string $sourceId = null,
        array $metadata = [],
    ): StoreWallet {
        if ($amountMinor <= 0) {
            throw new RuntimeException('Store Wallet credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountMinor, $idempotencyKey, $sourceType, $sourceId, $metadata): StoreWallet {
            $existing = StoreWalletEntry::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id || (int) $existing->amount_minor !== $amountMinor) {
                    throw new RuntimeException('Store Wallet idempotency key was already used for a different credit.');
                }

                return $existing->wallet()->firstOrFail();
            }

            $spec = $this->currencySpec();
            $wallet = $this->wallet($user, $spec['currency'], $spec['exponent'], true);
            $max = max(1, (int) config('services.spcambo.store_wallet_max_balance_minor', 10_000_000));

            if ((int) $wallet->balance_minor > $max - $amountMinor) {
                throw new RuntimeException('Store Wallet maximum balance would be exceeded.');
            }

            $after = (int) $wallet->balance_minor + $amountMinor;
            $wallet->forceFill(['balance_minor' => $after])->save();

            StoreWalletEntry::query()->create([
                'store_wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'CREDIT',
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $after,
                'idempotency_key' => $idempotencyKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => $metadata ?: null,
            ]);

            return $wallet->fresh();
        });
    }

    public function payOrder(User $user, Order $order): Order
    {
        return DB::transaction(function () use ($user, $order): Order {
            $lockedOrder = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ((int) $lockedOrder->user_id !== (int) $user->id) {
                throw new RuntimeException('That order does not belong to this Store Wallet.');
            }

            $idempotencyKey = 'store-wallet:order:'.$lockedOrder->id;
            $existing = StoreWalletEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $lockedOrder->status === 'FULFILLED'
                    ? $lockedOrder
                    : $this->fulfillment->fulfill($lockedOrder);
            }

            if ($lockedOrder->status === 'FULFILLED') {
                return $lockedOrder;
            }

            if (! in_array($lockedOrder->status, ['PENDING_PAYMENT', 'VERIFYING'], true)) {
                throw new RuntimeException('This order can no longer be paid from Store Wallet.');
            }

            $required = (int) $lockedOrder->total_minor;
            if ($required <= 0) {
                return $this->fulfillment->fulfill($lockedOrder);
            }

            $wallet = $this->wallet(
                $user,
                (string) $lockedOrder->currency,
                (int) $lockedOrder->currency_exponent,
                true,
            );
            $available = (int) $wallet->balance_minor;

            if ($available < $required) {
                throw new InsufficientStoreBalanceException($available, $required, (string) $lockedOrder->currency);
            }

            $after = $available - $required;
            $wallet->forceFill(['balance_minor' => $after])->save();

            StoreWalletEntry::query()->create([
                'store_wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'PURCHASE',
                'amount_minor' => -$required,
                'balance_after_minor' => $after,
                'idempotency_key' => $idempotencyKey,
                'source_type' => 'ORDER',
                'source_id' => (string) $lockedOrder->id,
                'metadata' => [
                    'reference' => (string) $lockedOrder->reference,
                    'currency' => (string) $lockedOrder->currency,
                    'currency_exponent' => (int) $lockedOrder->currency_exponent,
                ],
            ]);

            return $this->fulfillment->fulfill($lockedOrder);
        });
    }
}
