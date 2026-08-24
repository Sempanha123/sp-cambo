<?php

namespace Tests\Feature\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_inherited_only_through_assigned_roles(): void
    {
        $permission = Permission::query()->create(['name' => 'catalog.manage', 'label' => 'Manage catalog']);
        $admin = Role::query()->create(['name' => 'ADMIN', 'label' => 'Administrator']);
        $customer = Role::query()->create(['name' => 'CUSTOMER', 'label' => 'Customer']);
        $admin->permissions()->attach($permission);

        $adminUser = User::factory()->create();
        $customerUser = User::factory()->create();
        $adminUser->roles()->attach($admin);
        $customerUser->roles()->attach($customer);

        $this->assertTrue($adminUser->hasRole('ADMIN'));
        $this->assertTrue($adminUser->hasPermission('catalog.manage'));
        $this->assertFalse($customerUser->hasRole('ADMIN'));
        $this->assertFalse($customerUser->hasPermission('catalog.manage'));
    }
}
