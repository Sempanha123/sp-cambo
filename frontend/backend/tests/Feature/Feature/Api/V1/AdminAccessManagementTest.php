<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Role;
use App\Models\User;
use App\Services\ApiKeySecretService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_access_reads_require_admin_view(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->getJson('/api/v1/admin/access/customers')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $admin = $this->admin(view: true, access: false);
        $this->actingAs($admin)->getJson('/api/v1/admin/access/customers')
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->actingAs($admin)->getJson('/api/v1/admin/access/api-keys')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/access/entitlements')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/access/usage')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/access/model-aliases')->assertForbidden();
    }

    public function test_catalog_manager_can_issue_a_one_time_secret_without_exposing_digest(): void
    {
        $admin = $this->admin(view: true, access: true);
        $customer = User::factory()->create();
        $alias = $this->publishedAlias();

        $this->actingAs($admin)->getJson('/api/v1/admin/access/model-aliases')
            ->assertOk()
            ->assertJsonPath('data.0.public_alias', $alias->public_alias);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/access/api-keys', [
            'user_id' => $customer->id,
            'label' => 'Support-issued key',
            'allowed_model_alias_ids' => [$alias->id],
            'expires_at' => now()->addDay()->toAtomString(),
            'reason' => 'Customer requested a replacement credential.',
        ])->assertCreated();

        $secret = (string) $response->json('data.secret');
        $this->assertStringStartsWith(ApiKeySecretService::PREFIX, $secret);
        $response->assertJsonPath('data.key.user.id', (string) $customer->id)
            ->assertJsonPath('data.key.allowed_model_aliases.0', $alias->public_alias)
            ->assertJsonMissingPath('data.key.lookup_digest');

        $key = ApiKey::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertNotSame($secret, $key->lookup_digest);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'api_key.admin_issued',
            'subject_id' => (string) $key->id,
        ]);
        $this->assertStringNotContainsString($secret, json_encode(AuditLog::query()->latest('id')->firstOrFail()->metadata));
    }

    public function test_admin_key_status_changes_are_irreversible_after_revocation(): void
    {
        $admin = $this->admin(view: true, access: true);
        $customer = User::factory()->create();
        $alias = $this->publishedAlias();
        $created = app(ApiKeySecretService::class)->create($customer, ['label' => 'Key'], [$alias->id]);
        $key = $created['key'];

        $this->actingAs($admin)->patchJson("/api/v1/admin/access/api-keys/{$key->id}/status", [
            'status' => 'DISABLED',
            'reason' => 'Temporary access suspension requested by support.',
        ])->assertOk()->assertJsonPath('data.stored_status', 'DISABLED');

        $this->actingAs($admin)->patchJson("/api/v1/admin/access/api-keys/{$key->id}/status", [
            'status' => 'REVOKED',
            'reason' => 'Credential replacement completed and old key retired.',
        ])->assertOk()->assertJsonPath('data.stored_status', 'REVOKED');

        $this->actingAs($admin)->patchJson("/api/v1/admin/access/api-keys/{$key->id}/status", [
            'status' => 'ACTIVE',
            'reason' => 'Attempted reactivation should remain blocked.',
        ])->assertStatus(409)->assertJsonPath('code', 'api_key_revoked');
    }

    public function test_customer_status_management_blocks_self_lockout_and_is_audited(): void
    {
        $admin = $this->admin(view: true, access: true);
        $customer = User::factory()->create();

        $this->actingAs($admin)->patchJson("/api/v1/admin/access/customers/{$customer->id}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Fraud review requires temporary account suspension.',
        ])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');

        $this->actingAs($admin)->patchJson("/api/v1/admin/access/customers/{$admin->id}/status", [
            'status' => 'DISABLED',
            'reason' => 'This attempt must not lock out the current operator.',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.status_changed', 'subject_id' => (string) $customer->id]);
    }

    public function test_entitlement_expiry_preserves_units_and_rejects_active_reservations(): void
    {
        $admin = $this->admin(view: true, access: true);
        $customer = User::factory()->create();
        $tenant = $customer->requireTenant();
        $lot = EntitlementLot::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'source_type' => 'PURCHASE',
            'source_id' => 'order-test',
            'package_name' => 'Test Package',
            'family_label' => 'Claude',
            'billing_mode' => 'TOKEN_QUOTA',
            'original_units' => 1000,
            'remaining_units' => 750,
            'reserved_units' => 0,
            'unit_label' => 'tokens',
            'allowed_model_aliases' => ['claude-coding'],
            'billing_snapshot' => [],
            'status' => 'ACTIVE',
            'activated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson("/api/v1/admin/access/entitlements/{$lot->id}/expire", [
            'reason' => 'Manual entitlement retirement after verified refund.',
        ])->assertOk()->assertJsonPath('data.status', 'EXPIRED');
        $this->assertSame(750, (int) $lot->fresh()->remaining_units);

        $reserved = $lot->replicate(['id']);
        $reserved->id = (string) \Illuminate\Support\Str::ulid();
        $reserved->source_id = 'reserved-test';
        $reserved->status = 'ACTIVE';
        $reserved->reserved_units = 10;
        $reserved->expires_at = null;
        $reserved->save();

        $this->actingAs($admin)->postJson("/api/v1/admin/access/entitlements/{$reserved->id}/expire", [
            'reason' => 'Should wait for active reservation reconciliation.',
        ])->assertStatus(409)->assertJsonPath('code', 'entitlement_has_active_reservations');
    }

    private function admin(bool $view, bool $access): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'SUPER_ADMIN'], ['label' => 'Super Administrator']);
        $permissionIds = [];
        if ($view) $permissionIds[] = Permission::query()->firstOrCreate(['name' => 'admin.view'], ['label' => 'View admin analytics'])->id;
        if ($access) $permissionIds[] = Permission::query()->firstOrCreate(['name' => 'access.manage'], ['label' => 'Manage customer access'])->id;
        $role->permissions()->syncWithoutDetaching($permissionIds);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        return $user;
    }

    private function publishedAlias(): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'admin-access-provider', 'enabled' => true]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://127.0.0.1:3010',
            'connection_type' => 'omniroute',
            'credential' => 'test-provider-credential',
            'credential_suffix' => 'test',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();
        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'private/admin-access',
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        return ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'claude-coding',
            'display_name' => 'Claude Coding',
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
    }
}
