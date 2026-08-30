<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralSetting extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'registration_reward_enabled' => 'boolean',
            'registration_reward_started_at' => 'immutable_datetime',
            'registration_reward_mode' => 'string',
            'registration_credit_minor' => 'integer',
            'registration_token_units' => 'integer',
            'registration_reward_model_aliases' => 'array',
            'commission_bps' => 'integer',
            'referred_bonus_bps' => 'integer',
            'minimum_order_minor' => 'integer',
            'cookie_days' => 'integer',
            'reward_expiry_days' => 'integer',
            'commission_all_orders' => 'boolean',
            'referred_bonus_first_order_only' => 'boolean',
        ];
    }
}
