<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderRouteHealth extends Model
{
    protected $table = 'provider_route_health';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',
            'circuit_open_until' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            ProviderConnectionRevision::class,
            'provider_connection_revision_id'
        );
    }

    public function circuitIsOpen(): bool
    {
        return $this->circuit_open_until !== null
            && $this->circuit_open_until->isFuture();
    }
}
