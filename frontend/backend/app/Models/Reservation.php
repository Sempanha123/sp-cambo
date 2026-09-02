<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billing_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'reconciliation_requested_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function providerConnectionRevision(): BelongsTo
    {
        return $this->belongsTo(ProviderConnectionRevision::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReservationAllocation::class);
    }
}
