<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreWalletEntry;
use App\Models\TelegramAnnouncement;
use App\Models\TelegramPurchase;
use App\Models\TelegramPurchaseAlert;
use Illuminate\Support\Str;
use Throwable;

/**
 * Compatibility facade for the old purchase-alert outbox.
 *
 * V20 emits one verified PURCHASE_ACTIVITY announcement for every real paid +
 * fulfilled SP Cambo order, regardless of whether checkout started on the
 * website or in the Telegram Store. The Telegram notification router decides
 * whether that announcement goes to Bot subscribers, configured channels, both,
 * or nowhere.
 */
class TelegramPurchaseAlertService
{
    public function __construct(private readonly TelegramAnnouncementService $announcements) {}

    /** Order creation is intentionally silent; only verified fulfillment may alert. */
    public function orderCreated(Order $order): void {}

    /** Website + Telegram orders both emit after verified paid fulfillment. */
    public function orderFulfilled(Order $order): void
    {
        $this->bestEffort(function () use ($order): void {
            $order = $order->fresh(['items', 'user', 'paymentAttempts']);
            if (! $order || ! $this->isVerifiedPaidFulfilled($order)) {
                return;
            }

            // Do not exclude the buyer. An enabled Store Bot subscriber should see
            // the same verified purchase activity as every other subscriber.
            $this->announcements->purchaseActivity($order);
        });
    }

    /**
     * Telegram delivery keeps this idempotent recovery hook. orderFulfilled()
     * normally created the announcement already; firstOrCreate() prevents doubles.
     */
    public function telegramPurchaseDelivered(TelegramPurchase $purchase): void
    {
        $this->bestEffort(function () use ($purchase): void {
            $purchase = $purchase->fresh(['account', 'order.items', 'order.user', 'order.paymentAttempts']);
            $order = $purchase?->order;
            if (! $purchase || ! $order || $purchase->delivered_at === null) {
                return;
            }
            if (! $this->isVerifiedPaidFulfilled($order)) {
                return;
            }

            $this->announcements->purchaseActivity($order);
        });
    }

    /** Delivery failures remain in the Telegram purchase itself; no separate alert bot exists. */
    public function telegramDeliveryFailed(TelegramPurchase $purchase, string $safeError): void
    {
        logger()->warning('Telegram Store delivery failed; purchase remains retryable.', [
            'telegram_purchase_id' => (string) $purchase->id,
            'safe_error' => Str::limit(trim($safeError), 500),
        ]);
    }

    /**
     * Legacy purchase-alert outbox rows are cancelled. V20 uses the unified
     * TelegramAnnouncement router for verified website + Telegram purchases.
     *
     * @return array{checked:int,sent:int,failed:int}
     */
    public function dispatchPending(int $batch = 50): array
    {
        $batch = max(1, min($batch, 500));
        $ids = TelegramPurchaseAlert::query()
            ->whereIn('status', ['PENDING', 'SENDING', 'FAILED'])
            ->oldest('created_at')
            ->limit($batch)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            TelegramPurchaseAlert::query()->whereIn('id', $ids)->update([
                'status' => 'CANCELLED',
                'last_error' => 'V20 uses unified verified purchase activity announcements; legacy purchase-alert outbox rows are disabled.',
                'retry_after' => null,
                'delivery_lease_token' => null,
                'delivery_lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        }

        return ['checked' => $ids->count(), 'sent' => 0, 'failed' => 0];
    }

    /** Legacy purchase-alert rows are no longer retryable in the unified router design. */
    public function retry(string $alertId): bool
    {
        return false;
    }

    /** Recover missing verified purchase events for BOTH website and Telegram orders. */
    public function recoverMissingPublicEvents(int $batch = 100): int
    {
        if (! (bool) config('services.telegram.purchase_activity_enabled', true)) {
            return 0;
        }

        $count = 0;
        $orders = Order::query()
            ->with(['items', 'user', 'paymentAttempts'])
            ->where('status', 'FULFILLED')
            ->whereNotNull('fulfilled_at')
            ->latest('fulfilled_at')
            ->limit(max(1, min($batch, 500)))
            ->get();

        foreach ($orders as $order) {
            if (! $this->isVerifiedPaidFulfilled($order)) {
                continue;
            }

            $eventKey = 'r13:public:order:'.$order->id.':subscribers';
            $before = TelegramAnnouncement::query()->where('event_key', $eventKey)->exists();
            $this->announcements->purchaseActivity($order);
            if (! $before && TelegramAnnouncement::query()->where('event_key', $eventKey)->exists()) {
                $count++;
            }
        }

        return $count;
    }

    private function isVerifiedPaidFulfilled(Order $order): bool
    {
        if ($order->status !== 'FULFILLED' || $order->fulfilled_at === null || (int) $order->total_minor <= 0) {
            return false;
        }

        $paidByWallet = StoreWalletEntry::query()
            ->where('type', 'PURCHASE')
            ->where('source_type', 'ORDER')
            ->where('source_id', (string) $order->id)
            ->exists();

        if ($order->relationLoaded('paymentAttempts')) {
            return $paidByWallet
                || $order->paymentAttempts->contains(fn ($attempt): bool => $attempt->status === 'PAID' && $attempt->paid_at !== null);
        }

        return $paidByWallet
            || $order->paymentAttempts()->where('status', 'PAID')->whereNotNull('paid_at')->exists();
    }

    private function bestEffort(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
