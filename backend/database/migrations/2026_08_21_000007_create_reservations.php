<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->restrictOnDelete();
            $table->string('public_model_alias', 100);
            $table->string('billing_mode', 30);
            $table->unsignedBigInteger('reserved_units');
            $table->unsignedBigInteger('settled_units')->nullable();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('entitlement_lot_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('reserved_units');
            $table->unsignedBigInteger('settled_units')->default(0);
            $table->timestamps();
            $table->unique(['reservation_id', 'entitlement_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_allocations');
        Schema::dropIfExists('reservations');
    }
};
