<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['promotion_snapshot' => 'array', 'fulfilled_at' => 'immutable_datetime', 'customer_hidden_at' => 'immutable_datetime', 'stock_reserved_at' => 'immutable_datetime', 'stock_released_at' => 'immutable_datetime', 'stock_consumed_at' => 'immutable_datetime', 'stock_oversold_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }
}
