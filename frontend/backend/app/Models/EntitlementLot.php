<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntitlementLot extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allowed_model_aliases' => 'array', 'billing_snapshot' => 'array', 'activated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function boundApiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'bound_api_key_id');
    }

    public function fulfillmentClaim(): BelongsTo
    {
        return $this->belongsTo(FulfillmentClaim::class);
    }
}
