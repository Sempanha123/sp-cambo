<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerTransfer extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function allocations(): HasMany
    {
        return $this->hasMany(ResellerTransferAllocation::class);
    }
}
