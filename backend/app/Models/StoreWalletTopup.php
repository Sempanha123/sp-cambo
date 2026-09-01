<?php

namespace App\Models;

use App\Services\TelegramQrCountdownService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreWalletTopup extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(function (StoreWalletTopup $topup): void {
            if ($topup->wasChanged(['telegram_qr_message_id', 'telegram_qr_expires_at'])
                && $topup->telegram_qr_deleted_at === null
                && (int) ($topup->telegram_qr_message_id ?? 0) > 0
                && $topup->telegram_qr_expires_at?->isFuture()) {
                app(TelegramQrCountdownService::class)->scheduleTopup($topup);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'amount_minor' => 'integer',
            'verification_lease_expires_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'telegram_qr_expires_at' => 'immutable_datetime',
            'telegram_qr_deleted_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
