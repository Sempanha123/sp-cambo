<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('prefix', 16);
            $table->char('last_four', 4);
            $table->char('lookup_digest', 64)->unique();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->unsignedInteger('requests_per_minute')->nullable();
            $table->unsignedBigInteger('tokens_per_minute')->nullable();
            $table->unsignedSmallInteger('concurrency_limit')->nullable();
            $table->unsignedInteger('max_request_bytes')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('api_key_model_alias', function (Blueprint $table): void {
            $table->foreignUlid('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->foreignId('model_alias_id')->constrained()->restrictOnDelete();
            $table->primary(['api_key_id', 'model_alias_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_model_alias');
        Schema::dropIfExists('api_keys');
    }
};
