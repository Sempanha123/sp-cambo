<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Enums\AccountStatus;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\EntitlementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

class ResellerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.spcambo.gateway_secret' => 'internal-test-secret',
            'services.spcambo.management_key_lookup_secret' => 'management-test-secret',
        ]);
    }

    public function test_reseller_routes_require_the_seeded_reseller_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $customer = User::factory()->create();
        $customer->roles()->attach(Role::query()->where('name', 'CUSTOMER')->firstOrFail());

        $this->actingAs($customer)
            ->getJson('/api/v1/reseller/customers')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_reseller_can_create_customer_and_allocate_only_own_inventory(): void
    {
        $reseller = $this->reseller();
        app(EntitlementService::class)->grant($reseller, ['source_type' => 'ADMIN_GRANT', 'source_id' => 'inventory', 'package_name' => 'Inventory', 'family_label' => 'Claude', 'billing_mode' => 'TOKEN_QUOTA', 'original_units' => 100, 'unit_label' => 'tokens', 'allowed_model_aliases' => ['claude-coding'], 'billing_snapshot' => [], 'expires_at' => now()->addDay()], 'reseller-api:inventory');
        $created = $this->actingAs($reseller)->postJson('/api/v1/reseller/customers', ['name' => 'Managed User', 'email' => 'managed@example.test', 'password' => 'Strong!Password123', 'password_confirmation' => 'Strong!Password123', 'label' => 'Customer A'])->assertCreated()->assertJsonMissingPath('data.password');
        $managedId = $created->json('data.id');
        $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/allocations", ['billing_mode' => 'TOKEN_QUOTA', 'public_model_alias' => 'claude-coding', 'units' => 40, 'idempotency_key' => 'api-allocation-1', 'reason' => 'Customer purchased forty token units.'])->assertCreated()->assertJsonPath('data.units', '40');
        $customer = User::query()->where('email', 'managed@example.test')->firstOrFail();
        $this->assertSame(40, (int) $customer->entitlementLots()->sum('remaining_units'));
        $this->actingAs($reseller)->getJson('/api/v1/reseller/customers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_allocation_replay_is_idempotent_and_changed_input_is_a_stable_conflict(): void
    {
        $reseller = $this->reseller();
        app(EntitlementService::class)->grant($reseller, ['source_type' => 'ADMIN_GRANT', 'source_id' => 'inventory', 'package_name' => 'Inventory', 'family_label' => 'Claude', 'billing_mode' => 'TOKEN_QUOTA', 'original_units' => 100, 'unit_label' => 'tokens', 'allowed_model_aliases' => ['claude-coding'], 'billing_snapshot' => [], 'expires_at' => now()->addDay()], 'reseller-api:idempotency-inventory');
        $managedId = $this->actingAs($reseller)->postJson('/api/v1/reseller/customers', ['name' => 'Replay User', 'email' => 'replay@example.test', 'password' => 'Strong!Password123', 'password_confirmation' => 'Strong!Password123', 'label' => 'Replay'])->assertCreated()->json('data.id');
        $payload = ['billing_mode' => 'TOKEN_QUOTA', 'public_model_alias' => 'claude-coding', 'units' => 40, 'idempotency_key' => 'allocation-replay-1', 'reason' => 'Customer purchased forty token units.'];

        $first = $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/allocations", $payload)->assertCreated();
        $second = $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/allocations", $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $payload['units'] = 41;
        $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/allocations", $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('reseller_transfers', 1);
    }

    public function test_other_reseller_cannot_read_or_allocate_managed_customer(): void
    {
        $owner = $this->reseller();
        $attacker = $this->reseller();
        $managedId = $this->actingAs($owner)->postJson('/api/v1/reseller/customers', ['name' => 'Owned User', 'email' => 'owned@example.test', 'password' => 'Strong!Password123', 'password_confirmation' => 'Strong!Password123', 'label' => 'Owned'])->assertCreated()->json('data.id');
        $this->actingAs($attacker)->postJson("/api/v1/reseller/customers/{$managedId}/allocations", ['billing_mode' => 'TOKEN_QUOTA', 'public_model_alias' => 'claude-coding', 'units' => 1, 'idempotency_key' => 'attack', 'reason' => 'Cross tenant allocation must be refused.'])->assertNotFound();
        $this->actingAs($attacker)->getJson('/api/v1/reseller/customers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_management_key_is_one_time_reveal_scoped_and_revocable(): void
    {
        $reseller = $this->reseller();
        $created = $this->actingAs($reseller)->postJson('/api/v1/reseller/management-keys', ['label' => 'Automation', 'scopes' => ['customers:read']])->assertCreated();
        $secret = $created->json('data.secret');
        $keyId = $created->json('data.key.id');
        $this->assertStringStartsWith('sk-spm-', $secret);
        $this->actingAs($reseller)->getJson('/api/v1/reseller/management-keys')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->withToken($secret)->getJson('/api/v1/reseller-management/customers')->assertOk();
        $this->withToken($secret)->postJson('/api/v1/reseller-management/customers', [])->assertForbidden()->assertJsonPath('code', 'insufficient_scope');
        $this->actingAs($reseller)->postJson("/api/v1/reseller/management-keys/{$keyId}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->withToken($secret)->getJson('/api/v1/reseller-management/customers')->assertUnauthorized()->assertJsonPath('code', 'invalid_management_key');
        $this->assertDatabaseCount('api_keys', 0);
    }

    public function test_reseller_issues_one_time_inference_key_only_for_managed_customer(): void
    {
        $reseller = $this->reseller();
        $attacker = $this->reseller();
        $alias = $this->alias();
        $managedId = $this->actingAs($reseller)->postJson('/api/v1/reseller/customers', ['name' => 'Key Customer', 'email' => 'key-customer@example.test', 'password' => 'Strong!Password123', 'password_confirmation' => 'Strong!Password123', 'label' => 'Key Customer'])->assertCreated()->json('data.id');
        $created = $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys", ['label' => 'Customer CLI', 'allowed_model_aliases' => [$alias->public_alias]])->assertCreated();
        $keyId = $created->json('data.key.id');
        $this->assertStringStartsWith('sk-spc-', $created->json('data.secret'));
        $this->actingAs($reseller)->getJson("/api/v1/reseller/customers/{$managedId}/api-keys")->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->actingAs($attacker)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys/{$keyId}/revoke")->assertNotFound();
        $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys/{$keyId}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
    }

    public function test_managed_customer_status_transitions_sync_the_account_and_append_immutable_audits(): void
    {
        $reseller = $this->reseller();
        [$managedId, $customer] = $this->managedCustomer($reseller, 'lifecycle@example.test');

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Customer requested a temporary account suspension.',
        ])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->assertSame(AccountStatus::Suspended, $customer->fresh()->status);

        $suspensionAudit = AuditLog::query()
            ->where('action', 'reseller_customer.status_changed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($reseller->id, $suspensionAudit->actor_user_id);
        $this->assertSame('reseller_customer', $suspensionAudit->subject_type);
        $this->assertSame((string) $managedId, $suspensionAudit->subject_id);
        $this->assertSame('Customer requested a temporary account suspension.', $suspensionAudit->reason);
        $this->assertSame([
            'previous_status' => 'ACTIVE',
            'new_status' => 'SUSPENDED',
            'reseller_customer_id' => (int) $managedId,
            'customer_user_id' => $customer->id,
        ], $suspensionAudit->metadata);

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'ACTIVE',
            'reason' => 'Customer access was approved for reactivation.',
        ])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame(AccountStatus::Active, $customer->fresh()->status);

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'CLOSED',
            'reason' => 'Customer requested permanent managed account closure.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertSame(AccountStatus::Disabled, $customer->fresh()->status);

        [$secondManagedId, $secondCustomer] = $this->managedCustomer($reseller, 'suspended-close@example.test');
        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$secondManagedId}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Risk review requires temporary account suspension.',
        ])->assertOk();
        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$secondManagedId}/status", [
            'status' => 'CLOSED',
            'reason' => 'Risk review approved permanent account closure.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->assertSame(AccountStatus::Disabled, $secondCustomer->fresh()->status);
        $this->assertSame(5, AuditLog::query()->where('action', 'reseller_customer.status_changed')->count());

        $this->expectException(LogicException::class);
        $suspensionAudit->update(['reason' => 'tampered']);
    }

    public function test_closed_managed_customer_is_terminal_and_invalid_transition_inputs_do_not_mutate_state(): void
    {
        $reseller = $this->reseller();
        [$managedId, $customer] = $this->managedCustomer($reseller, 'terminal@example.test');

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'SUSPENDED',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'too short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'PAUSED',
            'reason' => 'Unsupported lifecycle states must be rejected.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'ACTIVE',
            'reason' => 'No-op lifecycle transitions must be rejected.',
        ])->assertConflict()->assertJsonPath('code', 'invalid_status_transition');
        $this->assertSame(AccountStatus::Active, $customer->fresh()->status);
        $this->assertDatabaseHas('reseller_customers', ['id' => $managedId, 'status' => 'ACTIVE']);
        $this->assertSame(0, AuditLog::query()->where('action', 'reseller_customer.status_changed')->count());

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'CLOSED',
            'reason' => 'Customer requested permanent account closure.',
        ])->assertOk();

        foreach (['ACTIVE', 'SUSPENDED'] as $status) {
            $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
                'status' => $status,
                'reason' => 'Closed managed accounts cannot transition again.',
            ])->assertConflict()->assertJsonPath('code', 'invalid_status_transition');
        }

        $this->assertSame(AccountStatus::Disabled, $customer->fresh()->status);
        $this->assertDatabaseHas('reseller_customers', ['id' => $managedId, 'status' => 'CLOSED']);
        $this->assertSame(1, AuditLog::query()->where('action', 'reseller_customer.status_changed')->count());
    }

    public function test_status_transition_is_tenant_scoped_for_browser_and_management_credentials(): void
    {
        $owner = $this->reseller();
        $attacker = $this->reseller();
        [$managedId, $customer] = $this->managedCustomer($owner, 'status-owned@example.test');
        $payload = [
            'status' => 'SUSPENDED',
            'reason' => 'Cross-tenant lifecycle mutation must be refused.',
        ];

        $this->actingAs($attacker)
            ->patchJson("/api/v1/reseller/customers/{$managedId}/status", $payload)
            ->assertNotFound();

        $managementSecret = $this->managementSecret($attacker, ['customers:write']);
        $this->withToken($managementSecret)
            ->patchJson("/api/v1/reseller-management/customers/{$managedId}/status", $payload)
            ->assertNotFound();

        $this->assertSame(AccountStatus::Active, $customer->fresh()->status);
        $this->assertDatabaseHas('reseller_customers', ['id' => $managedId, 'status' => 'ACTIVE']);
        $this->assertSame(0, AuditLog::query()->where('action', 'reseller_customer.status_changed')->count());
    }

    public function test_management_key_requires_customers_write_scope_for_status_transitions(): void
    {
        $reseller = $this->reseller();
        [$managedId, $customer] = $this->managedCustomer($reseller, 'management-status@example.test');
        $payload = [
            'status' => 'SUSPENDED',
            'reason' => 'Management automation requested account suspension.',
        ];
        $readSecret = $this->managementSecret($reseller, ['customers:read']);
        $writeSecret = $this->managementSecret($reseller, ['customers:write']);

        $this->withToken($readSecret)
            ->patchJson("/api/v1/reseller-management/customers/{$managedId}/status", $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
        $this->withToken($writeSecret)
            ->patchJson("/api/v1/reseller-management/customers/{$managedId}/status", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'SUSPENDED');

        $this->assertSame(AccountStatus::Suspended, $customer->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reseller->id,
            'action' => 'reseller_customer.status_changed',
            'reason' => $payload['reason'],
        ]);
    }

    public function test_non_active_managed_customer_blocks_allocation_and_key_creation_but_allows_key_cleanup(): void
    {
        $reseller = $this->reseller();
        [$managedId] = $this->managedCustomer($reseller, 'cleanup@example.test');
        $alias = $this->alias();
        $created = $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys", [
            'label' => 'Customer CLI',
            'allowed_model_aliases' => [$alias->public_alias],
        ])->assertCreated();
        $keyId = $created->json('data.key.id');

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Customer access is paused pending account review.',
        ])->assertOk();
        $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/allocations", [
            'billing_mode' => 'TOKEN_QUOTA',
            'public_model_alias' => $alias->public_alias,
            'units' => 1,
            'idempotency_key' => 'non-active-allocation',
            'reason' => 'Non-active customers cannot receive new allocation.',
        ])->assertNotFound();
        $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys", [
            'label' => 'Blocked key',
            'allowed_model_aliases' => [$alias->public_alias],
        ])->assertNotFound();
        $this->actingAs($reseller)->getJson("/api/v1/reseller/customers/{$managedId}/api-keys")
            ->assertOk()
            ->assertJsonPath('data.0.id', $keyId);

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'CLOSED',
            'reason' => 'Customer requested permanent account closure.',
        ])->assertOk();
        $this->actingAs($reseller)->getJson("/api/v1/reseller/customers/{$managedId}/api-keys")
            ->assertOk()
            ->assertJsonPath('data.0.id', $keyId);
        $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys/{$keyId}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'REVOKED');
    }

    public function test_existing_inference_key_follows_managed_customer_account_lifecycle_before_preflight(): void
    {
        $reseller = $this->reseller();
        [$managedId] = $this->managedCustomer($reseller, 'gateway-lifecycle@example.test');
        $alias = $this->alias();
        $secret = $this->actingAs($reseller)->postJson("/api/v1/reseller/customers/{$managedId}/api-keys", [
            'label' => 'Persistent customer key',
            'allowed_model_aliases' => [$alias->public_alias],
        ])->assertCreated()->json('data.secret');

        $this->gatewayInspect($secret)->assertOk();
        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Customer access is paused pending account review.',
        ])->assertOk();
        $this->gatewayInspect($secret)->assertForbidden()->assertJsonPath('code', 'account_suspended');

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'ACTIVE',
            'reason' => 'Customer account review approved reactivation.',
        ])->assertOk();
        $this->gatewayInspect($secret)->assertOk();

        $this->actingAs($reseller)->patchJson("/api/v1/reseller/customers/{$managedId}/status", [
            'status' => 'CLOSED',
            'reason' => 'Customer requested permanent account closure.',
        ])->assertOk();
        $this->gatewayInspect($secret)->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->assertDatabaseHas('api_keys', ['lookup_digest' => app(ApiKeySecretService::class)->digest($secret), 'status' => 'ACTIVE']);
    }

    /** @return array{0: string, 1: User} */
    private function managedCustomer(User $reseller, string $email): array
    {
        $managedId = (string) $this->actingAs($reseller)->postJson('/api/v1/reseller/customers', [
            'name' => 'Managed User',
            'email' => $email,
            'password' => 'Strong!Password123',
            'password_confirmation' => 'Strong!Password123',
            'label' => 'Managed Customer',
        ])->assertCreated()->json('data.id');

        return [$managedId, User::query()->where('email', $email)->firstOrFail()];
    }

    private function managementSecret(User $reseller, array $scopes): string
    {
        return (string) $this->actingAs($reseller)->postJson('/api/v1/reseller/management-keys', [
            'label' => 'Lifecycle Automation',
            'scopes' => $scopes,
        ])->assertCreated()->json('data.secret');
    }

    private function gatewayInspect(string $secret): TestResponse
    {
        return $this->withToken('internal-test-secret')->postJson('/api/v1/internal/gateway/inspect', [
            'customer_key' => $secret,
        ]);
    }

    private function reseller(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('name', 'RESELLER')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function alias(): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider', 'enabled' => true]);
        $model = AiModel::query()->create(['provider_id' => $provider->id, 'internal_model_id' => 'private', 'family' => 'claude', 'family_label' => 'Claude', 'commercial_resale_verified_at' => now(), 'enabled' => true]);

        return ModelAlias::query()->create(['ai_model_id' => $model->id, 'public_alias' => 'claude-coding', 'display_name' => 'Claude Coding', 'capabilities' => [], 'limits' => [], 'status' => 'available', 'enabled' => true, 'customer_visible' => true]);
    }
}
