<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelRoutePool extends Model
{
    public const STRATEGY_LEAST_CONNECTIONS = 'LEAST_CONNECTIONS';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_concurrency' => 'integer',
        ];
    }

    public function modelAlias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ModelRoutePoolEntry::class);
    }
}
