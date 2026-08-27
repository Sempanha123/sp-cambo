<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_purchases', 'delivery_lease_token')) {
            Schema::table('telegram_purchases', function (Blueprint $table): void {
                $table->char('delivery_lease_token', 64)->nullable()->after('delivery_secret_ciphertext');
            });
        }

        if (! Schema::hasColumn('telegram_purchases', 'delivery_lease_expires_at')) {
            Schema::table('telegram_purchases', function (Blueprint $table): void {
                $table->timestamp('delivery_lease_expires_at')->nullable()->after('delivery_lease_token');
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['delivery_lease_token', 'delivery_lease_expires_at'],
            fn (string $column): bool => Schema::hasColumn('telegram_purchases', $column),
        ));

        if ($columns !== []) {
            Schema::table('telegram_purchases', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
