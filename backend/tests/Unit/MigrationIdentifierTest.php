<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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
}
