<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelRoutePool extends Model
{
    public const STRATEGY_WEIGHTED_LEAST_CONNECTIONS = 'WEIGHTED_LEAST_CONNECTIONS';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_concurrency' => 'integer',
            'max_failover_attempts' => 'integer',
            'circuit_failure_threshold' => 'integer',
            'circuit_cooldown_seconds' => 'integer',
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
