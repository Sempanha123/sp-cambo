<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralRegistrationReward extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reward_units' => 'integer',
            'currency_exponent' => 'integer',
            'allowed_model_aliases' => 'array',
            'metadata' => 'array',
            'awarded_at' => 'immutable_datetime',
        ];
    }

    public function referrer(): BelongsTo { return $this->belongsTo(User::class, 'referrer_user_id'); }
    public function referredUser(): BelongsTo { return $this->belongsTo(User::class, 'referred_user_id'); }
    public function entitlementLot(): BelongsTo { return $this->belongsTo(EntitlementLot::class); }
}
