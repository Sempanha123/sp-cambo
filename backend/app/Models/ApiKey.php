<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ApiKey extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'user_id', 'label', 'prefix', 'last_four', 'lookup_digest', 'status', 'requests_per_minute', 'tokens_per_minute', 'concurrency_limit', 'max_request_bytes', 'max_output_tokens', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['lookup_digest'];

    protected function casts(): array
    {
        return ['last_used_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function modelAliases(): BelongsToMany
    {
        return $this->belongsToMany(ModelAlias::class);
    }

    protected static function booted(): void
    {
        static::updating(function (ApiKey $key): void {
            if ($key->isDirty('status') && $key->status === 'REVOKED' && $key->revoked_at === null) {
                $key->revoked_at = now();
            }
        });
    }
}
