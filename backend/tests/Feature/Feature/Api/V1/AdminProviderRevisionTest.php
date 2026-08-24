<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProviderRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
    }

    public function test_admin_can_edit_unused_pending_revision_without_revealing_or_replacing_blank_credential(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();

        $this->actingAs($admin)->putJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}", [
            'route_version' => 2,
            'origin' => 'https://draft-two.example/',
            'connection_type' => 'omniroute',
            'credential' => '',
            'timeout_ms' => 45000,
            'policy_version' => 2,
            'resolve_until' => null,
        ])->assertOk()
            ->assertJsonPath('data.route_version', 2)
            ->assertJsonPath('data.origin', 'https://draft-two.example')
            ->assertJsonPath('data.timeout_ms', 45000)
            ->assertJsonPath('data.policy_version', 2)
            ->assertJsonPath('data.credential_configured', true)
            ->assertJsonPath('data.credential_suffix', '••••cret')
            ->assertJsonMissingPath('data.credential');

        $revision->refresh();
        $this->assertSame('initial-secret', $revision->credential);
        $this->assertSame(2, $revision->route_version);
    }

    public function test_admin_can_delete_unused_non_active_revision(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->revision();

        $this->actingAs($admin)->deleteJson("/api/v1/admin/providers/{$provider->id}/connection-revisions/{$revision->id}")
            ->assertOk()->assertJsonPath('data.success', true);

        $this->assertDatabaseMissing('provider_connection_revisions', ['id' => $revision->id]);
    }

    private function admin(): User
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'catalog.manage'], ['label' => 'Manage catalog']);
        $role = Role::query()->firstOrCreate(['name' => 'ADMIN'], ['label' => 'Administrator']);
        $role->permissions()->syncWithoutDetaching($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    /** @return array{Provider, ProviderConnectionRevision} */
    private function revision(): array
    {
        $provider = Provider::query()->create([
            'name' => 'Provider',
            'slug' => 'provider-'.uniqid(),
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'https://draft-one.example',
            'connection_type' => 'omniroute',
            'credential' => 'initial-secret',
            'credential_suffix' => 'cret',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
        ]);

        return [$provider, $revision];
    }
}
