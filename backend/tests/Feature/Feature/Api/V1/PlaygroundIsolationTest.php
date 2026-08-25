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

    public function test_quota_exposes_only_daily_free_funding_and_creates_the_daily_lot(): void
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
            ->assertJsonPath('data.redeem_token_remaining', 0)
            ->assertJsonPath('data.paid_token_remaining', 0)
            ->assertJsonPath('data.paid_credit_remaining', 0)
            ->assertJsonPath('data.fallback_available', false);

        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $user->id,
            'source_type' => 'PLAYGROUND_DAILY',
            'original_units' => 400,
            'remaining_units' => 400,
            'reserved_units' => 0,
        ]);
    }

    public function test_paid_and_redeemed_funding_cannot_continue_an_exhausted_playground_run(): void
    {
        Http::fake();
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
        $this->grant($user, $alias, 'TOKEN_QUOTA', 700, 'RESELLER_TRANSFER');
        $this->grant($user, $alias, 'CREDIT_BALANCE', 1_000_000, 'ADMIN_GRANT');

        $this->actingAs($user)
            ->postJson('/api/v1/me/playground/run', [
                'model' => $alias->public_alias,
                'protocol' => 'messages',
                'prompt' => 'This request must not leave the backend.',
                'max_output_tokens' => 64,
            ])
            ->assertStatus(402)
            ->assertJsonPath('code', 'playground_quota_exhausted')
            ->assertJsonPath('message', 'Your daily free Playground quota is exhausted for this model.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('playground_credentials', 0);
        $this->assertDatabaseCount('api_keys', 0);
        $this->assertDatabaseCount('reservations', 0);
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
