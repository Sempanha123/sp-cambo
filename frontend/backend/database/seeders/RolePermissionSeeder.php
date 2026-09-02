<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const ROLES = [
        'SUPER_ADMIN' => 'Super Administrator',
        'ADMIN' => 'Administrator',
        'FINANCE' => 'Finance',
        'SUPPORT' => 'Support',
        'RESELLER' => 'Reseller',
        'CUSTOMER' => 'Customer',
    ];

    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'admin.view' => 'View admin analytics',
        'catalog.manage' => 'Manage catalog',
        'access.manage' => 'Manage customer access',
        'reseller.manage' => 'Manage reseller customers',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'SUPER_ADMIN' => ['admin.view', 'catalog.manage', 'access.manage', 'reseller.manage'],
        'ADMIN' => ['admin.view'],
        'FINANCE' => [],
        'SUPPORT' => [],
        'RESELLER' => ['reseller.manage'],
        'CUSTOMER' => [],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = collect(self::PERMISSIONS)
                ->mapWithKeys(function (string $label, string $name): array {
                    $permission = Permission::query()->updateOrCreate(
                        ['name' => $name],
                        ['label' => $label],
                    );

                    return [$name => $permission->id];
                });

            foreach (self::ROLES as $name => $label) {
                $role = Role::query()->updateOrCreate(
                    ['name' => $name],
                    ['label' => $label],
                );

                $role->permissions()->syncWithoutDetaching(
                    collect(self::ROLE_PERMISSIONS[$name])
                        ->map(fn (string $permission): int => (int) $permissions->get($permission))
                        ->all(),
                );
            }
        });
    }
}
