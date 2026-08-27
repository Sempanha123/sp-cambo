<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_keys', 'secret_ciphertext')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->text('secret_ciphertext')->nullable()->after('lookup_digest');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_keys', 'secret_ciphertext')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->dropColumn('secret_ciphertext');
            });
        }
    }
};
