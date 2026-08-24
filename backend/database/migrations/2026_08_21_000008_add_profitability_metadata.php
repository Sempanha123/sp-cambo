<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_pricing', function (Blueprint $table): void {
            $table->unsignedBigInteger('upstream_input_per_million_minor')->nullable();
            $table->unsignedBigInteger('upstream_output_per_million_minor')->nullable();
            $table->unsignedBigInteger('upstream_cache_read_per_million_minor')->nullable();
            $table->unsignedBigInteger('upstream_cache_write_per_million_minor')->nullable();
            $table->timestamp('upstream_cost_verified_at')->nullable();
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_margin_bps')->default(0);
            $table->text('profitability_override_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_pricing', function (Blueprint $table): void {
            $table->dropColumn(['upstream_input_per_million_minor', 'upstream_output_per_million_minor', 'upstream_cache_read_per_million_minor', 'upstream_cache_write_per_million_minor', 'upstream_cost_verified_at']);
        });
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['minimum_margin_bps', 'profitability_override_reason']);
        });
    }
};
