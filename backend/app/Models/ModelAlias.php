<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ModelAlias extends Model
{
    protected $fillable = ['ai_model_id', 'public_alias', 'display_name', 'description', 'capabilities', 'limits', 'status', 'enabled', 'customer_visible'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'limits' => 'array', 'enabled' => 'boolean', 'customer_visible' => 'boolean'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function pricing(): HasOne
    {
        return $this->hasOne(ModelPricing::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where('customer_visible', true)
            ->whereIn('status', ['active', 'beta'])
            ->whereHas('model', fn (Builder $model) => $model
                ->where('enabled', true)
                ->whereNotNull('commercial_resale_verified_at')
                ->whereHas('provider', fn (Builder $provider) => $provider
                    ->where('enabled', true)
                    ->whereHas('activeConnectionRevision', fn (Builder $revision) => $revision
                        ->where('lifecycle_status', ProviderConnectionRevision::STATUS_READY))));
    }
}
