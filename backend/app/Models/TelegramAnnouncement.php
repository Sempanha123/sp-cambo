<?php

namespace App\Models;

use App\Services\TelegramNotificationRouter;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramAnnouncement extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (TelegramAnnouncement $announcement): void {
            // Never expose private/upstream provider identity in customer-facing
            // Store Bot or public-channel model announcements.
            if (in_array(strtoupper((string) $announcement->kind), ['NEW_MODEL', 'MODEL_UPDATE'], true)) {
                $lines = preg_split('/\R/u', (string) $announcement->body) ?: [];
                $announcement->body = implode("\n", array_values(array_filter(
                    $lines,
                    fn (string $line): bool => ! str_starts_with(mb_strtolower(trim($line)), 'provider:')
                        && ! str_contains(mb_strtolower($line), 'omniroute')
                )));
            }
        });

        static::created(function (TelegramAnnouncement $announcement): void {
            app(TelegramNotificationRouter::class)->routeAnnouncement($announcement);
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function modelAlias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TelegramAnnouncementDelivery::class);
    }
}
