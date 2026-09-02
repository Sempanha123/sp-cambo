<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelPricing extends Model
{
    protected $table = 'model_pricing';

    protected $guarded = [];

    // Private reference-cost and verification fields are internal business data. Admin
    // controllers read these properties explicitly; accidental model JSON
    // serialization must never expose them to customers.
    protected $hidden = [
        'upstream_input_per_million_minor',
        'upstream_output_per_million_minor',
        'upstream_cache_read_per_million_minor',
        'upstream_cache_write_per_million_minor',
        'upstream_reasoning_per_million_minor',
        'upstream_cost_verified_at',
    ];

    protected function casts(): array
    {
        return ['upstream_cost_verified_at' => 'immutable_datetime'];
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class, 'model_alias_id');
    }
}
