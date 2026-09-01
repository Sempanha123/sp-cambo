<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('model_route_pools')) {
            Schema::create('model_route_pools', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('model_alias_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('enabled')->default(false)->index();
                $table->string('strategy', 40)->default('LEAST_CONNECTIONS');
                $table->unsignedInteger('max_concurrency')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('model_route_pool_entries')) {
            Schema::create('model_route_pool_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('model_route_pool_id')->constrained()->cascadeOnDelete();
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
                    ['model_route_pool_id', 'provider_connection_revision_id'],
                    'model_route_pool_revision_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('model_route_pool_entries');
        Schema::dropIfExists('model_route_pools');
    }
};
