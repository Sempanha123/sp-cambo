<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrationIdentifierTest extends TestCase
{
    public function test_account_status_migration_rolls_back_cleanly_on_sqlite(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertTrue(Schema::hasColumn('users', 'status'));

        $exitCode = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_21_000001_add_account_status_to_users_table.php',
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse(Schema::hasColumn('users', 'status'));
        $this->assertTrue(Schema::hasColumn('users', 'name'));
        $this->assertTrue(Schema::hasColumn('users', 'email'));
    }

    public function test_billing_snapshot_migration_rolls_back_cleanly_on_sqlite(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertTrue(Schema::hasColumn('entitlement_lots', 'billing_snapshot_hash'));
        $this->assertTrue(Schema::hasColumn('reservations', 'billing_snapshot'));

        $exitCode = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_22_000019_widen_reservation_status.php',
            '--realpath' => false,
            '--force' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $exitCode = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_22_000018_add_usage_cost_observability.php',
            '--realpath' => false,
            '--force' => true,
        ]);
        $this->assertSame(0, $exitCode);

        foreach (['2026_08_21_000017_add_promotion_currency.php', '2026_08_21_000016_add_order_idempotency.php'] as $migration) {
            $exitCode = Artisan::call('migrate:rollback', [
                '--path' => "database/migrations/{$migration}",
                '--realpath' => false,
                '--force' => true,
            ]);
            $this->assertSame(0, $exitCode);
        }

        $exitCode = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_21_000015_harden_inference_billing_snapshots.php',
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse(Schema::hasColumn('entitlement_lots', 'billing_snapshot_hash'));
        $this->assertFalse(Schema::hasColumn('reservations', 'billing_snapshot'));
        $this->assertTrue(Schema::hasColumn('entitlement_lots', 'remaining_units'));
        $this->assertTrue(Schema::hasColumn('reservations', 'reserved_units'));
    }

    public function test_reservation_status_migration_widens_mysql_column_for_every_lifecycle_state(): void
    {
        $connection = app('db')->connection();
        $connection->useDefaultSchemaGrammar();
        $originalGrammar = $connection->getSchemaGrammar();
        $connection->setSchemaGrammar(new MySqlGrammar($connection));

        try {
            $migration = require database_path('migrations/2026_08_22_000019_widen_reservation_status.php');
            $queries = $connection->pretend(static function () use ($migration): void {
                $migration->up();
            });
            $statements = array_column($queries, 'query');

            $alterStatements = array_values(array_filter(
                $statements,
                static fn (string $statement): bool => str_contains(strtolower($statement), 'alter table `reservations`'),
            ));
            $this->assertCount(1, $alterStatements);
            $this->assertMatchesRegularExpression(
                '/alter table `reservations` modify `status` varchar\((\d+)\) not null default \'ACTIVE\'/i',
                $alterStatements[0],
            );

            preg_match('/modify `status` varchar\((\d+)\)/i', $alterStatements[0], $matches);
            $width = (int) ($matches[1] ?? 0);
            foreach (['ACTIVE', 'RECONCILIATION_REQUIRED', 'SETTLED', 'RELEASED', 'EXPIRED'] as $status) {
                $this->assertLessThanOrEqual($width, strlen($status), "Reservation status [{$status}] exceeds the MySQL column width.");
            }

            $rollbackQueries = $connection->pretend(static function () use ($migration): void {
                $migration->down();
            });
            $this->assertSame([], $rollbackQueries, 'Rollback must not narrow the column below a supported lifecycle state.');
        } finally {
            $connection->setSchemaGrammar($originalGrammar);
        }
    }

    public function test_telegram_storefront_migration_preserves_existing_delivery_data(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $announcementId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        $tenantId = (string) Str::ulid();
        $now = now();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Migration recovery tenant',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = DB::table('users')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Migration recovery user',
            'email' => 'migration-recovery@example.test',
            'password' => 'not-used',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('telegram_accounts')->insert([
            'id' => $accountId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'telegram_user_id' => '987654321',
            'chat_id' => '987654321',
            'username' => 'migration_recovery',
            'locale' => 'en',
            'announcements_enabled' => true,
            'linked_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('telegram_announcements')->insert([
            'id' => $announcementId,
            'event_key' => 'migration-recovery-event',
            'kind' => 'SYSTEM',
            'title' => 'Migration recovery',
            'body' => 'Preserve this delivery.',
            'status' => 'QUEUED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('telegram_announcement_deliveries')->insert([
            'telegram_announcement_id' => $announcementId,
            'telegram_account_id' => $accountId,
            'status' => 'SENT',
            'attempted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_08_26_000060_upgrade_telegram_storefront.php');
        $migration->up();

        $this->assertDatabaseHas('telegram_announcement_deliveries', [
            'telegram_announcement_id' => $announcementId,
            'telegram_account_id' => $accountId,
            'status' => 'SENT',
        ]);
        $this->assertSame(1, DB::table('telegram_announcement_deliveries')->count());
    }

    public function test_telegram_storefront_migration_recovers_missing_delivery_table(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::drop('telegram_announcement_deliveries');

        $migration = require database_path('migrations/2026_08_26_000060_upgrade_telegram_storefront.php');
        $migration->up();

        $this->assertCompleteTelegramDeliverySchema();
    }

    public function test_telegram_storefront_migration_repairs_partial_delivery_table_without_dropping_it(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::drop('telegram_announcement_deliveries');
        Schema::create('telegram_announcement_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('telegram_announcement_id')->nullable();
            $table->ulid('telegram_account_id')->nullable();
        });

        $migration = require database_path('migrations/2026_08_26_000060_upgrade_telegram_storefront.php');
        $migration->up();

        $this->assertCompleteTelegramDeliverySchema();
    }

    public function test_telegram_storefront_migration_preserves_incomplete_partial_rows(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::drop('telegram_announcement_deliveries');
        Schema::create('telegram_announcement_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('telegram_announcement_id')->nullable();
        });
        $deliveryId = DB::table('telegram_announcement_deliveries')->insertGetId([
            'telegram_announcement_id' => null,
        ]);

        $migration = require database_path('migrations/2026_08_26_000060_upgrade_telegram_storefront.php');
        $migration->up();

        $this->assertDatabaseHas('telegram_announcement_deliveries', [
            'id' => $deliveryId,
            'telegram_announcement_id' => null,
            'telegram_account_id' => null,
            'status' => 'PENDING',
        ]);
        $this->assertFalse(Schema::hasForeignKey(
            'telegram_announcement_deliveries',
            ['telegram_announcement_id'],
        ));
        $this->assertFalse(Schema::hasForeignKey(
            'telegram_announcement_deliveries',
            ['telegram_account_id'],
        ));
    }

    public function test_telegram_storefront_migration_uses_mysql_safe_delivery_identifiers(): void
    {
        $connection = app('db')->connection();
        $connection->useDefaultSchemaGrammar();
        $originalGrammar = $connection->getSchemaGrammar();
        $connection->setSchemaGrammar(new MySqlGrammar($connection));

        try {
            $blueprint = new Blueprint($connection, 'telegram_announcement_deliveries');
            $blueprint->create();
            $blueprint->id();
            $blueprint->ulid('telegram_announcement_id');
            $blueprint->ulid('telegram_account_id');
            $blueprint->string('status', 24)->default('PENDING')->index();
            $blueprint->timestamp('attempted_at')->nullable();
            $blueprint->text('last_error')->nullable();
            $blueprint->timestamps();
            $blueprint->foreign('telegram_announcement_id', 'tg_ann_delivery_announcement_fk')
                ->references('id')
                ->on('telegram_announcements')
                ->cascadeOnDelete();
            $blueprint->foreign('telegram_account_id', 'tg_ann_delivery_account_fk')
                ->references('id')
                ->on('telegram_accounts')
                ->cascadeOnDelete();
            $blueprint->unique(
                ['telegram_announcement_id', 'telegram_account_id'],
                'tg_announcement_account_unique',
            );

            $identifiers = [];
            foreach ($blueprint->toSql() as $statement) {
                preg_match_all('/(?:constraint|index|unique) `([^`]+)`/i', $statement, $matches);
                $identifiers = array_merge($identifiers, $matches[1]);
            }
            $identifiers = array_values(array_unique($identifiers));

            $this->assertContains('tg_ann_delivery_announcement_fk', $identifiers);
            $this->assertContains('tg_ann_delivery_account_fk', $identifiers);
            $this->assertContains('tg_announcement_account_unique', $identifiers);
            foreach ($identifiers as $identifier) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($identifier),
                    "MySQL identifier [{$identifier}] exceeds 64 characters.",
                );
            }
        } finally {
            $connection->setSchemaGrammar($originalGrammar);
        }
    }

    public function test_reseller_migration_compiles_with_mysql_safe_identifier_lengths(): void
    {
        $connection = app('db')->connection();
        $connection->useDefaultSchemaGrammar();
        $originalGrammar = $connection->getSchemaGrammar();
        $connection->setSchemaGrammar(new MySqlGrammar($connection));

        try {
            $migration = require database_path('migrations/2026_08_21_000012_create_reseller_domain.php');
            $queries = $connection->pretend(static function () use ($migration): void {
                $migration->up();
            });

            $identifiers = [];

            foreach (array_column($queries, 'query') as $statement) {
                preg_match_all('/(?:constraint|index|unique) `([^`]+)`/i', $statement, $matches);
                $identifiers = array_merge($identifiers, $matches[1]);
            }

            $identifiers = array_values(array_unique($identifiers));

            $this->assertNotEmpty($identifiers, 'The MySQL migration did not compile any named indexes or constraints.');
            $this->assertContains('reseller_transfer_source_unique', $identifiers);

            foreach ($identifiers as $identifier) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($identifier),
                    "MySQL identifier [{$identifier}] exceeds 64 characters.",
                );
            }
        } finally {
            $connection->setSchemaGrammar($originalGrammar);
        }
    }

    private function assertCompleteTelegramDeliverySchema(): void
    {
        $this->assertTrue(Schema::hasTable('telegram_announcement_deliveries'));
        $this->assertTrue(Schema::hasColumns('telegram_announcement_deliveries', [
            'id',
            'telegram_announcement_id',
            'telegram_account_id',
            'status',
            'attempted_at',
            'last_error',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasIndex('telegram_announcement_deliveries', ['status']));
        $this->assertTrue(Schema::hasIndex(
            'telegram_announcement_deliveries',
            ['telegram_announcement_id', 'telegram_account_id'],
            'unique',
        ));
        $this->assertTrue(Schema::hasForeignKey(
            'telegram_announcement_deliveries',
            ['telegram_announcement_id'],
        ));
        $this->assertTrue(Schema::hasForeignKey(
            'telegram_announcement_deliveries',
            ['telegram_account_id'],
        ));
    }
}
