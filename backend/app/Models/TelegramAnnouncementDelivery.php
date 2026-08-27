<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAnnouncementDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempted_at' => 'immutable_datetime', 'delivery_lease_expires_at' => 'immutable_datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(TelegramAnnouncement::class, 'telegram_announcement_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }
}
