<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentClaim extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'order_item_id',
        'claim_snapshot',
        'expires_at',
        'status',
        'api_key_id',
        'source_idempotency_key',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'claim_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
