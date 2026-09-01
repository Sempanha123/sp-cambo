<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_route_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_alias_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->string('strategy', 40)->default('WEIGHTED_LEAST_CONNECTIONS');
            $table->unsignedInteger('max_concurrency')->nullable();
            $table->unsignedTinyInteger('max_failover_attempts')->default(2);
            $table->unsignedTinyInteger('circuit_failure_threshold')->default(3);
            $table->unsignedSmallInteger('circuit_cooldown_seconds')->default(30);
            $table->timestamps();
        });

        Schema::create('model_route_pool_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_route_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained()->restrictOnDelete();
            $table->ulid('provider_connection_revision_id');
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('weight')->default(100);
            $table->unsignedInteger('max_concurrency')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->foreign('provider_connection_revision_id')
                ->references('id')
                ->on('provider_connection_revisions')
                ->restrictOnDelete();

            $table->unique(
                ['model_route_pool_id', 'ai_model_id', 'provider_connection_revision_id'],
                'model_route_pool_target_unique'
            );
            $table->index(
                ['provider_connection_revision_id', 'enabled'],
                'model_route_pool_revision_enabled_idx'
            );
        });

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

        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignId('model_route_pool_entry_id')
                ->nullable()
                ->after('provider_connection_revision_id')
                ->constrained('model_route_pool_entries')
                ->nullOnDelete();

            $table->index(
                ['status', 'provider_connection_revision_id'],
                'reservations_status_route_idx'
            );
            $table->index(
                ['status', 'public_model_alias'],
                'reservations_status_alias_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_status_alias_idx');
            $table->dropIndex('reservations_status_route_idx');
            $table->dropConstrainedForeignId('model_route_pool_entry_id');
        });

        Schema::dropIfExists('provider_route_health');
        Schema::dropIfExists('model_route_pool_entries');
        Schema::dropIfExists('model_route_pools');
    }
};
