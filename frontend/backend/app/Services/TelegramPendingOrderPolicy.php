<?php

namespace App\Services;

use App\Exceptions\TelegramPendingOrderLimitException;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramPurchase;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps Telegram checkout noise bounded without ever discarding a paid order.
 *
 * "Delete" in the storefront means soft-cancel the abandoned unpaid purchase so
 * it disappears from the active queue. The DB row/order remain available for
 * audit/recovery and paid evidence always wins over cleanup.
 */
class TelegramPendingOrderPolicy
{
    public const MAX_OPEN_ORDERS = 4;
    public const UNPAID_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PackageStockService $stock,
        private readonly TelegramBotClient $bot,
    ) {}

    /** @return array{checked:int,cancelled:int,protected:int} */
    public function cleanupExpired(?TelegramAccount $account = null, int $batch = 100): array
    {
        $batch = max(1, min($batch, 500));
        $cutoff = now()->subSeconds(self::UNPAID_TTL_SECONDS);

        $query = TelegramPurchase::query()
            ->whereNull('delivered_at')
            ->where('status', 'AWAITING_PAYMENT')
            ->where('created_at', '<=', $cutoff)
            ->oldest('created_at');

        if ($account !== null) {
            $query->where('telegram_account_id', $account->id);
        }

        $ids = $query->limit($batch)->pluck('id');
        $cancelled = 0;
        $protected = 0;

        foreach ($ids as $id) {
            $result = $this->cancelIfStillUnpaid((string) $id, 'expired');
            if ($result === true) {
                $cancelled++;
            } elseif ($result === null) {
                $protected++;
            }
        }

        return [
            'checked' => $ids->count(),
            'cancelled' => $cancelled,
            'protected' => $protected,
        ];
    }

    /**
     * Make room immediately before a new payable order is created.
     * Oldest unpaid rows are soft-cancelled first. Paid/delivery-retry rows are
     * never removed. If all four slots are protected paid orders, checkout stops.
     *
     * @return array{cancelled:int,open:int}
     */
    public function makeRoomForNewOrder(TelegramAccount $account): array
    {
        $this->cleanupExpired($account, 50);

        $cancelled = 0;
        $attempted = [];

        while (($open = $this->openCount($account)) >= self::MAX_OPEN_ORDERS) {
            $candidate = TelegramPurchase::query()
                ->where('telegram_account_id', $account->id)
                ->whereNull('delivered_at')
                ->where('status', 'AWAITING_PAYMENT')
                ->when($attempted !== [], fn ($query) => $query->whereNotIn('id', $attempted))
                ->oldest('created_at')
                ->first();

            if (! $candidate) {
                throw new TelegramPendingOrderLimitException(
                    'You already have 4 paid orders waiting for delivery. Open Orders and use Check before creating another purchase. Do not pay again for those orders.'
                );
            }

            $attempted[] = (string) $candidate->id;
            $result = $this->cancelIfStillUnpaid((string) $candidate->id, 'limit');
            if ($result === true) {
                $cancelled++;
                continue;
            }

            // The candidate became paid/actively verifying while we inspected it.
            // Try another unpaid row; never force-delete this one.
            if (count($attempted) >= self::MAX_OPEN_ORDERS + 8) {
                throw new TelegramPendingOrderLimitException(
                    'Your pending orders changed while checkout was opening. Open Orders, tap Check, then try again.'
                );
            }
        }

        return ['cancelled' => $cancelled, 'open' => $open ?? $this->openCount($account)];
    }

    public function openCount(TelegramAccount $account): int
    {
        return TelegramPurchase::query()
            ->where('telegram_account_id', $account->id)
            ->whereNull('delivered_at')
            ->whereIn('status', ['AWAITING_PAYMENT', 'PAID', 'DELIVERY_FAILED'])
            ->count();
    }

    /**
     * true  = cancelled
     * false = no longer eligible / already gone
     * null  = protected because payment or active verification exists
     */
    private function cancelIfStillUnpaid(string $purchaseId, string $reason): ?bool
    {
        $result = DB::transaction(function () use ($purchaseId, $reason): array {
            $purchase = TelegramPurchase::query()
                ->lockForUpdate()
                ->find($purchaseId);

            if (! $purchase || $purchase->delivered_at !== null || $purchase->status !== 'AWAITING_PAYMENT') {
                return ['state' => 'skip'];
            }

            $order = Order::query()
                ->with('paymentAttempts')
                ->lockForUpdate()
                ->find($purchase->order_id);

            if (! $order) {
                $purchase->forceFill([
                    'status' => 'CANCELLED',
                    'last_checked_at' => now(),
                    'last_error' => null,
                    'delivery_lease_token' => null,
                    'delivery_lease_expires_at' => null,
                ])->save();

                return [
                    'state' => 'cancelled',
                    'purchase_id' => (string) $purchase->id,
                    'order_id' => null,
                    'chat_id' => $purchase->account?->chat_id,
                    'qr_message_id' => $purchase->telegram_qr_message_id,
                ];
            }

            $paidAttemptExists = $order->paymentAttempts->contains(
                fn ($attempt): bool => (string) $attempt->status === 'PAID'
            );

            if ($paidAttemptExists || in_array((string) $order->status, ['PAID', 'FULFILLED'], true)) {
                // Repair the Telegram-side state instead of deleting a purchase that
                // already has durable paid evidence.
                $purchase->forceFill([
                    'status' => 'PAID',
                    'last_checked_at' => now(),
                ])->save();

                return ['state' => 'protected'];
            }

            $activeVerification = $order->paymentAttempts->contains(function ($attempt): bool {
                return (string) $attempt->status === 'VERIFYING'
                    && $attempt->verification_lease_expires_at !== null
                    && $attempt->verification_lease_expires_at->isFuture();
            });

            if ($activeVerification) {
                return ['state' => 'protected'];
            }

            // Expire only non-paid attempts. A later operator recovery can still
            // inspect the preserved order and attempt rows if needed.
            $order->paymentAttempts()
                ->where('status', '!=', 'PAID')
                ->update([
                    'status' => 'EXPIRED',
                    'verification_lease_token' => null,
                    'verification_lease_expires_at' => null,
                ]);

            if (in_array((string) $order->status, ['PENDING_PAYMENT', 'VERIFYING'], true)) {
                $order->forceFill(['status' => 'CANCELLED'])->save();
            }

            $purchase->forceFill([
                'status' => 'CANCELLED',
                'last_checked_at' => now(),
                'last_error' => null,
                'delivery_lease_token' => null,
                'delivery_lease_expires_at' => null,
            ])->save();

            return [
                'state' => 'cancelled',
                'purchase_id' => (string) $purchase->id,
                'order_id' => (string) $order->id,
                'chat_id' => $purchase->account?->chat_id,
                'qr_message_id' => $purchase->telegram_qr_message_id,
                'reason' => $reason,
            ];
        });

        if (($result['state'] ?? null) === 'protected') {
            return null;
        }
        if (($result['state'] ?? null) !== 'cancelled') {
            return false;
        }

        if (! empty($result['order_id'])) {
            try {
                $order = Order::query()->find($result['order_id']);
                if ($order) {
                    $this->stock->release($order);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $chatId = (string) ($result['chat_id'] ?? '');
        $messageId = (int) ($result['qr_message_id'] ?? 0);
        if ($chatId !== '' && $messageId > 0) {
            try {
                $this->bot->deleteMessage($chatId, $messageId);
                TelegramPurchase::query()
                    ->whereKey($result['purchase_id'])
                    ->update(['telegram_qr_deleted_at' => now()]);
            } catch (Throwable $exception) {
                // Cleanup must not fail just because Telegram could not delete an
                // already-expired message.
                report($exception);
            }
        }

        return true;
    }
}
