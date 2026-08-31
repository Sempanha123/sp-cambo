<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreWalletTopup extends Model
{
    use HasUlids;

    protected $guarded = [];

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
