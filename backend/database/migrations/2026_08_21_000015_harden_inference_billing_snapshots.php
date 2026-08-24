<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_pricing', function (Blueprint $table): void {
            $table->unsignedBigInteger('reasoning_per_million_minor')->nullable();
            $table->unsignedBigInteger('upstream_reasoning_per_million_minor')->nullable();
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->json('billing_rules')->nullable();
        });

        Schema::table('entitlement_lots', function (Blueprint $table): void {
            $table->char('billing_snapshot_hash', 64)->nullable()->index();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->json('billing_snapshot')->nullable();
            $table->string('reconciliation_reason', 100)->nullable();
            $table->timestamp('reconciliation_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['billing_snapshot', 'reconciliation_reason', 'reconciliation_requested_at']);
        });

        Schema::table('entitlement_lots', function (Blueprint $table): void {
            $table->dropIndex(['billing_snapshot_hash']);
        });

        Schema::table('entitlement_lots', function (Blueprint $table): void {
            $table->dropColumn('billing_snapshot_hash');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('billing_rules');
        });

        Schema::table('model_pricing', function (Blueprint $table): void {
            $table->dropColumn(['reasoning_per_million_minor', 'upstream_reasoning_per_million_minor']);
        });
    }
};
