<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RedeemCode extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'allowed_model_aliases' => 'array',
            'billing_rules' => 'array',
            'enabled' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RedeemCodeRedemption::class);
    }
}
