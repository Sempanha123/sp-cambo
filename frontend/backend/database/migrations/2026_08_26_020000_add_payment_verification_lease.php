<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_attempts', 'verification_lease_token')) {
            Schema::table('payment_attempts', function (Blueprint $table): void {
                $table->char('verification_lease_token', 64)->nullable()->after('last_checked_at');
            });
        }

        if (! Schema::hasColumn('payment_attempts', 'verification_lease_expires_at')) {
            Schema::table('payment_attempts', function (Blueprint $table): void {
                $table->timestamp('verification_lease_expires_at')->nullable()->after('verification_lease_token');
            });
        }

        if (! $this->hasIndex('payment_attempts', 'pay_verify_lease_idx')) {
            Schema::table('payment_attempts', function (Blueprint $table): void {
                $table->index(['status', 'verification_lease_expires_at'], 'pay_verify_lease_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('payment_attempts', 'pay_verify_lease_idx')) {
            Schema::table('payment_attempts', function (Blueprint $table): void {
                $table->dropIndex('pay_verify_lease_idx');
            });
        }

        foreach (['verification_lease_expires_at', 'verification_lease_token'] as $column) {
            if (Schema::hasColumn('payment_attempts', $column)) {
                Schema::table('payment_attempts', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $definition): bool => ($definition['name'] ?? null) === $index
        );
    }
};
