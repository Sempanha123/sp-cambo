<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerManagementKey extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'label', 'prefix', 'last_four', 'lookup_digest', 'scopes', 'status', 'last_used_at', 'expires_at'];

    protected $hidden = ['lookup_digest'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
