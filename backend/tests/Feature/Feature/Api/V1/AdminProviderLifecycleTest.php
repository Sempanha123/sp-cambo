<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProviderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('l', 32))]);
    }

    public function test_admin_can_publish_a_legacy_ready_provider_alias_for_sale_in_one_explicit_action(): void
    {
        $admin = $this->admin();
        [$provider, $revision, $model, $alias] = $this->catalog();

        $this->assertNull($provider->active_connection_revision_id);
        $this->assertNull($model->commercial_resale_verified_at);
        $this->assertFalse($alias->customer_visible);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/aliases/{$alias->id}/publish", [
                'confirm_commercial_resale' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.public_alias', 'sp-test-model')
            ->assertJsonPath('data.customer_visible', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.publication_ready', true)
            ->assertJsonPath('data.publication_blockers', []);

        $this->assertSame($revision->id, $provider->refresh()->active_connection_revision_id);
        $this->assertNotNull($model->refresh()->commercial_resale_verified_at);
        $this->assertTrue($alias->refresh()->customer_visible);
    }

    public function test_normal_provider_delete_stays_conservative_when_aliases_exist(): void
    {
        $admin = $this->admin();
        [$provider] = $this->catalog();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/providers/{$provider->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'provider_in_use_by_aliases')
            ->assertJsonPath('data.cascade_available', true);

        $this->assertDatabaseHas('providers', ['id' => $provider->id]);
    }

    public function test_admin_can_cascade_delete_unused_provider_configuration_and_empty_packages_are_disabled(): void
    {
        $admin = $this->admin();
        [$provider, $revision, $model, $alias] = $this->catalog();
        $package = Package::query()->create([
            'slug' => 'provider-delete-package',
            'name' => 'Provider delete package',
            'subtitle' => null,
            'badge' => null,
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => 'test',
            'family_label' => 'Test',
            'advertised_units' => 1000,
            'unit_label' => 'tokens',
            'price_minor' => 100,
            'compare_at_price_minor' => null,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'duration_seconds' => 86400,
            'limits' => [],
            'billing_rules' => [],
            'auto_creates_api_key' => true,
            'featured' => false,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'enabled' => true,
            'customer_visible' => true,
            'minimum_margin_bps' => 0,
            'profitability_override_reason' => null,
        ]);
        $package->modelAliases()->attach($alias->id);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/providers/{$provider->id}?cascade=1")
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.cascade', true)
            ->assertJsonPath('data.deleted_aliases', 1)
            ->assertJsonPath('data.detached_packages', 1)
            ->assertJsonPath('data.disabled_empty_packages', 1);

        $this->assertDatabaseMissing('providers', ['id' => $provider->id]);
        $this->assertDatabaseMissing('provider_connection_revisions', ['id' => $revision->id]);
        $this->assertDatabaseMissing('ai_models', ['id' => $model->id]);
        $this->assertDatabaseMissing('model_aliases', ['id' => $alias->id]);
        $this->assertDatabaseMissing('model_alias_package', ['model_alias_id' => $alias->id]);
        $this->assertFalse($package->refresh()->enabled);
        $this->assertFalse($package->customer_visible);
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

    /** @return array{Provider,ProviderConnectionRevision,AiModel,ModelAlias} */
    private function catalog(): array
    {
        $provider = Provider::query()->create([
            'name' => 'Lifecycle provider',
            'slug' => 'lifecycle-provider-'.uniqid(),
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'https://provider.example',
            'connection_type' => 'omniroute',
            'credential' => 'provider-secret',
            'credential_suffix' => 'cret',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'agentrouter/test-model',
            'display_name' => 'Test model',
            'family' => 'test',
            'family_label' => 'Test',
            'capabilities' => [
                'streaming' => true,
                'tools' => false,
                'vision' => false,
                'reasoning' => false,
                'context_tokens' => 200000,
                'max_output_tokens' => 64000,
            ],
            'limits' => [
                'requests_per_minute' => null,
                'tokens_per_minute' => null,
                'concurrency' => null,
            ],
            'commercial_resale_verified_at' => null,
            'enabled' => true,
        ]);
        $alias = ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'sp-test-model',
            'display_name' => 'SP Test Model',
            'description' => null,
            'capabilities' => [
                'messages_api' => true,
                'chat_completions_api' => true,
                'responses_api' => true,
                'streaming' => true,
                'tools' => false,
                'vision' => false,
                'reasoning' => false,
                'context_tokens' => 200000,
                'max_output_tokens' => 64000,
            ],
            'limits' => [
                'requests_per_minute' => null,
                'tokens_per_minute' => null,
                'concurrency' => null,
            ],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => false,
        ]);

        return [$provider, $revision, $model, $alias];
    }
}
