<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_purchases', 'delivery_secret_ciphertext')) {
            Schema::table('telegram_purchases', function (Blueprint $table) {
                $table->text('delivery_secret_ciphertext')->nullable()->after('last_error');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('telegram_purchases', 'delivery_secret_ciphertext')) {
            Schema::table('telegram_purchases', function (Blueprint $table) {
                $table->dropColumn('delivery_secret_ciphertext');
            });
        }
    }
};
