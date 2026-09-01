<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ModelAlias extends Model
{
    protected $fillable = ['ai_model_id', 'public_alias', 'display_name', 'description', 'capabilities', 'limits', 'status', 'enabled', 'customer_visible'];

    protected static function booted(): void
    {
        static::created(function (ModelAlias $alias): void {
            if ($alias->isTelegramCustomerVisible()) {
                $alias->queueTelegramModelAnnouncement('NEW_MODEL');
            }
        });

        static::updated(function (ModelAlias $alias): void {
            if (! $alias->isTelegramCustomerVisible()) {
                return;
            }

            if (! $alias->wasChanged([
                'public_alias',
                'display_name',
                'description',
                'capabilities',
                'limits',
                'status',
                'enabled',
                'customer_visible',
            ])) {
                return;
            }

            $wasVisible = (bool) $alias->getOriginal('enabled')
                && (bool) $alias->getOriginal('customer_visible')
                && in_array((string) $alias->getOriginal('status'), ['active', 'beta'], true);

            $alias->queueTelegramModelAnnouncement($wasVisible ? 'MODEL_UPDATE' : 'NEW_MODEL');
        });
    }

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

    private function isTelegramCustomerVisible(): bool
    {
        return (bool) $this->enabled
            && (bool) $this->customer_visible
            && in_array((string) $this->status, ['active', 'beta'], true);
    }

    private function queueTelegramModelAnnouncement(string $kind): void
    {
        $eventKey = 'model:auto:'.mb_strtolower($kind).':'.$this->id.':'
            .($this->updated_at?->format('YmdHis.u') ?? now()->format('YmdHis.u'));

        TelegramAnnouncement::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'kind' => $kind,
                'title' => $kind === 'NEW_MODEL' ? 'New model available' : 'Model updated',
                'body' => implode("\n", array_filter([
                    $this->display_name,
                    'Public alias: '.$this->public_alias,
                    $this->description,
                    'Open SP Cambo Store to see packages that include this model.',
                ])),
                'model_alias_id' => $this->id,
                'status' => 'QUEUED',
            ],
        );
    }
}
