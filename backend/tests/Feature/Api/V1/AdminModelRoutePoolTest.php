<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use App\Services\ModelRoutePoolService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminModelRoutePoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_selector_balances_across_ready_revisions_and_respects_capacity(): void
    {
        $provider = Provider::query()->create([
            'name' => 'Pool Test',
            'slug' => 'pool-test',
            'enabled' => true,
        ]);

        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'private-model',
            'family' => 'test',
            'family_label' => 'Test',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);

        $alias = ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'pool-test-model',
            'display_name' => 'Pool Test Model',
            'description' => null,
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);

        $first = $this->revision($provider, 1);
        $second = $this->revision($provider, 2);

        $pool = ModelRoutePool::query()->create([
            'model_alias_id' => $alias->id,
            'enabled' => true,
            'strategy' => ModelRoutePool::STRATEGY_LEAST_CONNECTIONS,
            'max_concurrency' => 4,
        ]);

        ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'provider_connection_revision_id' => $first->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 1,
            'priority' => 100,
        ]);

        ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'provider_connection_revision_id' => $second->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 2,
            'priority' => 100,
        ]);

        // First route is already at capacity; selector must choose revision 2.
        Reservation::query()->create([
            'user_id' => User::factory()->create()->id,
            'provider_connection_revision_id' => $first->id,
            'public_model_alias' => $alias->public_alias,
            'billing_mode' => 'TOKEN_QUOTA',
            'reserved_units' => 1,
            'status' => 'ACTIVE',
            'idempotency_key' => 'route-pool-test-active',
            'expires_at' => now()->addMinutes(15),
        ]);

        $selected = app(ModelRoutePoolService::class)->select($alias, $provider);

        $this->assertSame((string) $second->id, (string) $selected->id);
    }

    private function revision(Provider $provider, int $version): ProviderConnectionRevision
    {
        return ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => $version,
            'origin' => 'http://127.0.0.1:'.(21000 + $version),
            'connection_type' => 'omniroute',
            'credential' => 'test-credential-'.$version,
            'credential_suffix' => (string) $version,
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
    }
}
