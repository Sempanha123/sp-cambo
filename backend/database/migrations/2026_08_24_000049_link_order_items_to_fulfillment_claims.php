<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'fulfillment_claim_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->foreignUlid('fulfillment_claim_id')
                    ->nullable()
                    ->after('package_snapshot')
                    ->constrained('fulfillment_claims')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'fulfillment_claim_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropForeign(['fulfillment_claim_id']);
                $table->dropColumn('fulfillment_claim_id');
            });
        }
    }
};
