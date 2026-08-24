<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiKeyCheckTest extends TestCase
{
    use RefreshDatabase;

    private function alias(): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider', 'enabled' => true]);
        $model = AiModel::query()->create(['provider_id' => $provider->id, 'internal_model_id' => 'private', 'family' => 'claude', 'family_label' => 'Claude', 'commercial_resale_verified_at' => now(), 'enabled' => true]);

        return ModelAlias::query()->create(['ai_model_id' => $model->id, 'public_alias' => 'claude-coding', 'display_name' => 'Claude Coding', 'capabilities' => ['messages_api' => true], 'limits' => [], 'status' => 'available', 'enabled' => true, 'customer_visible' => true]);
    }

    private function grant(User $user, ModelAlias $alias, string $mode, int $units, array $billingRules, ?\DateTimeInterface $expiresAt = null): EntitlementLot
    {
        return app(EntitlementService::class)->grant($user, [
            'source_type' => 'ADMIN_GRANT',
            'source_id' => uniqid('grant-', true),
            'package_name' => 'Test',
            'family_label' => 'Claude',
            'billing_mode' => $mode,
            'original_units' => $units,
            'unit_label' => $mode === 'CREDIT_BALANCE' ? 'microcredits' : 'tokens',
            'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null,
            'currency_exponent' => $mode === 'CREDIT_BALANCE' ? 6 : null,
            'allowed_model_aliases' => [$alias->public_alias],
            'billing_snapshot' => ['billing_rules' => $billingRules],
            'expires_at' => $expiresAt ?? now()->addDay()
        ], 'grant:'.uniqid('', true));
    }

    public function test_check_returns_credit_remaining(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $created = $this->actingAs($user)->postJson('/api/v1/me/api-keys', ['label' => 'Test Key', 'allowed_model_aliases' => [$alias->public_alias]])->assertCreated();
        $apiKey = $user->apiKeys()->firstOrFail();

        $this->grant($user, $alias, 'TOKEN_QUOTA', 1000, []);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 500, []);

        // Expired lot (should not be counted)
        $this->grant($user, $alias, 'TOKEN_QUOTA', 2000, [], now()->subDays(5));

        // Inactive lot (should not be counted)
        $lot = $this->grant($user, $alias, 'TOKEN_QUOTA', 3000, []);
        $lot->update(['status' => 'INACTIVE']);

        $secret = $created->json('data.secret');
        $digest = hash('sha256', $secret);
        $apiKey->update(['lookup_digest' => $digest]);

        $response = $this->postJson('/api/v1/keys/check', [
            'api_key' => $secret,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.credit_remaining', 1500); // 1000 + 500
        $response->assertJsonPath('data.quota_remaining', 1500); // 1000 + 500
        }

    public function test_check_returns_null_for_no_credit_remaining(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $created = $this->actingAs($user)->postJson('/api/v1/me/api-keys', ['label' => 'Test Key', 'allowed_model_aliases' => [$alias->public_alias]])->assertCreated();
        $apiKey = $user->apiKeys()->firstOrFail();

        $secret = $created->json('data.secret');
        $digest = hash('sha256', $secret);
        $apiKey->update(['lookup_digest' => $digest]);

        $response = $this->postJson('/api/v1/keys/check', [
            'api_key' => $secret,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.credit_remaining', null);
        $response->assertJsonPath('data.quota_remaining', null);
    }
}