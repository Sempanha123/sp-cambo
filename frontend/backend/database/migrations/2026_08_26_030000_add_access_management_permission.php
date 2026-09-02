<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        DB::transaction(function (): void {
            $permission = DB::table('permissions')->where('name', 'access.manage')->first();
            if ($permission === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => 'access.manage',
                    'label' => 'Manage customer access',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $permissionId = (int) $permission->id;
                DB::table('permissions')->where('id', $permissionId)->update([
                    'label' => 'Manage customer access',
                    'updated_at' => now(),
                ]);
            }

            $superAdminId = DB::table('roles')->where('name', 'SUPER_ADMIN')->value('id');
            if ($superAdminId !== null) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => (int) $superAdminId,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'access.manage')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::transaction(function () use ($permissionId): void {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        });
    }
};
