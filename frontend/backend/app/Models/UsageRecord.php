<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use HasUlids;

    protected $guarded = [];

    // Cost/profit is operator-only business data. Customer-facing controllers
    // already use explicit resources; this hidden guard prevents accidental
    // serialization of the private reference-cost estimate if this model is ever returned directly.
    protected $hidden = ['upstream_cost_minor'];

    protected function casts(): array
    {
        return ['settled_at' => 'immutable_datetime'];
    }
}
