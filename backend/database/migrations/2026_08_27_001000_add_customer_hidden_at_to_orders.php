<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'customer_hidden_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->timestamp('customer_hidden_at')->nullable()->after('fulfilled_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'customer_hidden_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('customer_hidden_at');
            });
        }
    }
};
