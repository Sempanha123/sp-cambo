<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settled_at' => 'immutable_datetime'];
    }
}
