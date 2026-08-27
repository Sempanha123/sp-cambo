<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class AccessAllocationSchema
{
    /** @var array<string,array<int,string>> */
    private const REQUIRED_COLUMNS = [
        'entitlement_lots' => ['access_scope', 'bound_api_key_id', 'fulfillment_claim_id'],
        'fulfillment_claims' => ['delivery_mode'],
    ];

    public static function ready(): bool
    {
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<int,string> */
    public static function missing(): array
    {
        $missing = [];

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table.'.*';
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        return $missing;
    }

    /** @return array{message:string,code:string} */
    public static function errorPayload(): array
    {
        return [
            'message' => 'SP Cambo needs the latest database update before this feature can be used. Run php artisan migrate, then reload the page.',
            'code' => 'database_migration_required',
        ];
    }
}
