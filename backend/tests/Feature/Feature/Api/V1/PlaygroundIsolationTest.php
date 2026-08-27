<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaygroundIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'services.spcambo.gateway_base_url' => 'http://gateway.test',
        ]);
    }

    public function test_quota_exposes_daily_and_explicit_fallback_balances_and_creates_the_daily_lot(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $this->settings($alias, 400);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 900, 'REDEEM_CODE');
        $this->grant($user, $alias, 'TOKEN_QUOTA', 800, 'ORDER');
        $this->grant($user, $alias, 'CREDIT_BALANCE', 2_000_000, 'ADMIN_GRANT');

        $this->actingAs($user)
            ->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.limit', 400)
            ->assertJsonPath('data.remaining', 400)
            ->assertJsonPath('data.free_model_aliases.0', $alias->public_alias)
            ->assertJsonPath('data.redeem_token_remaining', 900)
            ->assertJsonPath('data.paid_token_remaining', 800)
            ->assertJsonPath('data.paid_credit_remaining', 2000000)
            ->assertJsonPath('data.fallback_available', true)
            ->assertJsonPath('data.fallback_model_aliases.0', $alias->public_alias);

        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $user->id,
            'source_type' => 'PLAYGROUND_DAILY',
            'original_units' => 400,
            'remaining_units' => 400,
            'reserved_units' => 0,
        ]);
    }

    public function test_daily_quota_remains_unspent_when_no_free_model_is_currently_runnable(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $this->settings($alias, 20_000);

        // Temporarily block publication without consuming any daily quota.
        $alias->model->provider->activeConnectionRevision->forceFill([
            'lifecycle_status' => ProviderConnectionRevision::STATUS_REVOKED,
        ])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.limit', 20_000)
            ->assertJsonPath('data.remaining', 20_000)
            ->assertJsonPath('data.free_model_aliases', [])
            ->assertJsonPath('data.free_models_available', false);

        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $user->id,
            'source_type' => 'PLAYGROUND_DAILY',
            'original_units' => 20_000,
            'remaining_units' => 20_000,
            'reserved_units' => 0,
        ]);
    }

    public function test_daily_quota_upgrade_repairs_stale_lot_without_refunding_real_usage(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $this->settings($alias, 10_000);

        $this->actingAs($user)->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.remaining', 10_000);

        $lot = EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'PLAYGROUND_DAILY')
            ->sole();
        $lot->forceFill(['remaining_units' => 7_500])->save(); // 2,500 genuinely spent.

        $this->settings($alias, 20_000);
        $this->actingAs($user)->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.limit', 20_000)
            ->assertJsonPath('data.remaining', 17_500);

        $lot->refresh();
        $this->assertSame(20_000, (int) $lot->original_units);
        $this->assertSame(17_500, (int) $lot->remaining_units);
    }

    public function test_funded_but_unpublished_model_is_reported_instead_of_disappearing_from_playground(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $this->grant($user, $alias, 'TOKEN_QUOTA', 4000, 'ORDER');

        // Simulate a route/model becoming temporarily unavailable after purchase.
        $alias->model->forceFill(['commercial_resale_verified_at' => null])->save();
        $this->settings($alias, 0);

        $this->actingAs($user)
            ->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.paid_token_remaining', 4000)
            ->assertJsonPath('data.available_models', [])
            ->assertJsonPath('data.unavailable_funded_models.0.public_alias', $alias->public_alias)
            ->assertJsonPath('data.unavailable_funded_models.0.token_remaining', 4000)
            ->assertJsonPath('data.unavailable_funded_models.0.available', false);
    }

    public function test_exhausted_daily_quota_requires_explicit_balance_opt_in_then_forwards_balance_scope(): void
    {
        Http::fake([
            'http://gateway.test/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Fallback works']],
            ], 200),
        ]);
        $user = User::factory()->create();
        $alias = $this->alias();
        $this->settings($alias, 100);

        $this->actingAs($user)->getJson('/api/v1/me/playground/quota')->assertOk();
        EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'PLAYGROUND_DAILY')
            ->update(['remaining_units' => 100, 'reserved_units' => 100]);

        $this->grant($user, $alias, 'TOKEN_QUOTA', 900, 'REDEEM_CODE');
        $this->grant($user, $alias, 'TOKEN_QUOTA', 800, 'ORDER');
        $this->grant($user, $alias, 'CREDIT_BALANCE', 2_000_000, 'PROMOTION');

        $payload = [
            'model' => $alias->public_alias,
            'protocol' => 'messages',
            'prompt' => 'Use my explicit balance fallback.',
            'max_output_tokens' => 64,
        ];

        $this->actingAs($user)
            ->postJson('/api/v1/me/playground/run', $payload)
            ->assertStatus(402)
            ->assertJsonPath('code', 'playground_quota_exhausted');

        Http::assertNothingSent();

        $this->actingAs($user)
            ->postJson('/api/v1/me/playground/run', [...$payload, 'funding_source' => 'balance'])
            ->assertOk()
            ->assertJsonPath('data.message', 'Fallback works')
            ->assertJsonPath('data.quota.fallback_available', true);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-SP-Cambo-Playground-Funding', 'BALANCE'));
        $this->assertDatabaseCount('playground_credentials', 1);
        $this->assertDatabaseCount('api_keys', 1);
    }

    public function test_purchased_model_not_in_daily_free_list_is_available_with_account_balance(): void
    {
        Http::fake([
            'http://gateway.test/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Purchased model works']],
            ], 200),
        ]);

        $user = User::factory()->create();
        $freeAlias = $this->alias();
        $this->settings($freeAlias, 400);

        $paidModel = AiModel::query()->create([
            'provider_id' => $freeAlias->model->provider_id,
            'internal_model_id' => 'private/purchased-only-model',
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $paidAlias = ModelAlias::query()->create([
            'ai_model_id' => $paidModel->id,
            'public_alias' => 'sp-purchased-only',
            'display_name' => 'SP Purchased Only',
            'capabilities' => ['messages_api' => true],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $this->grant($user, $paidAlias, 'TOKEN_QUOTA', 5000, 'ORDER');

        $quota = $this->actingAs($user)->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.free_model_aliases.0', $freeAlias->public_alias)
            ->assertJsonPath('data.fallback_model_aliases.0', $paidAlias->public_alias)
            ->json('data');

        $this->assertContains($paidAlias->public_alias, $quota['available_model_aliases']);

        $this->actingAs($user)->postJson('/api/v1/me/playground/run', [
            'model' => $paidAlias->public_alias,
            'protocol' => 'messages',
            'prompt' => 'Use the model I purchased.',
            'max_output_tokens' => 64,
            'funding_source' => 'balance',
        ])->assertOk()->assertJsonPath('data.message', 'Purchased model works');
    }

    private function settings(ModelAlias $alias, int $dailyQuota): void
    {
        PlaygroundSetting::current()->forceFill([
            'enabled' => true,
            'daily_token_quota' => $dailyQuota,
            'max_output_tokens' => 2048,
            'allowed_model_aliases' => [$alias->public_alias],
            'gateway_base_url' => 'http://gateway.test',
            'default_model_alias' => $alias->public_alias,
            'allow_model_switching' => true,
        ])->save();
    }

    private function alias(): ModelAlias
    {
        $provider = Provider::query()->create([
            'name' => 'Playground Provider',
            'slug' => 'playground-provider',
            'enabled' => true,
        ]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://provider.test',
            'connection_type' => 'omniroute',
            'credential' => 'test-only-upstream-credential',
            'credential_suffix' => 'tial',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();
        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'private/playground-model',
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);

        return ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'sp-playground',
            'display_name' => 'SP Playground',
            'capabilities' => ['messages_api' => true],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
    }

    private function grant(
        User $user,
        ModelAlias $alias,
        string $mode,
        int $units,
        string $sourceType,
    ): EntitlementLot {
        return app(EntitlementService::class)->grant($user, [
            'source_type' => $sourceType,
            'source_id' => uniqid('playground-isolation-', true),
            'package_name' => 'Non-Playground funding',
            'family_label' => 'Claude',
            'billing_mode' => $mode,
            'original_units' => $units,
            'unit_label' => $mode === 'CREDIT_BALANCE' ? 'microcredits' : 'tokens',
            'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null,
            'currency_exponent' => $mode === 'CREDIT_BALANCE' ? 6 : null,
            'allowed_model_aliases' => [$alias->public_alias],
            'billing_snapshot' => ['billing_rules' => []],
            'expires_at' => now()->addDay(),
        ], 'playground-isolation:'.uniqid('', true));
    }
}
