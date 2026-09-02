<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationAllocation extends Model
{
    protected $guarded = [];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(EntitlementLot::class, 'entitlement_lot_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
