<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramNotificationSetting extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'event_routes' => 'array',
            'qr_countdown_enabled' => 'boolean',
            'qr_countdown_interval_seconds' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
