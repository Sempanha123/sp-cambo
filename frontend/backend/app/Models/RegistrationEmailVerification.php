<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationEmailVerification extends Model
{
    protected $fillable = [
        'email',
        'code_hash',
        'attempts',
        'last_sent_at',
        'expires_at',
        'verified_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
