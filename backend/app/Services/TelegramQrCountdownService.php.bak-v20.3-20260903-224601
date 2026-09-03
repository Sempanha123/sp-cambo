<?php

namespace App\Services;

use App\Jobs\RefreshTelegramQrCountdown;
use App\Models\StoreWalletTopup;
use App\Models\TelegramPurchase;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramQrCountdownService
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramNotificationRouter $notifications,
    ) {}

    public function schedulePurchase(TelegramPurchase $purchase): void
    {
        $this->schedule(
            'purchase',
            (string) $purchase->id,
            (int) ($purchase->telegram_qr_message_id ?? 0),
            $purchase->telegram_qr_expires_at,
            $purchase->telegram_qr_deleted_at,
        );
    }

    public function scheduleTopup(StoreWalletTopup $topup): void
    {
        $this->schedule(
            'topup',
            (string) $topup->id,
            (int) ($topup->telegram_qr_message_id ?? 0),
            $topup->telegram_qr_expires_at,
            $topup->telegram_qr_deleted_at,
        );
    }

    /**
     * @return int|null Seconds until the next edit; null means this chain is done.
     */
    public function refresh(string $subjectType, string $subjectId): ?int
    {
        $chainKey = $this->chainKey($subjectType, $subjectId);

        if (! $this->notifications->qrCountdownEnabled()) {
            Cache::forget($chainKey);
            return null;
        }

        try {
            if ($subjectType === 'purchase') {
                return $this->refreshPurchase($subjectId, $chainKey);
            }

            if ($subjectType === 'topup') {
                return $this->refreshTopup($subjectId, $chainKey);
            }

            Cache::forget($chainKey);
            return null;
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        }
    }

    private function schedule(
        string $type,
        string $id,
        int $messageId,
        mixed $expiresAt,
        mixed $deletedAt,
    ): void {
        if (! $this->notifications->qrCountdownEnabled()
            || $messageId <= 0
            || $deletedAt !== null
            || $expiresAt === null
            || ! $expiresAt->isFuture()) {
            return;
        }

        $ttl = max(60, now()->diffInSeconds($expiresAt, false) + 120);
        $key = $this->chainKey($type, $id);

        // Multiple model saves can happen during checkout. Only one countdown
        // chain is allowed for a QR message.
        if (! Cache::add($key, true, now()->addSeconds($ttl))) {
            return;
        }

        RefreshTelegramQrCountdown::dispatch($type, $id)
            ->delay(now()->addSecond())
            ->afterCommit();
    }

    private function refreshPurchase(string $id, string $chainKey): ?int
    {
        $purchase = TelegramPurchase::query()
            ->with(['account', 'order.items', 'order.paymentAttempts'])
            ->find($id);

        if (! $purchase
            || $purchase->telegram_qr_deleted_at !== null
            || ! $purchase->telegram_qr_message_id
            || ! $purchase->telegram_qr_expires_at
            || ! $purchase->account) {
            Cache::forget($chainKey);
            return null;
        }

        $order = $purchase->order;
        $paid = $purchase->delivered_at !== null
            || strtoupper((string) $purchase->status) === 'DELIVERED'
            || ($order && in_array(strtoupper((string) $order->status), ['PAID', 'FULFILLED'], true))
            || ($order?->paymentAttempts?->contains(
                fn ($attempt): bool => strtoupper((string) $attempt->status) === 'PAID'
            ) ?? false);

        if ($paid) {
            $this->safeEditCaption(
                (string) $purchase->account->chat_id,
                (int) $purchase->telegram_qr_message_id,
                $this->purchasePaidCaption($purchase),
            );
            Cache::forget($chainKey);
            return null;
        }

        $remaining = max(0, now()->diffInSeconds($purchase->telegram_qr_expires_at, false));

        if ($remaining <= 0) {
            $this->safeEditCaption(
                (string) $purchase->account->chat_id,
                (int) $purchase->telegram_qr_message_id,
                $this->purchaseCaption($purchase, 0),
            );
            Cache::forget($chainKey);
            return null;
        }

        $this->safeEditCaption(
            (string) $purchase->account->chat_id,
            (int) $purchase->telegram_qr_message_id,
            $this->purchaseCaption($purchase, $remaining),
        );

        return min($this->notifications->qrCountdownInterval(), $remaining);
    }

    private function refreshTopup(string $id, string $chainKey): ?int
    {
        $topup = StoreWalletTopup::query()
            ->with('telegramAccount')
            ->find($id);

        if (! $topup
            || $topup->telegram_qr_deleted_at !== null
            || ! $topup->telegram_qr_message_id
            || ! $topup->telegram_qr_expires_at
            || ! $topup->telegramAccount) {
            Cache::forget($chainKey);
            return null;
        }

        if ($topup->paid_at !== null || strtoupper((string) $topup->status) === 'PAID') {
            $this->safeEditCaption(
                (string) $topup->telegramAccount->chat_id,
                (int) $topup->telegram_qr_message_id,
                $this->topupPaidCaption($topup),
            );
            Cache::forget($chainKey);
            return null;
        }

        $remaining = max(0, now()->diffInSeconds($topup->telegram_qr_expires_at, false));

        if ($remaining <= 0) {
            $this->safeEditCaption(
                (string) $topup->telegramAccount->chat_id,
                (int) $topup->telegram_qr_message_id,
                $this->topupCaption($topup, 0),
            );
            Cache::forget($chainKey);
            return null;
        }

        $this->safeEditCaption(
            (string) $topup->telegramAccount->chat_id,
            (int) $topup->telegram_qr_message_id,
            $this->topupCaption($topup, $remaining),
        );

        return min($this->notifications->qrCountdownInterval(), $remaining);
    }

    private function purchaseCaption(TelegramPurchase $purchase, int $remaining): string
    {
        $order = $purchase->order;
        $item = $order?->items?->first();
        $name = trim((string) ($item?->package_name ?: 'SP Cambo package'));
        $amount = $order
            ? $this->money((int) $order->total_minor, (string) $order->currency, (int) $order->currency_exponent)
            : '—';
        $reference = trim((string) ($order?->reference ?? ''));

        return implode("\n", array_filter([
            '💳✨ BAKONG KHQR',
            '',
            '📦 '.$name,
            '💵 Amount: '.$amount,
            $reference !== '' ? '🧾 Order: #'.$reference : null,
            '',
            $remaining > 0
                ? '⏳ Time remaining: '.$this->clock($remaining)
                : '⌛ This KHQR has expired.',
            $remaining > 0 ? '🔄 Countdown updates automatically.' : null,
            '',
            $remaining > 0
                ? 'Pay the exact amount shown in the QR. SP Cambo verifies payment server-side.'
                : 'Open Store and create a new payment if you still want this package.',
        ]));
    }

    private function purchasePaidCaption(TelegramPurchase $purchase): string
    {
        $order = $purchase->order;
        $item = $order?->items?->first();

        return implode("\n", array_filter([
            '✅✨ PAYMENT RECEIVED',
            '',
            $item?->package_name ? '📦 '.$item->package_name : null,
            $order?->reference ? '🧾 Order: #'.$order->reference : null,
            '',
            'SP Cambo verified the payment.',
            'Your access is being prepared/delivered automatically.',
        ]));
    }

    private function topupCaption(StoreWalletTopup $topup, int $remaining): string
    {
        return implode("\n", [
            '👛✨ STORE WALLET · BAKONG KHQR',
            '',
            '💵 Amount: '.$this->money((int) $topup->amount_minor, (string) $topup->currency, (int) $topup->currency_exponent),
            '🧾 Reference: '.(string) $topup->reference,
            '',
            $remaining > 0
                ? '⏳ Time remaining: '.$this->clock($remaining)
                : '⌛ This KHQR has expired.',
            $remaining > 0 ? '🔄 Countdown updates automatically.' : 'Create a new top-up to continue.',
        ]);
    }

    private function topupPaidCaption(StoreWalletTopup $topup): string
    {
        return implode("\n", [
            '✅✨ WALLET TOP-UP RECEIVED',
            '',
            '💵 +'.$this->money((int) $topup->amount_minor, (string) $topup->currency, (int) $topup->currency_exponent),
            '🧾 Reference: '.(string) $topup->reference,
            '',
            'SP Cambo verified the Bakong payment.',
        ]);
    }

    private function safeEditCaption(string $chatId, int $messageId, string $caption): void
    {
        try {
            $this->bot->editMessageCaption($chatId, $messageId, mb_substr($caption, 0, 1000));
        } catch (Throwable $exception) {
            // If the user deleted the QR message, Telegram can no longer edit it.
            // Let the queue retry transport faults, but permanently missing
            // messages are harmless and should not break payment reconciliation.
            $message = mb_strtolower($exception->getMessage());
            if (str_contains($message, 'message to edit not found')
                || str_contains($message, 'message is not modified')
                || str_contains($message, 'message can\'t be edited')) {
                return;
            }

            throw $exception;
        }
    }

    private function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return sprintf('%02d:%02d', $minutes, $rest);
    }

    private function money(int $minor, string $currency, int $exponent): string
    {
        $exponent = max(0, min(6, $exponent));
        $scale = 10 ** $exponent;
        $number = number_format($minor / $scale, $exponent, '.', '');

        return strtoupper($currency) === 'USD' ? '$'.$number : $number.' '.strtoupper($currency);
    }

    private function chainKey(string $type, string $id): string
    {
        return 'telegram:qr-countdown:'.$type.':'.$id;
    }
}
