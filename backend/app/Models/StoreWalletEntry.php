<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StoreWalletEntry extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $table = 'store_wallet_entries';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Store wallet ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Store wallet ledger entries are immutable.'));
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(StoreWallet::class, 'store_wallet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
