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
 * Compatibility facade for the old R12/R13 purchase-alert outbox.
 *
 * Fix19 has one Telegram role only: the customer Store Bot. Website orders are
 * intentionally silent and never create Telegram rows. Telegram social-proof
 * activity is generated only after a Telegram-originated order is paid,
 * fulfilled, and the buyer's API access was actually delivered.
 */
class TelegramPurchaseAlertService
{
    public function __construct(private readonly TelegramAnnouncementService $announcements) {}

    /** Website and generic order creation never emits Telegram messages. */
    public function orderCreated(Order $order): void {}

    /** Website and generic fulfillment never emits Telegram messages. */
    public function orderFulfilled(Order $order): void {}

    /** Telegram Store social proof is emitted only after customer delivery. */
    public function telegramPurchaseDelivered(TelegramPurchase $purchase): void
    {
        $this->bestEffort(function () use ($purchase): void {
            $purchase = $purchase->fresh(['account', 'order.items', 'order.user', 'order.paymentAttempts']);
            $order = $purchase?->order;
            if (! $purchase || ! $order || $purchase->delivered_at === null) {
                return;
            }
            if (! str_starts_with((string) $order->idempotency_key, 'telegram:')) {
                return;
            }
            if (! $this->isVerifiedPaidFulfilled($order)) {
                return;
            }

            $this->announcements->purchaseActivity($order, $purchase->account);
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
     * Legacy R12/R13 rows are cancelled instead of being delivered. This makes an
     * upgraded database safe even when it still contains old website-alert outbox
     * rows from Fix17.
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
                'last_error' => 'Fix19 disabled website/admin/public purchase alerts. Telegram Store activity now uses subscriber announcements only.',
                'retry_after' => null,
                'delivery_lease_token' => null,
                'delivery_lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        }

        return ['checked' => $ids->count(), 'sent' => 0, 'failed' => 0];
    }

    /** Legacy purchase-alert rows are no longer retryable in the single-bot design. */
    public function retry(string $alertId): bool
    {
        return false;
    }

    /** Recover only delivered Telegram Store purchases, never website orders. */
    public function recoverMissingPublicEvents(int $batch = 100): int
    {
        if (! (bool) config('services.telegram.purchase_activity_enabled', true)) {
            return 0;
        }

        $count = 0;
        $purchases = TelegramPurchase::query()
            ->with(['account', 'order.items', 'order.user', 'order.paymentAttempts'])
            ->whereNotNull('delivered_at')
            ->latest('delivered_at')
            ->limit(max(1, min($batch, 500)))
            ->get();

        foreach ($purchases as $purchase) {
            $order = $purchase->order;
            if (! $order || ! str_starts_with((string) $order->idempotency_key, 'telegram:') || ! $this->isVerifiedPaidFulfilled($order)) {
                continue;
            }

            $eventKey = 'r13:public:order:'.$order->id.':subscribers';
            $before = TelegramAnnouncement::query()->where('event_key', $eventKey)->exists();
            $this->announcements->purchaseActivity($order, $purchase->account);
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
