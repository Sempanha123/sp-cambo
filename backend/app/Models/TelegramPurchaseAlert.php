<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramPurchaseAlert extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_lease_expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'retry_after' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
