<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_settings', function (Blueprint $table): void {
            $table->boolean('registration_reward_enabled')->default(true)->after('enabled');
            $table->timestamp('registration_reward_started_at')->nullable()->after('registration_reward_enabled');
            $table->string('registration_reward_mode', 24)->default('CREDIT_BALANCE')->after('registration_reward_enabled');
            $table->unsignedBigInteger('registration_credit_minor')->default(25)->after('registration_reward_mode');
            $table->unsignedBigInteger('registration_token_units')->default(25000)->after('registration_credit_minor');
            $table->json('registration_reward_model_aliases')->nullable()->after('registration_token_units');
        });

        DB::table('referral_settings')->whereNull('registration_reward_started_at')->update(['registration_reward_started_at' => now()]);

        Schema::create('referral_registration_rewards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('EARNED')->index();
            $table->string('reward_mode', 24);
            $table->unsignedBigInteger('reward_units');
            $table->char('currency', 3)->nullable();
            $table->unsignedTinyInteger('currency_exponent')->nullable();
            $table->json('allowed_model_aliases')->nullable();
            $table->foreignUlid('entitlement_lot_id')->nullable()->constrained('entitlement_lots')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['referrer_user_id', 'created_at'], 'referral_registration_referrer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_registration_rewards');
        Schema::table('referral_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_reward_enabled',
                'registration_reward_started_at',
                'registration_reward_mode',
                'registration_credit_minor',
                'registration_token_units',
                'registration_reward_model_aliases',
            ]);
        });
    }
};
