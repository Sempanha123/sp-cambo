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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('entitlement_lots', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'billing_mode', 'status', 'expires_at'], 'entitlement_tenant_billing_status_expiry');
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        // Existing installations predate tenant ownership on customer rows. Create
        // one tenant per existing user, then backfill all commerce/security rows
        // from their immutable user ownership before new writes begin using it.
        DB::table('users')->orderBy('id')->get(['id', 'name', 'tenant_id'])->each(function ($user): void {
            $tenantId = $user->tenant_id;
            if ($tenantId === null) {
                $tenantId = (string) Str::ulid();
                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'name' => trim((string) $user->name) !== '' ? ((string) $user->name).' workspace' : 'Customer workspace',
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
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn('tenant_id');
        });
        Schema::table('entitlement_lots', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('entitlement_tenant_billing_status_expiry');
            $table->dropColumn('tenant_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropColumn('tenant_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'id']);
            $table->dropColumn('tenant_id');
        });
    }
};
