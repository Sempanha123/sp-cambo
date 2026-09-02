<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Upgrade the earlier route-pool schema instead of creating the same
         * tables a second time. This supports both fresh installs and databases
         * that already ran the first route-pool migration.
         */
        if (! Schema::hasColumn('model_route_pools', 'max_failover_attempts')) {
            Schema::table('model_route_pools', function (Blueprint $table): void {
                $table->unsignedTinyInteger('max_failover_attempts')
                    ->default(2)
                    ->after('max_concurrency');
            });
        }

        if (! Schema::hasColumn('model_route_pools', 'circuit_failure_threshold')) {
            Schema::table('model_route_pools', function (Blueprint $table): void {
                $table->unsignedTinyInteger('circuit_failure_threshold')
                    ->default(3)
                    ->after('max_failover_attempts');
            });
        }

        if (! Schema::hasColumn('model_route_pools', 'circuit_cooldown_seconds')) {
            Schema::table('model_route_pools', function (Blueprint $table): void {
                $table->unsignedSmallInteger('circuit_cooldown_seconds')
                    ->default(30)
                    ->after('circuit_failure_threshold');
            });
        }

        DB::table('model_route_pools')
            ->where('strategy', 'LEAST_CONNECTIONS')
            ->update(['strategy' => 'WEIGHTED_LEAST_CONNECTIONS']);

        if (! Schema::hasColumn('model_route_pool_entries', 'ai_model_id')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->foreignId('ai_model_id')
                    ->nullable()
                    ->after('model_route_pool_id')
                    ->constrained('ai_models')
                    ->restrictOnDelete();
            });
        }

        /*
         * Legacy entries belong to the private model already attached to the
         * public alias. Backfill that relationship before enabling cross-provider
         * targets and the new composite uniqueness rule.
         */
        $legacyEntries = DB::table('model_route_pool_entries as entries')
            ->join('model_route_pools as pools', 'pools.id', '=', 'entries.model_route_pool_id')
            ->join('model_aliases as aliases', 'aliases.id', '=', 'pools.model_alias_id')
            ->whereNull('entries.ai_model_id')
            ->select(['entries.id', 'aliases.ai_model_id'])
            ->get();

        foreach ($legacyEntries as $entry) {
            DB::table('model_route_pool_entries')
                ->where('id', $entry->id)
                ->update(['ai_model_id' => $entry->ai_model_id]);
        }

        if (Schema::hasIndex('model_route_pool_entries', 'model_route_pool_revision_unique')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->dropUnique('model_route_pool_revision_unique');
            });
        }

        if (! Schema::hasIndex('model_route_pool_entries', 'model_route_pool_target_unique')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->unique(
                    ['model_route_pool_id', 'ai_model_id', 'provider_connection_revision_id'],
                    'model_route_pool_target_unique'
                );
            });
        }

        if (! Schema::hasIndex('model_route_pool_entries', 'model_route_pool_revision_enabled_idx')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->index(
                    ['provider_connection_revision_id', 'enabled'],
                    'model_route_pool_revision_enabled_idx'
                );
            });
        }

        if (! Schema::hasTable('provider_route_health')) {
            Schema::create('provider_route_health', function (Blueprint $table): void {
                $table->id();
                $table->ulid('provider_connection_revision_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('circuit_open_until')->nullable()->index();
                $table->timestamp('last_failure_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->string('last_error_code', 100)->nullable();
                $table->timestamps();

                $table->foreign('provider_connection_revision_id')
                    ->references('id')
                    ->on('provider_connection_revisions')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('reservations', 'model_route_pool_entry_id')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->foreignId('model_route_pool_entry_id')
                    ->nullable()
                    ->after('provider_connection_revision_id')
                    ->constrained('model_route_pool_entries')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasIndex('reservations', 'reservations_status_route_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->index(
                    ['status', 'provider_connection_revision_id'],
                    'reservations_status_route_idx'
                );
            });
        }

        if (! Schema::hasIndex('reservations', 'reservations_status_alias_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->index(
                    ['status', 'public_model_alias'],
                    'reservations_status_alias_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('reservations', 'reservations_status_alias_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropIndex('reservations_status_alias_idx');
            });
        }

        if (Schema::hasIndex('reservations', 'reservations_status_route_idx')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropIndex('reservations_status_route_idx');
            });
        }

        if (Schema::hasColumn('reservations', 'model_route_pool_entry_id')) {
            Schema::table('reservations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('model_route_pool_entry_id');
            });
        }

        Schema::dropIfExists('provider_route_health');

        if (Schema::hasIndex('model_route_pool_entries', 'model_route_pool_revision_enabled_idx')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->dropIndex('model_route_pool_revision_enabled_idx');
            });
        }

        if (Schema::hasIndex('model_route_pool_entries', 'model_route_pool_target_unique')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->dropUnique('model_route_pool_target_unique');
            });
        }

        if (! Schema::hasIndex('model_route_pool_entries', 'model_route_pool_revision_unique')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->unique(
                    ['model_route_pool_id', 'provider_connection_revision_id'],
                    'model_route_pool_revision_unique'
                );
            });
        }

        if (Schema::hasColumn('model_route_pool_entries', 'ai_model_id')) {
            Schema::table('model_route_pool_entries', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('ai_model_id');
            });
        }

        foreach ([
            'circuit_cooldown_seconds',
            'circuit_failure_threshold',
            'max_failover_attempts',
        ] as $column) {
            if (Schema::hasColumn('model_route_pools', $column)) {
                Schema::table('model_route_pools', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        DB::table('model_route_pools')
            ->where('strategy', 'WEIGHTED_LEAST_CONNECTIONS')
            ->update(['strategy' => 'LEAST_CONNECTIONS']);
    }
};
