<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Provider extends Model
{
    protected $fillable = ['name', 'slug', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function connectionRevisions(): HasMany
    {
        return $this->hasMany(ProviderConnectionRevision::class);
    }

    public function activeConnectionRevision(): BelongsTo
    {
        return $this->belongsTo(ProviderConnectionRevision::class, 'active_connection_revision_id');
    }
}
