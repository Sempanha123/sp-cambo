<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('internal_model_id', 191);
            $table->string('family', 100)->index();
            $table->string('family_label', 100);
            $table->timestamp('commercial_resale_verified_at')->nullable()->index();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();
            $table->unique(['provider_id', 'internal_model_id']);
        });

        Schema::create('model_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_id')->constrained()->restrictOnDelete();
            $table->string('public_alias', 100)->unique();
            $table->string('display_name', 150);
            $table->text('description')->nullable();
            $table->json('capabilities');
            $table->json('limits');
            $table->string('status', 20)->default('unavailable');
            $table->boolean('enabled')->default(false)->index();
            $table->boolean('customer_visible')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('model_pricing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_alias_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('exponent')->default(2);
            $table->unsignedBigInteger('input_per_million_minor');
            $table->unsignedBigInteger('output_per_million_minor');
            $table->unsignedBigInteger('cache_read_per_million_minor')->nullable();
            $table->unsignedBigInteger('cache_write_per_million_minor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_pricing');
        Schema::dropIfExists('model_aliases');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('providers');
    }
};
