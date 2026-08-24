<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_lots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->string('source_id', 100)->nullable();
            $table->string('package_name', 150);
            $table->string('family_label', 100);
            $table->string('billing_mode', 30)->index();
            $table->unsignedBigInteger('original_units');
            $table->unsignedBigInteger('remaining_units');
            $table->unsignedBigInteger('reserved_units')->default(0);
            $table->string('unit_label', 50);
            $table->char('currency', 3)->nullable();
            $table->unsignedTinyInteger('currency_exponent')->nullable();
            $table->json('allowed_model_aliases');
            $table->json('billing_snapshot');
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'billing_mode', 'status', 'expires_at']);
        });

        Schema::create('credit_ledger', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('entitlement_lot_id')->nullable()->constrained('entitlement_lots')->restrictOnDelete();
            $table->string('type', 40)->index();
            $table->bigInteger('amount');
            $table->string('idempotency_key', 191)->unique();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('entitlement_lots');
    }
};
