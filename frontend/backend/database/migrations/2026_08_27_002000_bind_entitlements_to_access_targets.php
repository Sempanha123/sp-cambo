<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration deliberately tolerates a partially-applied local upgrade.
        // MySQL DDL auto-commits, so an interrupted migration can leave the first
        // column behind while Laravel correctly leaves the migration itself pending.
        // Guard every structural step so a rerun repairs that state instead of
        // failing on "duplicate column" and leaving customer pages on HTTP 500.
        if (! Schema::hasColumn('entitlement_lots', 'access_scope')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->string('access_scope', 20)->default('ACCOUNT')->after('status');
            });
        }

        if (! Schema::hasColumn('entitlement_lots', 'bound_api_key_id')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->foreignUlid('bound_api_key_id')->nullable()->after('access_scope')->constrained('api_keys')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('entitlement_lots', 'fulfillment_claim_id')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->foreignUlid('fulfillment_claim_id')->nullable()->after('bound_api_key_id')->constrained('fulfillment_claims')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('fulfillment_claims', 'delivery_mode')) {
            Schema::table('fulfillment_claims', function (Blueprint $table): void {
                $table->string('delivery_mode', 20)->nullable()->after('api_key_id');
            });
        }

        $this->addIndexSafely('entitlement_lots', ['access_scope'], 'entitlement_lots_access_scope_index');
        $this->addIndexSafely('entitlement_lots', ['user_id', 'access_scope', 'bound_api_key_id', 'status'], 'entitlement_user_access_key_status');
        $this->addIndexSafely('fulfillment_claims', ['delivery_mode'], 'fulfillment_claims_delivery_mode_index');
        $this->addIndexSafely('usage_records', ['api_key_id', 'settled_at'], 'usage_key_settled');
        $this->addIndexSafely('api_request_logs', ['api_key_id', 'started_at'], 'request_key_started');
    }

    public function down(): void
    {
        $this->dropIndexSafely('api_request_logs', 'request_key_started');
        $this->dropIndexSafely('usage_records', 'usage_key_settled');
        $this->dropIndexSafely('fulfillment_claims', 'fulfillment_claims_delivery_mode_index');
        $this->dropIndexSafely('entitlement_lots', 'entitlement_user_access_key_status');
        $this->dropIndexSafely('entitlement_lots', 'entitlement_lots_access_scope_index');

        if (Schema::hasColumn('fulfillment_claims', 'delivery_mode')) {
            Schema::table('fulfillment_claims', function (Blueprint $table): void {
                $table->dropColumn('delivery_mode');
            });
        }

        if (Schema::hasColumn('entitlement_lots', 'fulfillment_claim_id')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->dropForeign(['fulfillment_claim_id']);
                $table->dropColumn('fulfillment_claim_id');
            });
        }
        if (Schema::hasColumn('entitlement_lots', 'bound_api_key_id')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->dropForeign(['bound_api_key_id']);
                $table->dropColumn('bound_api_key_id');
            });
        }
        if (Schema::hasColumn('entitlement_lots', 'access_scope')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->dropColumn('access_scope');
            });
        }
    }

    /** @param array<int,string> $columns */
    private function addIndexSafely(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->index($columns, $name);
            });
        } catch (QueryException $exception) {
            if (! $this->isAlreadyExistsError($exception)) {
                throw $exception;
            }
        }
    }

    private function dropIndexSafely(string $table, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (! str_contains($message, 'check that column/key exists')
                && ! str_contains($message, 'no such index')
                && ! str_contains($message, 'does not exist')) {
                throw $exception;
            }
        }
    }

    private function isAlreadyExistsError(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate column name');
    }
};
