<?php

namespace App\Models;

use App\Services\TelegramQrCountdownService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPurchase extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(function (TelegramPurchase $purchase): void {
            if ($purchase->wasChanged(['telegram_qr_message_id', 'telegram_qr_expires_at'])
                && $purchase->telegram_qr_deleted_at === null
                && (int) ($purchase->telegram_qr_message_id ?? 0) > 0
                && $purchase->telegram_qr_expires_at?->isFuture()) {
                app(TelegramQrCountdownService::class)->schedulePurchase($purchase);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'delivered_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'delivery_lease_expires_at' => 'immutable_datetime',
            'delivery_secret_ciphertext' => 'encrypted',
            'telegram_qr_expires_at' => 'immutable_datetime',
            'telegram_qr_deleted_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfillmentClaim(): BelongsTo
    {
        return $this->belongsTo(FulfillmentClaim::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
