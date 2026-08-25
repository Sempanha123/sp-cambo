<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('playground_settings')) {
            Schema::create('playground_settings', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('daily_token_quota')->default(20000);
                $table->unsignedInteger('max_output_tokens')->default(2048);
                $table->json('allowed_model_aliases')->nullable();
                $table->timestamps();
            });
        }

        DB::table('playground_settings')->updateOrInsert(
            ['id' => 1],
            [
                'enabled' => true,
                'daily_token_quota' => 20000,
                'max_output_tokens' => 2048,
                'allowed_model_aliases' => json_encode([], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_settings');
    }
};
