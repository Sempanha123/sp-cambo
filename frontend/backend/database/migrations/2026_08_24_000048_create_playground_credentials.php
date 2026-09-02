<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DDL can succeed before Laravel records the migration row (for example
        // after a worker/process crash). Treat an existing table as completed so
        // a deployment replay does not fail or destroy hosted credentials.
        if (Schema::hasTable('playground_credentials')) {
            return;
        }

        Schema::create('playground_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('api_key_id')->unique()->constrained('api_keys')->restrictOnDelete();
            $table->text('secret_ciphertext');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_credentials');
    }
};
