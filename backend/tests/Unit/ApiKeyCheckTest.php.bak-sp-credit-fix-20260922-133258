<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiKeyCheckTest extends TestCase
{
    use RefreshDatabase;

    private function alias(string $publicAlias = 'claude-coding'): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Provider '.$publicAlias, 'slug' => 'provider-'.$publicAlias, 'enabled' => true]);
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
            'internal_model_id' => 'private-'.$publicAlias,
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);

        return ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => $publicAlias,
            'display_name' => 'Model '.$publicAlias,
            'capabilities' => [
                'messages_api' => true,
                'streaming' => true,
                'tools' => true,
                'context_tokens' => 200_000,
                'max_output_tokens' => 64_000,
                'capability_basis' => 'PROVIDER_PUBLIC_SPEC',
            ],
            'limits' => ['context_tokens' => 200_000, 'max_output_tokens' => 64_000],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
    }

    /** @return array{key: ApiKey, secret: string} */
    private function issueKey(User $user, ModelAlias $alias): array
    {
        $created = $this->actingAs($user)->postJson('/api/v1/me/api-keys', [
            'label' => 'Test Key',
            'allowed_model_aliases' => [$alias->public_alias],
        ])->assertCreated();

        return [
            'key' => $user->apiKeys()->firstOrFail(),
            'secret' => (string) $created->json('data.secret'),
        ];
    }

    private function grant(
        User $user,
        ModelAlias $alias,
        string $mode,
        int $units,
        array $billingRules = [],
        ?\DateTimeInterface $expiresAt = null,
        string $sourceType = 'ADMIN_GRANT',
    ): EntitlementLot {
        return app(EntitlementService::class)->grant($user, [
            'source_type' => $sourceType,
            'source_id' => uniqid('grant-', true),
            'package_name' => $mode === 'CREDIT_BALANCE' ? 'Credit Test' : 'Token Test',
            'family_label' => 'Claude',
            'billing_mode' => $mode,
            'original_units' => $units,
            'unit_label' => $mode === 'CREDIT_BALANCE' ? 'microcredits' : 'tokens',
            'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null,
            'currency_exponent' => $mode === 'CREDIT_BALANCE' ? 6 : null,
            'allowed_model_aliases' => [$alias->public_alias],
            'billing_snapshot' => ['billing_rules' => $billingRules],
            'expires_at' => $expiresAt ?? now()->addDay(),
        ], 'grant:'.uniqid('', true));
    }

    public function test_check_uses_the_same_hmac_digest_as_issued_keys(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);
        $issued['key']->forceFill([
            'requests_per_minute' => 60,
            'tokens_per_minute' => 200_000,
            'concurrency_limit' => 4,
            'max_request_bytes' => 1_048_576,
            'max_output_tokens' => 64_000,
        ])->save();

        // No test-only digest rewrite is required. A real issued secret must work.
        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.model_details.0.public_alias', 'claude-coding')
            ->assertJsonPath('data.model_details.0.context_tokens', 200_000)
            ->assertJsonPath('data.model_details.0.max_output_tokens', 64_000)
            ->assertJsonPath('data.model_details.0.capability_basis', 'PROVIDER_PUBLIC_SPEC')
            ->assertJsonPath('data.model_details.0.features.0', 'Streaming')
            ->assertJsonPath('data.model_details.0.features.1', 'Tools')
            ->assertJsonPath('data.limits.requests_per_minute', 60)
            ->assertJsonPath('data.limits.tokens_per_minute', 200_000)
            ->assertJsonPath('data.limits.concurrency', 4)
            ->assertJsonPath('data.limits.max_request_bytes', 1_048_576)
            ->assertJsonPath('data.limits.max_output_tokens', 64_000)
            ->assertJsonMissingPath('data.model_details.0.internal_model_id')
            ->assertJsonMissingPath('data.model_details.0.provider');

        $this->postJson('/api/v1/keys/check', ['api_key' => " \n{$issued['secret']}\r\n"])
            ->assertOk()
            ->assertJsonPath('data.valid', true);
    }

    public function test_check_separates_token_quota_from_credit_balance_and_subtracts_reservations(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);

        $this->grant($user, $alias, 'TOKEN_QUOTA', 1000);
        $reservedTokens = $this->grant($user, $alias, 'TOKEN_QUOTA', 500);
        $reservedTokens->update(['reserved_units' => 125]);

        $credit = $this->grant($user, $alias, 'CREDIT_BALANCE', 2_500_000);
        $credit->update(['reserved_units' => 500_000]);

        // Expired and inactive lots cannot appear in the spendable balance.
        $this->grant($user, $alias, 'TOKEN_QUOTA', 2000, [], now()->subDays(5));
        $inactive = $this->grant($user, $alias, 'CREDIT_BALANCE', 3_000_000);
        $inactive->update(['status' => 'INACTIVE']);

        $response = $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])->assertOk();

        $response->assertJsonPath('data.quota_remaining', '1375');
        $response->assertJsonPath('data.credit_remaining.minor', '2000000');
        $response->assertJsonPath('data.credit_remaining.currency', 'USD');
        $response->assertJsonPath('data.credit_remaining.exponent', 6);
        $response->assertJsonCount(1, 'data.credit_balances');
        $response->assertJsonPath('data.package', 'Token Test, Credit Test');
    }

    public function test_check_excludes_entitlements_outside_the_key_model_scope(): void
    {
        $user = User::factory()->create();
        $allowed = $this->alias('claude-coding');
        $other = $this->alias('other-model');
        $issued = $this->issueKey($user, $allowed);

        $this->grant($user, $allowed, 'TOKEN_QUOTA', 100);
        $this->grant($user, $other, 'TOKEN_QUOTA', 900);

        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.quota_remaining', '100');
    }

    public function test_zero_spendable_quota_is_reported_as_zero_not_unlimited(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);
        $lot = $this->grant($user, $alias, 'TOKEN_QUOTA', 100);
        $lot->update(['remaining_units' => 50, 'reserved_units' => 50]);

        $response = $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])->assertOk();

        $response->assertJsonPath('data.quota_remaining', '0');
        $response->assertJsonPath('data.credit_remaining', null);
    }

    public function test_authenticated_status_reports_the_same_spendable_balances_without_billing(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);
        $tokens = $this->grant($user, $alias, 'TOKEN_QUOTA', 500);
        $tokens->update(['reserved_units' => 50]);
        $credit = $this->grant($user, $alias, 'CREDIT_BALANCE', 1_500_000);
        $credit->update(['reserved_units' => 250_000]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/me/api-keys/{$issued['key']->id}/status")
            ->assertOk();

        $response->assertJsonPath('data.token_quota_remaining', '450');
        $response->assertJsonPath('data.credit_remaining.minor', '1250000');
        $response->assertJsonPath('data.credit_remaining.currency', 'USD');
        $response->assertJsonPath('data.credit_remaining.exponent', 6);
    }

    public function test_playground_key_check_and_status_report_only_daily_free_quota(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);
        DB::table('playground_credentials')->insert([
            'user_id' => $user->id,
            'api_key_id' => $issued['key']->id,
            'secret_ciphertext' => 'test-only-not-read',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $daily = $this->grant($user, $alias, 'TOKEN_QUOTA', 400, [], null, 'PLAYGROUND_DAILY');
        $daily->update(['reserved_units' => 40]);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 900, [], null, 'ADMIN_GRANT');
        $this->grant($user, $alias, 'CREDIT_BALANCE', 2_000_000, [], null, 'ORDER');

        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.quota_remaining', '360')
            ->assertJsonPath('data.credit_remaining', null)
            ->assertJsonCount(0, 'data.credit_balances')
            ->assertJsonPath('data.package', 'Token Test');

        $this->actingAs($user)
            ->getJson("/api/v1/me/api-keys/{$issued['key']->id}/status")
            ->assertOk()
            ->assertJsonPath('data.token_quota_remaining', '360')
            ->assertJsonPath('data.credit_remaining', null)
            ->assertJsonCount(0, 'data.credit_balances');
    }

    public function test_customer_key_check_and_status_exclude_daily_playground_quota(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);

        $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], null, 'PLAYGROUND_DAILY');
        $paid = $this->grant($user, $alias, 'TOKEN_QUOTA', 100, [], null, 'ADMIN_GRANT');
        $paid->update(['reserved_units' => 25]);
        $credit = $this->grant($user, $alias, 'CREDIT_BALANCE', 900_000, [], null, 'ORDER');
        $credit->update(['reserved_units' => 100_000]);

        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.quota_remaining', '75')
            ->assertJsonPath('data.credit_remaining.minor', '800000');

        $this->actingAs($user)
            ->getJson("/api/v1/me/api-keys/{$issued['key']->id}/status")
            ->assertOk()
            ->assertJsonPath('data.token_quota_remaining', '75')
            ->assertJsonPath('data.credit_remaining.minor', '800000');
    }

    public function test_check_reports_effective_expired_and_disabled_status(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);

        $issued['key']->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'EXPIRED');

        $issued['key']->update(['expires_at' => null, 'status' => 'DISABLED']);
        $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'DISABLED');
    }

    public function test_check_returns_real_metering_spend_and_recent_requests(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $issued = $this->issueKey($user, $alias);

        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'api_key_id' => $issued['key']->id,
            'public_model_alias' => $alias->public_alias,
            'billing_mode' => 'CREDIT_BALANCE',
            'reserved_units' => 150_000,
            'settled_units' => 125_000,
            'status' => 'SETTLED',
            'idempotency_key' => 'key-check-usage',
            'expires_at' => now()->addMinute(),
            'settled_at' => now(),
            'billing_snapshot' => ['currency' => 'USD', 'currency_exponent' => 6],
        ]);

        ApiRequestLog::query()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $user->id,
            'api_key_id' => $issued['key']->id,
            'public_model' => $alias->public_alias,
            'endpoint' => '/v1/messages',
            'state' => 'SETTLED',
            'duration_ms' => 420,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        UsageRecord::query()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $user->id,
            'api_key_id' => $issued['key']->id,
            'public_model' => $alias->public_alias,
            'provider_family' => 'claude',
            'endpoint' => '/v1/messages',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cache_read_tokens' => 5,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 10,
            'total_tokens' => 135,
            'metered_units' => 125_000,
            'credit_charge_minor' => 125_000,
            'upstream_cost_minor' => 80_000,
            'currency' => 'USD',
            'currency_exponent' => 6,
            'settled_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/keys/check', ['api_key' => $issued['secret']])->assertOk();

        $response->assertJsonPath('data.tokens_used.input', '100');
        $response->assertJsonPath('data.tokens_used.output', '20');
        $response->assertJsonPath('data.tokens_used.total', '135');
        $response->assertJsonPath('data.total_spend.minor', '125000');
        $response->assertJsonPath('data.total_spend.currency', 'USD');
        $response->assertJsonPath('data.total_spend.exponent', 6);
        $response->assertJsonCount(1, 'data.recent_requests');
        $response->assertJsonPath('data.recent_requests.0.model', $alias->public_alias);
        $response->assertJsonPath('data.recent_requests.0.status', 'success');
        $response->assertJsonPath('data.recent_requests.0.input_tokens', '100');
        $response->assertJsonPath('data.recent_requests.0.output_tokens', '20');
        $response->assertJsonPath('data.recent_requests.0.charge.minor', '125000');
        $response->assertJsonMissingPath('data.recent_requests.0.internal_model');
        $response->assertJsonMissingPath('data.recent_requests.0.provider');
        $response->assertJsonMissingPath('data.recent_requests.0.provider_slug');
        $response->assertJsonMissingPath('data.recent_requests.0.route_version');
    }
}
