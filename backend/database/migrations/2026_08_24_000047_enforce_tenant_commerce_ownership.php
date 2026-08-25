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
        // This migration must be safe on both fresh installs and upgraded V2
        // databases where the later 000048 hotfix may already have added these
        // two columns. The user-proven production repair used 000048 first.
        if (! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id', 'id']);
            });
        }

        if (! Schema::hasColumn('orders', 'tenant_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->nullable()->after('user_id')->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('entitlement_lots', 'tenant_id')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id', 'billing_mode', 'status', 'expires_at'], 'entitlement_tenant_billing_status_expiry');
            });
        }

        if (! Schema::hasColumn('api_keys', 'tenant_id')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id', 'status']);
            });
        }

        // Backfill every legacy customer. Do not create a second tenant when a
        // previous hotfix has already assigned one.
        DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'tenant_id'])->each(function ($user): void {
            $tenantId = $user->tenant_id;

            if ($tenantId === null) {
                $tenantId = (string) Str::ulid();
                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'name' => trim((string) ($user->name ?: $user->email ?: "User {$user->id}")),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
            }

            DB::table('orders')->where('user_id', $user->id)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
            DB::table('entitlement_lots')->where('user_id', $user->id)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
            DB::table('api_keys')->where('user_id', $user->id)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        });
    }

    public function down(): void
    {
        // Intentionally conservative: this migration is an upgrade bridge and
        // may encounter columns created by 000048. Rollback must not destroy a
        // column that another applied migration still owns.
        if (Schema::hasColumn('api_keys', 'tenant_id')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                try { $table->dropForeign(['tenant_id']); } catch (\Throwable) {}
                try { $table->dropIndex(['tenant_id', 'status']); } catch (\Throwable) {}
                $table->dropColumn('tenant_id');
            });
        }
        if (Schema::hasColumn('entitlement_lots', 'tenant_id')) {
            Schema::table('entitlement_lots', function (Blueprint $table): void {
                try { $table->dropForeign(['tenant_id']); } catch (\Throwable) {}
                try { $table->dropIndex('entitlement_tenant_billing_status_expiry'); } catch (\Throwable) {}
                $table->dropColumn('tenant_id');
            });
        }
    }
};
