<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_announcements')) {
            if (! Schema::hasColumn('telegram_announcements', 'metadata')) {
                Schema::table('telegram_announcements', function (Blueprint $table): void {
                    $table->json('metadata')->nullable()->after('body');
                });
            }

            if (! Schema::hasColumn('telegram_announcements', 'excluded_telegram_account_id')) {
                Schema::table('telegram_announcements', function (Blueprint $table): void {
                    $table->ulid('excluded_telegram_account_id')->nullable()->after('model_alias_id');
                });
            }

            if (! Schema::hasForeignKey('telegram_announcements', ['excluded_telegram_account_id'])) {
                Schema::table('telegram_announcements', function (Blueprint $table): void {
                    $table->foreign('excluded_telegram_account_id', 'tg_ann_excluded_account_fk')
                        ->references('id')
                        ->on('telegram_accounts')
                        ->nullOnDelete();
                });
            }
        }

        // R13 intentionally preserves R12 private ADMIN purchase alerts.
        // Do not mutate or cancel historical/queued operator rows here.
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_announcements')) {
            return;
        }

        if (Schema::hasForeignKey('telegram_announcements', ['excluded_telegram_account_id'])) {
            Schema::table('telegram_announcements', function (Blueprint $table): void {
                $table->dropForeign('tg_ann_excluded_account_fk');
            });
        }

        $columns = array_values(array_filter(
            ['metadata', 'excluded_telegram_account_id'],
            fn (string $column): bool => Schema::hasColumn('telegram_announcements', $column),
        ));

        if ($columns !== []) {
            Schema::table('telegram_announcements', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
