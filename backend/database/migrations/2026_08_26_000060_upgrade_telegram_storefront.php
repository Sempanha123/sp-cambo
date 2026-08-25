<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration may be re-run after a partial MySQL failure. Add only
        // Telegram account columns that are not already present.
        if (! Schema::hasColumn('telegram_accounts', 'locale')) {
            Schema::table('telegram_accounts', function (Blueprint $table): void {
                $table->char('locale', 2)->default('en')->after('username');
            });
        }

        if (! Schema::hasColumn('telegram_accounts', 'announcements_enabled')) {
            Schema::table('telegram_accounts', function (Blueprint $table): void {
                $table->boolean('announcements_enabled')->default(true)->after('locale')->index();
            });
        }

        if (! Schema::hasColumn('telegram_accounts', 'last_seen_at')) {
            Schema::table('telegram_accounts', function (Blueprint $table): void {
                $table->timestamp('last_seen_at')->nullable()->after('linked_at');
            });
        }

        if (! Schema::hasTable('telegram_announcements')) {
            Schema::create('telegram_announcements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('event_key', 191)->unique();
                $table->string('kind', 40)->index();
                $table->string('title', 180);
                $table->text('body');
                $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
                $table->foreignId('model_alias_id')->nullable()->constrained('model_aliases')->nullOnDelete();
                $table->string('status', 24)->default('QUEUED')->index();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        // A failed CREATE can leave this table behind before Laravel records
        // the migration. It cannot contain valid application data yet, so
        // rebuild only this incomplete table before applying short FK names.
        if (Schema::hasTable('telegram_announcement_deliveries')) {
            Schema::drop('telegram_announcement_deliveries');
        }

        Schema::create('telegram_announcement_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('telegram_announcement_id');
            $table->ulid('telegram_account_id');
            $table->string('status', 24)->default('PENDING')->index();
            $table->timestamp('attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            // Keep explicit names below MySQL's 64-character identifier limit.
            $table->foreign('telegram_announcement_id', 'tg_ann_delivery_announcement_fk')
                ->references('id')
                ->on('telegram_announcements')
                ->cascadeOnDelete();
            $table->foreign('telegram_account_id', 'tg_ann_delivery_account_fk')
                ->references('id')
                ->on('telegram_accounts')
                ->cascadeOnDelete();
            $table->unique(
                ['telegram_announcement_id', 'telegram_account_id'],
                'tg_announcement_account_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_announcement_deliveries');
        Schema::dropIfExists('telegram_announcements');

        if (Schema::hasColumn('telegram_accounts', 'announcements_enabled')) {
            Schema::table('telegram_accounts', function (Blueprint $table): void {
                $table->dropIndex(['announcements_enabled']);
            });
        }

        $columns = array_values(array_filter(
            ['locale', 'announcements_enabled', 'last_seen_at'],
            fn (string $column): bool => Schema::hasColumn('telegram_accounts', $column)
        ));

        if ($columns !== []) {
            Schema::table('telegram_accounts', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
