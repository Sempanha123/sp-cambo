<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'client_key', 'title', 'model_alias', 'system_prompt', 'messages', 'message_count', 'last_message_at', 'expires_at'])]
class PlaygroundChat extends Model
{
    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
