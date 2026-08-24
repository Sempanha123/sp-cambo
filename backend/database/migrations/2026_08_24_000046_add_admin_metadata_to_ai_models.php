<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->string('display_name', 150)->nullable()->after('internal_model_id');
            $table->json('capabilities')->nullable()->after('family_label');
            $table->json('limits')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'capabilities', 'limits']);
        });
    }
};
