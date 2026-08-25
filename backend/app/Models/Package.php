<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Package extends Model
{
    protected $fillable = ['slug', 'name', 'subtitle', 'badge', 'billing_mode', 'family', 'family_label', 'advertised_units', 'unit_label', 'price_minor', 'compare_at_price_minor', 'currency', 'currency_exponent', 'duration_seconds', 'limits', 'billing_rules', 'auto_creates_api_key', 'featured', 'sort_order', 'starts_at', 'ends_at', 'enabled', 'customer_visible', 'minimum_margin_bps', 'profitability_override_reason'];

    protected function casts(): array
    {
        return ['limits' => 'array', 'billing_rules' => 'array', 'enabled' => 'boolean', 'customer_visible' => 'boolean', 'auto_creates_api_key' => 'boolean', 'featured' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function modelAliases(): BelongsToMany
    {
        return $this->belongsToMany(ModelAlias::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('enabled', true)->where('customer_visible', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->whereHas('modelAliases', fn (Builder $alias) => $alias->published());
    }
}
