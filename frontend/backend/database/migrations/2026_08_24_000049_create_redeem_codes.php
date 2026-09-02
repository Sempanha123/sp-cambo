<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redeem_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('code_digest', 64)->unique();
            $table->string('prefix', 16);
            $table->char('last_four', 4);
            $table->string('label', 150);
            $table->string('billing_mode', 30);
            $table->unsignedBigInteger('units');
            $table->unsignedInteger('duration_seconds');
            $table->json('allowed_model_aliases');
            $table->json('billing_rules')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('redeem_code_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('redeem_code_id')->constrained('redeem_codes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('entitlement_lot_id')->constrained('entitlement_lots')->restrictOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['redeem_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redeem_code_redemptions');
        Schema::dropIfExists('redeem_codes');
    }
};
