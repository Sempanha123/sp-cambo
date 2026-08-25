<?php

namespace App\Models;

use App\Exceptions\ProviderConnectionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    public function activateConnectionRevision(ProviderConnectionRevision $revision): self
    {
        return DB::transaction(function () use ($revision): self {
            $provider = self::query()->lockForUpdate()->findOrFail($this->getKey());
            $candidate = ProviderConnectionRevision::query()
                ->lockForUpdate()
                ->findOrFail($revision->getKey());

            if ((string) $candidate->provider_id !== (string) $provider->getKey()) {
                throw new ProviderConnectionException(
                    'The connection revision does not belong to this provider.',
                    'provider_revision_not_owned'
                );
            }

            if (! $candidate->isRouteReady()) {
                throw new ProviderConnectionException(
                    'Only a READY connection revision can be activated.',
                    'provider_revision_not_ready'
                );
            }

            $provider->forceFill([
                'active_connection_revision_id' => $candidate->getKey(),
            ])->saveOrFail();

            return $provider->refresh();
        });
    }
}
