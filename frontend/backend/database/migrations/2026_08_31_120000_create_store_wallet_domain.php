<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_wallets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('store_wallet_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_wallet_id')->constrained('store_wallets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('idempotency_key', 191)->unique();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('store_wallet_topups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('telegram_account_id')->nullable()->constrained('telegram_accounts')->nullOnDelete();
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 25)->unique();
            $table->string('status', 24)->default('PENDING')->index();
            $table->text('qr_payload');
            $table->char('qr_md5', 32)->index();
            $table->string('transaction_hash', 128)->nullable()->unique();
            $table->string('verification_lease_token', 64)->nullable();
            $table->timestamp('verification_lease_expires_at')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['telegram_account_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('telegram_purchases', function (Blueprint $table): void {
            $table->string('payment_method', 16)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_purchases', function (Blueprint $table): void {
            $table->dropIndex(['payment_method']);
            $table->dropColumn('payment_method');
        });

        Schema::dropIfExists('store_wallet_topups');
        Schema::dropIfExists('store_wallet_entries');
        Schema::dropIfExists('store_wallets');
    }
};
