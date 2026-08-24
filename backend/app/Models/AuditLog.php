<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Audit logs are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Audit logs are immutable.'));
    }
}
