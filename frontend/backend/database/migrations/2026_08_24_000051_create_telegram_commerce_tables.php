<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('telegram_user_id', 32)->unique();
            $table->string('chat_id', 32)->unique();
            $table->string('username')->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'revoked_at']);
        });

        Schema::create('telegram_link_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_digest', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('telegram_purchases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('telegram_account_id')->constrained('telegram_accounts')->cascadeOnDelete();
            $table->foreignUlid('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('fulfillment_claim_id')->nullable()->constrained('fulfillment_claims')->nullOnDelete();
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('status', 32)->default('AWAITING_PAYMENT');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_checked_at']);
            $table->index(['telegram_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_purchases');
        Schema::dropIfExists('telegram_link_tokens');
        Schema::dropIfExists('telegram_accounts');
    }
};
