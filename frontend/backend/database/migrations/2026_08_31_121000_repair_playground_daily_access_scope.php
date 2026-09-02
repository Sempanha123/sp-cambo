<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Historical PLAYGROUND_DAILY lots could inherit ACCOUNT from the
         * access_scope column's database default. The source type is already the
         * authoritative proof that these lots belong only to Playground, so repair
         * them once and keep all balance/read paths consistent.
         */
        if (! Schema::hasTable('entitlement_lots')
            || ! Schema::hasColumn('entitlement_lots', 'access_scope')) {
            return;
        }

        DB::table('entitlement_lots')
            ->where('source_type', 'PLAYGROUND_DAILY')
            ->where(function ($query): void {
                $query->whereNull('access_scope')
                    ->orWhere('access_scope', '!=', 'PLAYGROUND');
            })
            ->update([
                'access_scope' => 'PLAYGROUND',
                'bound_api_key_id' => null,
                'fulfillment_claim_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible. Returning repaired daily
        // Playground lots to ACCOUNT scope could expose them to customer API keys.
    }
};
