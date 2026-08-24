<?php

namespace Tests\Feature\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleGrantCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unknown_email_fails_without_mutating_authorization_or_audit_state(): void
    {
        $this->artisan('spcambo:grant-role', [
            'email' => 'missing@example.test',
            'role' => 'SUPER_ADMIN',
            '--reason' => 'Initial production administrator bootstrap.',
        ])->assertFailed();

        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unknown_role_fails_without_mutating_authorization_or_audit_state(): void
    {
        User::factory()->create(['email' => 'operator@example.test']);

        $this->artisan('spcambo:grant-role', [
            'email' => 'operator@example.test',
            'role' => 'ROOT',
            '--reason' => 'Attempt to grant an unknown role.',
        ])->assertFailed();

        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reason_is_required_for_a_privileged_role_grant(): void
    {
        User::factory()->create(['email' => 'operator@example.test']);

        $this->artisan('spcambo:grant-role', [
            'email' => 'operator@example.test',
            'role' => 'SUPER_ADMIN',
        ])->assertFailed();

        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_valid_role_grant_is_audited_effective_and_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.test']);
        $user->roles()->attach(Role::query()->where('name', 'CUSTOMER')->firstOrFail());
        $reason = 'Initial production administrator bootstrap.';
        $arguments = [
            'email' => 'OPERATOR@EXAMPLE.TEST',
            'role' => 'super_admin',
            '--reason' => $reason,
        ];

        $this->artisan('spcambo:grant-role', $arguments)->assertSuccessful();
        $this->artisan('spcambo:grant-role', $arguments)->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->hasRole('SUPER_ADMIN'));
        $this->assertTrue($user->hasPermission('admin.view'));
        $this->assertTrue($user->hasPermission('catalog.manage'));
        $this->assertTrue($user->hasPermission('reseller.manage'));
        $this->assertDatabaseCount('role_user', 2);
        $this->assertDatabaseCount('audit_logs', 1);

        $audit = AuditLog::query()->firstOrFail();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame('authorization.role.granted', $audit->action);
        $this->assertSame('user', $audit->subject_type);
        $this->assertSame((string) $user->id, $audit->subject_id);
        $this->assertSame($reason, $audit->reason);
        $this->assertSame([
            'role' => 'SUPER_ADMIN',
            'source' => 'artisan',
        ], $audit->metadata);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/overview')
            ->assertOk();
    }
}
