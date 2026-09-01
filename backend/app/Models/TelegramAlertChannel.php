<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramAlertChannel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
