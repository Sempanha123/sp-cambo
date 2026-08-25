<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminProviderModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32))]);
    }

    public function test_discovery_requires_an_explicitly_active_connection_revision(): void
    {
        $admin = $this->admin();
        [$provider] = $this->providerWithRevision(ProviderConnectionRevision::STATUS_READY, false);
        Http::fake();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/models/discover")
            ->assertStatus(409)
            ->assertJsonPath('code', 'active_provider_connection_required');

        Http::assertNothingSent();
    }

    public function test_discovery_rejects_an_active_revision_that_is_not_ready(): void
    {
        $admin = $this->admin();
        [$provider, $revision] = $this->providerWithRevision(ProviderConnectionRevision::STATUS_PENDING, false);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->saveOrFail();
        Http::fake();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/models/discover")
            ->assertStatus(409)
            ->assertJsonPath('code', 'active_provider_connection_not_ready');

        Http::assertNothingSent();
    }

    public function test_discovery_falls_back_to_the_compatible_catalog_endpoint_after_a_network_error(): void
    {
        $admin = $this->admin();
        [$provider] = $this->providerWithRevision();

        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://catalog.example/v1/models') {
                throw new \RuntimeException('private-first-endpoint-detail');
            }

            return Http::response([
                'models' => [
                    ['id' => 'agentrouter/gpt-5.6-sol'],
                    ['name' => 'agentrouter/claude-sonnet-5'],
                ],
            ]);
        });

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/models/discover")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.internal_model_id', 'agentrouter/claude-sonnet-5')
            ->assertJsonPath('data.1.internal_model_id', 'agentrouter/gpt-5.6-sol')
            ->assertJsonMissing(['private-first-endpoint-detail', 'catalog-secret']);

        // Laravel records the successful fallback request, while the simulated
        // connection exception is deliberately absent from its sent-request log.
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://catalog.example/models');
    }

    public function test_discovery_returns_a_safe_error_when_all_catalog_requests_fail(): void
    {
        $admin = $this->admin();
        [$provider] = $this->providerWithRevision();
        Http::fake(fn () => throw new \RuntimeException('private-network-detail'));

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/models/discover")
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_model_discovery_failed')
            ->assertJsonMissing(['private-network-detail', 'catalog-secret']);

        // Failed connection attempts are not retained in Laravel's sent-request log.
        Http::assertNothingSent();
    }

    public function test_imported_models_remain_commercially_unverified(): void
    {
        $admin = $this->admin();
        [$provider] = $this->providerWithRevision();
        Http::fake([
            'https://catalog.example/v1/models' => Http::response([
                'data' => [['id' => 'agentrouter/gpt-5.6-sol']],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/providers/{$provider->id}/models/import", [
                'model_ids' => ['agentrouter/gpt-5.6-sol'],
            ])
            ->assertOk()
            ->assertJsonPath('data.created.0', 'agentrouter/gpt-5.6-sol')
            ->assertJsonPath('data.models.0.commercial_resale_verified', false)
            ->assertJsonPath('data.models.0.commercial_resale_verified_at', null)
            ->assertJsonMissing(['catalog-secret']);

        $model = AiModel::query()
            ->where('provider_id', $provider->id)
            ->where('internal_model_id', 'agentrouter/gpt-5.6-sol')
            ->sole();

        $this->assertNull($model->commercial_resale_verified_at);
        $this->assertTrue($model->enabled);
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
    private function providerWithRevision(
        string $status = ProviderConnectionRevision::STATUS_READY,
        bool $activate = true
    ): array {
        $provider = Provider::query()->create([
            'name' => 'Catalog Provider',
            'slug' => 'catalog-provider-'.uniqid(),
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'https://catalog.example',
            'connection_type' => 'openai_compatible',
            'credential' => 'catalog-secret',
            'credential_suffix' => 'cret',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => $status,
        ]);

        if ($activate) {
            $provider = $provider->activateConnectionRevision($revision);
        }

        return [$provider, $revision];
    }
}
