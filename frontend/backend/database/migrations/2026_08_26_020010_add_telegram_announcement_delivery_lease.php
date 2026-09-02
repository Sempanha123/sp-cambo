<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_announcement_deliveries', 'delivery_lease_token')) {
            Schema::table('telegram_announcement_deliveries', function (Blueprint $table): void {
                $table->char('delivery_lease_token', 64)->nullable()->after('status');
            });
        }
        if (! Schema::hasColumn('telegram_announcement_deliveries', 'delivery_lease_expires_at')) {
            Schema::table('telegram_announcement_deliveries', function (Blueprint $table): void {
                $table->timestamp('delivery_lease_expires_at')->nullable()->after('delivery_lease_token');
            });
        }
        if (! Schema::hasColumn('telegram_announcement_deliveries', 'attempt_count')) {
            Schema::table('telegram_announcement_deliveries', function (Blueprint $table): void {
                $table->unsignedSmallInteger('attempt_count')->default(0)->after('delivery_lease_expires_at');
            });
        }
        if (! $this->hasIndex('telegram_announcement_deliveries', 'tg_ann_delivery_lease_idx')) {
            Schema::table('telegram_announcement_deliveries', function (Blueprint $table): void {
                $table->index(['status', 'delivery_lease_expires_at'], 'tg_ann_delivery_lease_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('telegram_announcement_deliveries', 'tg_ann_delivery_lease_idx')) {
            Schema::table('telegram_announcement_deliveries', function (Blueprint $table): void {
                $table->dropIndex('tg_ann_delivery_lease_idx');
            });
        }
        foreach (['attempt_count', 'delivery_lease_expires_at', 'delivery_lease_token'] as $column) {
            if (Schema::hasColumn('telegram_announcement_deliveries', $column)) {
                Schema::table('telegram_announcement_deliveries', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);
    }
};
