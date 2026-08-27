<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'stock_quantity')) {
            Schema::table('packages', function (Blueprint $table): void {
                // null = unlimited inventory; 0 = sold out; positive = available units.
                $table->unsignedBigInteger('stock_quantity')->nullable()->after('duration_seconds');
            });
        }

        $missing = [
            'stock_reserved_at' => ! Schema::hasColumn('orders', 'stock_reserved_at'),
            'stock_released_at' => ! Schema::hasColumn('orders', 'stock_released_at'),
            'stock_consumed_at' => ! Schema::hasColumn('orders', 'stock_consumed_at'),
            'stock_oversold_at' => ! Schema::hasColumn('orders', 'stock_oversold_at'),
        ];

        if (in_array(true, $missing, true)) {
            Schema::table('orders', function (Blueprint $table) use ($missing): void {
                if ($missing['stock_reserved_at']) {
                    $table->timestamp('stock_reserved_at')->nullable()->after('fulfilled_at');
                }
                if ($missing['stock_released_at']) {
                    $table->timestamp('stock_released_at')->nullable()->after('stock_reserved_at');
                }
                if ($missing['stock_consumed_at']) {
                    $table->timestamp('stock_consumed_at')->nullable()->after('stock_released_at');
                }
                if ($missing['stock_oversold_at']) {
                    $table->timestamp('stock_oversold_at')->nullable()->after('stock_consumed_at');
                }
            });
        }
    }

    public function down(): void
    {
        $orderColumns = array_values(array_filter([
            'stock_oversold_at',
            'stock_consumed_at',
            'stock_released_at',
            'stock_reserved_at',
        ], fn (string $column): bool => Schema::hasColumn('orders', $column)));

        if ($orderColumns !== []) {
            Schema::table('orders', function (Blueprint $table) use ($orderColumns): void {
                $table->dropColumn($orderColumns);
            });
        }

        if (Schema::hasColumn('packages', 'stock_quantity')) {
            Schema::table('packages', function (Blueprint $table): void {
                $table->dropColumn('stock_quantity');
            });
        }
    }
};
