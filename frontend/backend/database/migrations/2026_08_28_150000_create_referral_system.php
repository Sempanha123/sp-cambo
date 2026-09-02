<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('referral_code', 32)->nullable()->unique()->after('tenant_id');
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
            $table->timestamp('referred_at')->nullable()->after('referred_by_user_id');
            $table->index(['referred_by_user_id', 'created_at'], 'users_referrer_created_idx');
        });

        Schema::create('referral_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('commission_bps')->default(1000);
            $table->unsignedInteger('referred_bonus_bps')->default(500);
            $table->unsignedBigInteger('minimum_order_minor')->default(100);
            $table->unsignedInteger('cookie_days')->default(30);
            $table->unsignedInteger('reward_expiry_days')->default(90);
            $table->boolean('commission_all_orders')->default(true);
            $table->boolean('referred_bonus_first_order_only')->default(true);
            $table->timestamps();
        });

        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('status', 24)->default('EARNED')->index();
            $table->unsignedBigInteger('order_total_minor');
            $table->unsignedBigInteger('referrer_reward_minor')->default(0);
            $table->unsignedBigInteger('referred_bonus_minor')->default(0);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->foreignUlid('referrer_entitlement_lot_id')->nullable()->constrained('entitlement_lots')->nullOnDelete();
            $table->foreignUlid('referred_entitlement_lot_id')->nullable()->constrained('entitlement_lots')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['referrer_user_id', 'created_at'], 'referral_rewards_referrer_created_idx');
            $table->index(['referred_user_id', 'created_at'], 'referral_rewards_referred_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referral_settings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_referrer_created_idx');
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referred_at']);
        });
    }
};
