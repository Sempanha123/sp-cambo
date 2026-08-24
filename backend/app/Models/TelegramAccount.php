<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramAccount extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['linked_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function purchases(): HasMany { return $this->hasMany(TelegramPurchase::class); }
}
