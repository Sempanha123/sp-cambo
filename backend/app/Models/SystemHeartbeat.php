<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'component';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }
}
