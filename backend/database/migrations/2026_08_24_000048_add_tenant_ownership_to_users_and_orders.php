<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('tenants')
                    ->nullOnDelete();
                $table->unique('tenant_id');
            });
        }

        DB::table('users')
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->select(['id', 'name', 'email'])
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $tenantId = (string) Str::ulid();

                    DB::table('tenants')->insert([
                        'id' => $tenantId,
                        'name' => trim((string) ($user->name ?: $user->email ?: "User {$user->id}")),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['tenant_id' => $tenantId]);
                }
            }, 'id');

        if (! Schema::hasColumn('orders', 'tenant_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('tenants')
                    ->nullOnDelete();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereNull('orders.tenant_id')
            ->whereNotNull('users.tenant_id')
            ->update(['orders.tenant_id' => DB::raw('users.tenant_id')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'tenant_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id', 'created_at']);
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropUnique(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
