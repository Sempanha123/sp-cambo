<?php

namespace Tests\Feature\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_database_seeder_creates_the_canonical_rbac_baseline_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['ADMIN', 'CUSTOMER', 'FINANCE', 'RESELLER', 'SUPER_ADMIN', 'SUPPORT'],
            Role::query()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(
            ['access.manage', 'admin.view', 'catalog.manage', 'reseller.manage'],
            Permission::query()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(['admin.view'], $this->permissionsFor('ADMIN'));
        $this->assertSame([], $this->permissionsFor('CUSTOMER'));
        $this->assertSame([], $this->permissionsFor('FINANCE'));
        $this->assertSame(['reseller.manage'], $this->permissionsFor('RESELLER'));
        $this->assertSame(
            ['access.manage', 'admin.view', 'catalog.manage', 'reseller.manage'],
            $this->permissionsFor('SUPER_ADMIN'),
        );
        $this->assertSame([], $this->permissionsFor('SUPPORT'));
        $this->assertDatabaseCount('permission_role', 6);
    }

    public function test_reseeding_repairs_missing_canonical_grants_without_removing_explicit_extra_grants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Role::query()->where('name', 'ADMIN')->firstOrFail();
        $admin->permissions()->detach(
            Permission::query()->where('name', 'admin.view')->firstOrFail(),
        );
        $admin->permissions()->attach(
            Permission::query()->where('name', 'catalog.manage')->firstOrFail(),
        );

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            ['admin.view', 'catalog.manage'],
            $this->permissionsFor('ADMIN'),
        );
    }

    public function test_every_permission_route_middleware_argument_exists_in_the_seeded_baseline(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $routePermissions = collect(Route::getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:'))
            ->map(fn (string $middleware) => substr($middleware, strlen('permission:')))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $seededPermissions = Permission::query()->orderBy('name')->pluck('name')->all();

        $this->assertNotSame([], $routePermissions);
        $this->assertSame([], array_values(array_diff($routePermissions, $seededPermissions)));
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(string $role): array
    {
        return Role::query()
            ->where('name', $role)
            ->firstOrFail()
            ->permissions()
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
