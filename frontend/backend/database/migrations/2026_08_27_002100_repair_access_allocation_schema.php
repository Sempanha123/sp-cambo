<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Repair checkpoint for operator databases that may already have the
        // original Fix 7 migration recorded from an earlier test archive.
        if (! Schema::hasColumn('entitlement_lots', 'access_scope')) {
            Schema::table('entitlement_lots', fn (Blueprint $table) => $table->string('access_scope', 20)->default('ACCOUNT')->after('status'));
        }
        if (! Schema::hasColumn('entitlement_lots', 'bound_api_key_id')) {
            Schema::table('entitlement_lots', fn (Blueprint $table) => $table->foreignUlid('bound_api_key_id')->nullable()->after('access_scope')->constrained('api_keys')->restrictOnDelete());
        }
        if (! Schema::hasColumn('entitlement_lots', 'fulfillment_claim_id')) {
            Schema::table('entitlement_lots', fn (Blueprint $table) => $table->foreignUlid('fulfillment_claim_id')->nullable()->after('bound_api_key_id')->constrained('fulfillment_claims')->restrictOnDelete());
        }
        if (! Schema::hasColumn('fulfillment_claims', 'delivery_mode')) {
            Schema::table('fulfillment_claims', fn (Blueprint $table) => $table->string('delivery_mode', 20)->nullable()->after('api_key_id'));
        }

        $this->addIndexSafely('entitlement_lots', ['access_scope'], 'entitlement_lots_access_scope_index');
        $this->addIndexSafely('entitlement_lots', ['user_id', 'access_scope', 'bound_api_key_id', 'status'], 'entitlement_user_access_key_status');
        $this->addIndexSafely('fulfillment_claims', ['delivery_mode'], 'fulfillment_claims_delivery_mode_index');
        $this->addIndexSafely('usage_records', ['api_key_id', 'settled_at'], 'usage_key_settled');
        $this->addIndexSafely('api_request_logs', ['api_key_id', 'started_at'], 'request_key_started');
    }

    public function down(): void
    {
        // Intentionally no-op. This migration repairs structure owned by the
        // preceding access-allocation migration; rolling this checkpoint back
        // must never delete columns that may contain customer allocation data.
    }

    /** @param array<int,string> $columns */
    private function addIndexSafely(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (! str_contains($message, 'duplicate key name')
                && ! str_contains($message, 'already exists')) {
                throw $exception;
            }
        }
    }
};
