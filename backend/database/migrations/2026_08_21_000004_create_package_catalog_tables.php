<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->string('subtitle', 255)->nullable();
            $table->string('badge', 100)->nullable();
            $table->string('billing_mode', 20)->index();
            $table->string('family', 100)->index();
            $table->string('family_label', 100);
            $table->unsignedBigInteger('advertised_units');
            $table->string('unit_label', 50);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('compare_at_price_minor')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('currency_exponent')->default(2);
            $table->unsignedBigInteger('duration_seconds');
            $table->json('limits');
            $table->boolean('auto_creates_api_key')->default(false);
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(false)->index();
            $table->boolean('customer_visible')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('model_alias_package', function (Blueprint $table): void {
            $table->foreignId('model_alias_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->primary(['model_alias_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_alias_package');
        Schema::dropIfExists('packages');
    }
};
