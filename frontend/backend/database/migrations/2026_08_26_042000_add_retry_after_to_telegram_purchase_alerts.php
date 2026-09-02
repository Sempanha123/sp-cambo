<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_purchase_alerts')) return;
        if (! Schema::hasColumn('telegram_purchase_alerts', 'retry_after')) {
            Schema::table('telegram_purchase_alerts', function (Blueprint $table): void {
                $table->timestamp('retry_after')->nullable()->after('last_error')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_purchase_alerts') && Schema::hasColumn('telegram_purchase_alerts', 'retry_after')) {
            Schema::table('telegram_purchase_alerts', function (Blueprint $table): void {
                $table->dropIndex(['retry_after']);
                $table->dropColumn('retry_after');
            });
        }
    }
};
