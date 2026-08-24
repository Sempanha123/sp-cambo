<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CreditLedger extends Model
{
    public $timestamps = false;

    protected $table = 'credit_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }
}
