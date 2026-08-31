<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPurchase extends Model
{
    use HasUlids;

    protected $guarded = [];

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
