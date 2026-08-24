<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelPricing extends Model
{
    protected $table = 'model_pricing';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['upstream_cost_verified_at' => 'immutable_datetime'];
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class, 'model_alias_id');
    }
}
