<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUlids;

    protected $fillable = ['name'];

    protected $keyType = 'string';

    public $incrementing = false;

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
