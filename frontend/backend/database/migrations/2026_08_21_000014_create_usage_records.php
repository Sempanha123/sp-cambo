<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->restrictOnDelete();
            $table->string('public_model', 100)->index();
            $table->string('endpoint', 100);
            $table->string('state', 20)->index();
            $table->unsignedBigInteger('estimated_units')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('usage_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->restrictOnDelete();
            $table->string('public_model', 100)->index();
            $table->string('endpoint', 100);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('reasoning_tokens')->default(0);
            $table->unsignedBigInteger('metered_units');
            $table->unsignedBigInteger('credit_charge_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedTinyInteger('currency_exponent')->nullable();
            $table->timestamp('settled_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('api_request_logs');
    }
};
