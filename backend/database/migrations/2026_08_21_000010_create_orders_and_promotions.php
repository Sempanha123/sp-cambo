<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label', 150);
            $table->string('type', 30);
            $table->unsignedInteger('percentage_bps')->nullable();
            $table->unsignedBigInteger('fixed_discount_minor')->nullable();
            $table->unsignedBigInteger('price_override_minor')->nullable();
            $table->unsignedBigInteger('bonus_units')->nullable();
            $table->unsignedBigInteger('minimum_order_minor')->default(0);
            $table->unsignedBigInteger('maximum_discount_minor')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->boolean('new_customer_only')->default(false);
            $table->boolean('stackable')->default(false);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('package_promotion', function (Blueprint $table): void {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->primary(['package_id', 'promotion_id']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('status', 30)->default('PENDING_PAYMENT')->index();
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_total_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->json('promotion_snapshot')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('package_slug', 100);
            $table->string('package_name', 150);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->json('package_snapshot');
            $table->timestamps();
        });

        Schema::create('promotion_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('bonus_units')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['promotion_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('package_promotion');
        Schema::dropIfExists('promotions');
    }
};
