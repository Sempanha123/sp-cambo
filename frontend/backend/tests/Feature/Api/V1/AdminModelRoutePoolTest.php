<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\InferenceAccessException;
use App\Models\AiModel;
use App\Models\ApiRequestLog;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\ProviderRouteHealth;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ModelRoutePoolService;
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
            'strategy' => ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS,
            'max_concurrency' => 4,
            'max_failover_attempts' => 2,
            'circuit_failure_threshold' => 3,
            'circuit_cooldown_seconds' => 30,
        ]);

        $firstEntry = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $model->id,
            'provider_connection_revision_id' => $first->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 1,
            'priority' => 100,
        ]);

        $secondEntry = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $model->id,
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
            'model_route_pool_entry_id' => $firstEntry->id,
            'public_model_alias' => $alias->public_alias,
            'billing_mode' => 'TOKEN_QUOTA',
            'reserved_units' => 1,
            'status' => 'ACTIVE',
            'idempotency_key' => 'route-pool-test-active',
            'expires_at' => now()->addMinutes(15),
        ]);

        $selected = app(ModelRoutePoolService::class)->select($alias, $model);

        $this->assertSame((string) $second->id, (string) $selected['revision']->id);
        $this->assertSame((string) $secondEntry->id, (string) $selected['entry']?->id);
        $this->assertSame((string) $model->id, (string) $selected['model']->id);
    }

    public function test_weighted_least_connections_prefers_the_higher_weight_at_equal_load(): void
    {
        $provider = Provider::query()->create([
            'name' => 'Weighted Pool Test',
            'slug' => 'weighted-pool-test',
            'enabled' => true,
        ]);

        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'weighted-private-model',
            'family' => 'test',
            'family_label' => 'Test',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);

        $alias = ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'weighted-pool-model',
            'display_name' => 'Weighted Pool Model',
            'description' => null,
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);

        $first = $this->revision($provider, 10);
        $second = $this->revision($provider, 11);

        $pool = ModelRoutePool::query()->create([
            'model_alias_id' => $alias->id,
            'enabled' => true,
            'strategy' => ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS,
            'max_concurrency' => null,
            'max_failover_attempts' => 2,
            'circuit_failure_threshold' => 3,
            'circuit_cooldown_seconds' => 30,
        ]);

        $higherWeight = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $model->id,
            'provider_connection_revision_id' => $first->id,
            'enabled' => true,
            'weight' => 200,
            'max_concurrency' => 10,
            'priority' => 100,
        ]);

        ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $model->id,
            'provider_connection_revision_id' => $second->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 10,
            'priority' => 100,
        ]);

        $selected = app(ModelRoutePoolService::class)->select($alias, $model);

        $this->assertSame((string) $higherWeight->id, (string) $selected['entry']?->id);
        $this->assertSame((string) $first->id, (string) $selected['revision']->id);
    }

    public function test_failover_persists_route_failure_when_no_alternate_route_exists(): void
    {
        [$alias, $model, $revision, $pool, $entry, $reservation] = $this->singleRoutePool(
            'persist-failure',
            circuitThreshold: 1,
        );

        try {
            app(ModelRoutePoolService::class)->failover(
                $reservation,
                'upstream_http_503',
                503,
            );
            $this->fail('Failover should be rejected when no alternate route exists.');
        } catch (InferenceAccessException $exception) {
            $this->assertSame('route_failover_unavailable', $exception->errorCode);
        }

        $health = ProviderRouteHealth::query()
            ->where('provider_connection_revision_id', $revision->id)
            ->firstOrFail();

        $this->assertSame(1, $health->consecutive_failures);
        $this->assertTrue($health->circuitIsOpen());
        $this->assertSame('upstream_http_503', $health->last_error_code);

        $history = $reservation->fresh()->billing_snapshot['route_history'];
        $this->assertSame((int) $entry->id, $history[0]['entry_id']);
        $this->assertSame('upstream_http_503', $history[0]['failure_code']);
        $this->assertSame(503, $history[0]['upstream_status']);
        $this->assertSame($alias->public_alias, $reservation->fresh()->public_model_alias);
        $this->assertSame($model->internal_model_id, $reservation->fresh()->billing_snapshot['internal_model_id']);
        $this->assertSame((string) $pool->id, (string) $entry->model_route_pool_id);
    }

    public function test_streaming_request_cannot_reroute_but_can_record_route_failure(): void
    {
        config(['services.spcambo.gateway_secret' => 'internal-route-pool-test']);

        [, , $revision, , , $reservation] = $this->singleRoutePool(
            'streaming-failure',
            circuitThreshold: 1,
        );

        ApiRequestLog::query()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $reservation->user_id,
            'api_key_id' => null,
            'public_model' => $reservation->public_model_alias,
            'endpoint' => '/v1/messages',
            'state' => 'STREAMING',
            'started_at' => now(),
        ]);

        $gateway = $this->withToken('internal-route-pool-test');
        $gateway->postJson(
            "/api/v1/internal/gateway/reservations/{$reservation->id}/reroute",
            ['failure_code' => 'upstream_disconnect'],
        )->assertStatus(409)->assertJsonPath('code', 'route_failover_not_allowed');

        $this->assertDatabaseMissing('provider_route_health', [
            'provider_connection_revision_id' => $revision->id,
        ]);

        $gateway->postJson(
            "/api/v1/internal/gateway/reservations/{$reservation->id}/route-failure",
            ['failure_code' => 'upstream_disconnect'],
        )->assertOk()->assertJsonPath('data.route_failure_recorded', true);

        $health = ProviderRouteHealth::query()
            ->where('provider_connection_revision_id', $revision->id)
            ->firstOrFail();

        $this->assertSame(1, $health->consecutive_failures);
        $this->assertTrue($health->circuitIsOpen());
        $this->assertSame((string) $revision->id, (string) $reservation->fresh()->provider_connection_revision_id);
    }

    public function test_pool_can_route_one_public_alias_to_another_provider_private_model(): void
    {
        $primaryProvider = Provider::query()->create([
            'name' => 'Primary Provider',
            'slug' => 'cross-provider-primary',
            'enabled' => true,
        ]);
        $secondaryProvider = Provider::query()->create([
            'name' => 'Secondary Provider',
            'slug' => 'cross-provider-secondary',
            'enabled' => true,
        ]);
        $primaryModel = AiModel::query()->create([
            'provider_id' => $primaryProvider->id,
            'internal_model_id' => 'private-primary',
            'family' => 'test',
            'family_label' => 'Test',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $secondaryModel = AiModel::query()->create([
            'provider_id' => $secondaryProvider->id,
            'internal_model_id' => 'private-secondary',
            'family' => 'test',
            'family_label' => 'Test',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $alias = ModelAlias::query()->create([
            'ai_model_id' => $primaryModel->id,
            'public_alias' => 'stable-public-name',
            'display_name' => 'Stable Public Name',
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $primaryRevision = $this->revision($primaryProvider, 30);
        $secondaryRevision = $this->revision($secondaryProvider, 31);
        $pool = ModelRoutePool::query()->create([
            'model_alias_id' => $alias->id,
            'enabled' => true,
            'strategy' => ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS,
            'max_concurrency' => 10,
            'max_failover_attempts' => 2,
            'circuit_failure_threshold' => 3,
            'circuit_cooldown_seconds' => 30,
        ]);
        $primaryEntry = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $primaryModel->id,
            'provider_connection_revision_id' => $primaryRevision->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 1,
            'priority' => 100,
        ]);
        $secondaryEntry = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $secondaryModel->id,
            'provider_connection_revision_id' => $secondaryRevision->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 5,
            'priority' => 100,
        ]);
        Reservation::query()->create([
            'user_id' => User::factory()->create()->id,
            'provider_connection_revision_id' => $primaryRevision->id,
            'model_route_pool_entry_id' => $primaryEntry->id,
            'public_model_alias' => $alias->public_alias,
            'billing_mode' => 'TOKEN_QUOTA',
            'reserved_units' => 1,
            'status' => 'ACTIVE',
            'idempotency_key' => 'cross-provider-primary-busy',
            'expires_at' => now()->addMinutes(15),
        ]);

        $selected = app(ModelRoutePoolService::class)->select($alias, $primaryModel);

        $this->assertSame('stable-public-name', $alias->public_alias);
        $this->assertSame((string) $secondaryEntry->id, (string) $selected['entry']?->id);
        $this->assertSame((string) $secondaryModel->id, (string) $selected['model']->id);
        $this->assertSame((string) $secondaryRevision->id, (string) $selected['revision']->id);
    }

    /** @return array{ModelAlias,AiModel,ProviderConnectionRevision,ModelRoutePool,ModelRoutePoolEntry,Reservation} */
    private function singleRoutePool(string $prefix, int $circuitThreshold): array
    {
        $provider = Provider::query()->create([
            'name' => "{$prefix} Provider",
            'slug' => "{$prefix}-provider",
            'enabled' => true,
        ]);
        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => "{$prefix}-private-model",
            'family' => 'test',
            'family_label' => 'Test',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $alias = ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => "{$prefix}-public-model",
            'display_name' => "{$prefix} Public Model",
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $revision = $this->revision($provider, 50);
        $pool = ModelRoutePool::query()->create([
            'model_alias_id' => $alias->id,
            'enabled' => true,
            'strategy' => ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS,
            'max_concurrency' => 10,
            'max_failover_attempts' => 2,
            'circuit_failure_threshold' => $circuitThreshold,
            'circuit_cooldown_seconds' => 30,
        ]);
        $entry = ModelRoutePoolEntry::query()->create([
            'model_route_pool_id' => $pool->id,
            'ai_model_id' => $model->id,
            'provider_connection_revision_id' => $revision->id,
            'enabled' => true,
            'weight' => 100,
            'max_concurrency' => 10,
            'priority' => 100,
        ]);
        $reservation = Reservation::query()->create([
            'user_id' => User::factory()->create()->id,
            'provider_connection_revision_id' => $revision->id,
            'model_route_pool_entry_id' => $entry->id,
            'public_model_alias' => $alias->public_alias,
            'billing_mode' => 'TOKEN_QUOTA',
            'reserved_units' => 1,
            'billing_snapshot' => [
                'internal_model_id' => $model->internal_model_id,
                'route_history' => [[
                    'entry_id' => (int) $entry->id,
                    'revision_id' => (string) $revision->id,
                    'selected_at' => now()->toAtomString(),
                ]],
            ],
            'status' => 'ACTIVE',
            'idempotency_key' => "{$prefix}-reservation",
            'expires_at' => now()->addMinutes(15),
        ]);

        return [$alias, $model, $revision, $pool, $entry, $reservation];
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
