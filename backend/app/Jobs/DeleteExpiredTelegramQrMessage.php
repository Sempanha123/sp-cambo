<?php

namespace App\Jobs;

use App\Models\StoreWalletTopup;
use App\Models\TelegramPurchase;
use App\Services\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeleteExpiredTelegramQrMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
    ) {}

    public function handle(TelegramBotClient $bot): void
    {
        if ($this->subjectType === 'topup') {
            $this->deleteTopup($bot);
            return;
        }

        if ($this->subjectType === 'purchase') {
            $this->deletePurchase($bot);
        }
    }

    private function deleteTopup(TelegramBotClient $bot): void
    {
        $topup = StoreWalletTopup::query()->find($this->subjectId);
        if (! $topup || $topup->telegram_qr_deleted_at !== null || ! $topup->telegram_qr_message_id) {
            return;
        }

        if ($topup->telegram_qr_expires_at?->isFuture()) {
            $this->release(max(1, now()->diffInSeconds($topup->telegram_qr_expires_at, false)));
            return;
        }

        $account = $topup->telegramAccount()->first();
        if (! $account) {
            return;
        }

        try {
            $bot->deleteMessage($account->chat_id, (int) $topup->telegram_qr_message_id);
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        }

        $topup->forceFill(['telegram_qr_deleted_at' => now()])->save();
    }

    private function deletePurchase(TelegramBotClient $bot): void
    {
        $purchase = TelegramPurchase::query()->find($this->subjectId);
        if (! $purchase || $purchase->telegram_qr_deleted_at !== null || ! $purchase->telegram_qr_message_id) {
            return;
        }

        if ($purchase->telegram_qr_expires_at?->isFuture()) {
            $this->release(max(1, now()->diffInSeconds($purchase->telegram_qr_expires_at, false)));
            return;
        }

        $account = $purchase->account()->first();
        if (! $account) {
            return;
        }

        try {
            $bot->deleteMessage($account->chat_id, (int) $purchase->telegram_qr_message_id);
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        }

        $purchase->forceFill(['telegram_qr_deleted_at' => now()])->save();
    }
}
