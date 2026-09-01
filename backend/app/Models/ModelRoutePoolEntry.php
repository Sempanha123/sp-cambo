<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelRoutePoolEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weight' => 'integer',
            'max_concurrency' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ModelRoutePool::class, 'model_route_pool_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            ProviderConnectionRevision::class,
            'provider_connection_revision_id'
        );
    }
}
