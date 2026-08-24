<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProviderConnectionRevision extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_READY = 'READY';

    public const STATUS_DRAINING = 'DRAINING';

    public const STATUS_REVOKED = 'REVOKED';

    public const CONNECTION_TYPES = ['omniroute', 'openai_compatible'];

    private const IMMUTABLE_FIELDS = [
        'provider_id',
        'route_version',
        'origin',
        'connection_type',
        'credential',
        'credential_suffix',
        'timeout_ms',
        'policy_version',
    ];

    protected $fillable = [
        'provider_id',
        'route_version',
        'origin',
        'connection_type',
        'credential',
        'credential_suffix',
        'timeout_ms',
        'policy_version',
        'lifecycle_status',
        'last_probe_status',
        'last_probe_at',
        'resolve_until',
    ];

    protected $hidden = ['credential', 'origin'];

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($revision->isDirty($field)) {
                    throw new LogicException('Provider connection routing fields are immutable. Rotate the connection instead.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'route_version' => 'integer',
            'timeout_ms' => 'integer',
            'policy_version' => 'integer',
            'last_probe_at' => 'immutable_datetime',
            'resolve_until' => 'immutable_datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function isRouteReady(): bool
    {
        return $this->lifecycle_status === self::STATUS_READY;
    }

    public function canResolvePinnedReservation(): bool
    {
        if ($this->lifecycle_status === self::STATUS_READY) {
            return true;
        }

        return $this->lifecycle_status === self::STATUS_DRAINING
            && $this->resolve_until !== null
            && $this->resolve_until->isFuture();
    }

    public function maskedCredential(): ?string
    {
        return $this->credential_suffix === null ? null : '••••'.$this->credential_suffix;
    }
}
