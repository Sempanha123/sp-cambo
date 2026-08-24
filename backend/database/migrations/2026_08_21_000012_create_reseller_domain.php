<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING')->index();
            $table->unsignedInteger('minimum_markup_bps')->default(0);
            $table->unsignedInteger('maximum_markup_bps')->default(10000);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reseller_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reseller_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('label', 150);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
            $table->index(['reseller_user_id', 'status']);
        });

        Schema::create('reseller_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('reseller_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('billing_mode', 30);
            $table->string('public_model_alias', 100);
            $table->unsignedBigInteger('units');
            $table->string('idempotency_key', 191)->unique();
            $table->text('reason');
            $table->timestamps();
        });

        Schema::create('reseller_transfer_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('reseller_transfer_id')->constrained('reseller_transfers')->cascadeOnDelete();
            $table->foreignUlid('source_entitlement_lot_id')->constrained('entitlement_lots')->restrictOnDelete();
            $table->foreignUlid('target_entitlement_lot_id')->constrained('entitlement_lots')->restrictOnDelete();
            $table->unsignedBigInteger('units');
            $table->unique(['reseller_transfer_id', 'source_entitlement_lot_id'], 'reseller_transfer_source_unique');
        });

        Schema::create('reseller_management_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('prefix', 16);
            $table->char('last_four', 4);
            $table->char('lookup_digest', 64)->unique();
            $table->json('scopes');
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_management_keys');
        Schema::dropIfExists('reseller_transfer_allocations');
        Schema::dropIfExists('reseller_transfers');
        Schema::dropIfExists('reseller_customers');
        Schema::dropIfExists('reseller_profiles');
    }
};
