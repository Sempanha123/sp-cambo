<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    protected $fillable = ['provider_id', 'internal_model_id', 'display_name', 'family', 'family_label', 'capabilities', 'limits', 'commercial_resale_verified_at', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'capabilities' => 'array', 'limits' => 'array', 'commercial_resale_verified_at' => 'immutable_datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ModelAlias::class);
    }
}
