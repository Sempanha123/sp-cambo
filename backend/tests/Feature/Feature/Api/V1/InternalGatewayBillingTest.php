<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Events\CustomerStateChanged;
use App\Models\AiModel;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InternalGatewayBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.spcambo.gateway_secret' => 'internal-test-secret',
        ]);
    }

    public function test_gateway_authentication_fails_closed_when_secret_is_missing_or_wrong(): void
    {
        $this->postJson('/api/v1/internal/gateway/preflight', [])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->withToken('wrong')->postJson('/api/v1/internal/gateway/preflight', [])->assertUnauthorized();
        config(['services.spcambo.gateway_secret' => '']);
        $this->withToken('internal-test-secret')->postJson('/api/v1/internal/gateway/inspect', [])->assertUnauthorized();
    }

    public function test_inspection_returns_safe_key_models_limits_and_balances_only(): void
    {
        [$user, $alias, $created] = $this->customer(['requests_per_minute' => 9, 'max_request_bytes' => 4096]);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 100, []);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/inspect', ['customer_key' => $created['secret']])
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.allowed_models.0.id', 'claude-coding')
            ->assertJsonPath('data.limits.requests_per_minute', 9)->assertJsonPath('data.balances.token_quota_remaining', '100');
        $response->assertJsonMissingPath('data.allowed_models.0.internal_model')->assertJsonMissingPath('data.lookup_digest');
        $this->assertStringNotContainsString('private-route', $response->getContent());
    }

    public function test_inspection_excludes_lots_outside_the_key_model_scope(): void
    {
        [$user, $alias, $created] = $this->customer();
        $otherModel = AiModel::query()->create([
            'provider_id' => $alias->model->provider_id,
            'internal_model_id' => 'private-other-route',
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);
        $otherAlias = ModelAlias::query()->create([
            'ai_model_id' => $otherModel->id,
            'public_alias' => 'other-model',
            'display_name' => 'Other Model',
            'capabilities' => ['messages_api' => true],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);

        $this->grant($user, $alias, 'TOKEN_QUOTA', 100, []);
        $this->grant($user, $alias, 'CREDIT_BALANCE', 200, []);
        $this->grant($user, $otherAlias, 'TOKEN_QUOTA', 900, []);
        $this->grant($user, $otherAlias, 'CREDIT_BALANCE', 800, []);

        $this->gateway()
            ->postJson('/api/v1/internal/gateway/inspect', ['customer_key' => $created['secret']])
            ->assertOk()
            ->assertJsonCount(1, 'data.allowed_models')
            ->assertJsonPath('data.allowed_models.0.id', $alias->public_alias)
            ->assertJsonPath('data.balances.token_quota_remaining', '100')
            ->assertJsonPath('data.balances.credit_remaining', '200');
    }

    public function test_preflight_authoritatively_selects_token_billing_and_settles_local_input_plus_output_idempotently(): void
    {
        [$user, $alias, $created] = $this->customer(['max_output_tokens' => 50]);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [
            'input_weight_microunits' => 1_000_000,
            'output_weight_microunits' => 2_000_000,
            'cache_write_weight_microunits' => 0,
            'reasoning_weight_microunits' => 0,
        ]);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']))->assertOk();
        $reservationId = $response->json('data.reservation_id');
        $response->assertJsonPath('data.internal_model', 'private-route')->assertJsonPath('data.billing_mode', 'TOKEN_QUOTA')
            ->assertJsonPath('data.max_output_tokens', 50)->assertJsonPath('data.reserved_units', '60');
        $this->assertDatabaseHas('entitlement_lots', ['user_id' => $user->id, 'reserved_units' => 60]);

        $usage = ['input_tokens' => 10, 'output_tokens' => 20, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0, 'duration_ms' => 250];
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$reservationId}/settle", $usage)->assertOk()->assertJsonPath('data.settled_units', '30');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$reservationId}/settle", $usage)->assertOk();
        $this->assertDatabaseHas('entitlement_lots', ['user_id' => $user->id, 'remaining_units' => 470, 'reserved_units' => 0]);
        $this->assertDatabaseCount('usage_records', 1);
        $this->actingAs($user)->getJson('/api/v1/me/activity')->assertOk()->assertJsonPath('data.0.metered_units', '30')
            ->assertJsonMissingPath('data.0.internal_model')->assertJsonMissingPath('data.0.provider')->assertJsonMissingPath('data.0.route_version');
    }

    public function test_token_quota_bills_only_sp_local_cache_at_twenty_five_percent_and_ignores_provider_only_categories(): void
    {
        [$user, $alias, $created] = $this->customer();
        $this->grant($user, $alias, 'TOKEN_QUOTA', 1_000, [
            'local_cache_read_billing_bps' => 2_500,
            'input_weight_microunits' => 1_000_000,
            'output_weight_microunits' => 1_000_000,
            'cache_read_weight_microunits' => 250_000,
            'cache_write_weight_microunits' => 0,
            'reasoning_weight_microunits' => 0,
        ]);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'estimated_input_tokens' => 10,
            'estimated_cache_read_tokens' => 100,
            'requested_max_output_tokens' => 20,
        ]))->assertOk()->assertJsonPath('data.reserved_units', '55');

        $reservationId = $response->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$reservationId}/settle", [
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cache_read_tokens' => 100,
            // These classes can be present in a protocol payload, but R43 does
            // not let provider/backend-only counters change Token quota billing.
            'cache_write_tokens' => 500,
            'reasoning_tokens' => 500,
        ])->assertOk()->assertJsonPath('data.settled_units', '55');

        $this->assertDatabaseHas('entitlement_lots', ['user_id' => $user->id, 'remaining_units' => 945, 'reserved_units' => 0]);
        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $reservationId,
            'input_tokens' => 10,
            'cache_read_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 130,
            'metered_units' => 55,
        ]);
    }

    public function test_count_tokens_preflight_reserves_input_only_and_settles_top_level_usage(): void
    {
        [$user, $alias, $created] = $this->customer();
        $this->grant($user, $alias, 'TOKEN_QUOTA', 100, []);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'estimated_input_tokens' => 15,
            'requested_max_output_tokens' => 0,
            'endpoint' => '/v1/messages/count_tokens',
        ]))->assertOk()->assertJsonPath('data.reserved_units', '15');

        $this->gateway()->postJson('/api/v1/internal/gateway/reservations/'.$response->json('data.reservation_id').'/settle', [
            'input_tokens' => 15,
            'output_tokens' => 0,
        ])->assertOk()->assertJsonPath('data.settled_units', '15');
    }

    public function test_credit_pricing_is_snapshotted_at_preflight_and_gateway_cannot_choose_or_set_charge(): void
    {
        [$user, $alias, $created] = $this->customer();
        $alias->pricing()->create([
            'currency' => 'USD',
            'exponent' => 6,
            'input_per_million_minor' => 1_000_000,
            'output_per_million_minor' => 2_000_000,
            'upstream_input_per_million_minor' => 400_000,
            'upstream_output_per_million_minor' => 800_000,
            'upstream_cache_read_per_million_minor' => 400_000,
            'upstream_cache_write_per_million_minor' => 400_000,
            'upstream_reasoning_per_million_minor' => 800_000,
            'upstream_cost_verified_at' => now(),
        ]);
        $this->grant($user, $alias, 'CREDIT_BALANCE', 1_000, []);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], ['estimated_input_tokens' => 10, 'requested_max_output_tokens' => 20]))->assertOk();
        $reservationId = $response->json('data.reservation_id');
        $response->assertJsonPath('data.billing_mode', 'CREDIT_BALANCE')->assertJsonPath('data.reserved_units', '50');

        $alias->pricing()->update(['input_per_million_minor' => 100_000_000, 'output_per_million_minor' => 100_000_000]);
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$reservationId}/settle", ['input_tokens' => 10, 'output_tokens' => 20])
            ->assertOk()->assertJsonPath('data.settled_units', '50');
        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $reservationId,
            'provider_family' => 'claude',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'total_tokens' => 30,
            'metered_units' => 50,
            'credit_charge_minor' => 50,
            'upstream_cost_minor' => 20,
        ]);
    }

    public function test_legacy_billing_multipliers_do_not_change_r39_local_standard_customer_settlement(): void
    {
        // Token-quota settlement: 10 raw input at 1.10x + 20 raw output at
        // 1.20x => 11 + 24 = 35 SP billable units when the package weights are 1x.
        [$quotaUser, $quotaAlias, $quotaKey] = $this->customer(['max_output_tokens' => 20]);
        $this->grant($quotaUser, $quotaAlias, 'TOKEN_QUOTA', 1_000, [
            'input_weight_microunits' => 1_000_000,
            'output_weight_microunits' => 1_000_000,
            'billing_multipliers_bps' => [
                'input' => 11_000,
                'output' => 12_000,
            ],
        ]);

        $quotaPreflight = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($quotaKey['secret'], [
            'estimated_input_tokens' => 10,
            'requested_max_output_tokens' => 20,
            'request_id' => 'multiplied-quota',
            'request_fingerprint' => hash('sha256', 'multiplied-quota'),
        ]))->assertOk()->assertJsonPath('data.reserved_units', '30');

        $quotaReservation = $quotaPreflight->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$quotaReservation}/settle", [
            'input_tokens' => 10,
            'output_tokens' => 20,
        ])->assertOk()->assertJsonPath('data.settled_units', '30');
        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $quotaReservation,
            'input_tokens' => 10,
            'output_tokens' => 20,
            'total_tokens' => 30,
            'metered_units' => 30,
        ]);

        // Credit settlement uses the same published multiplier before applying the
        // customer base rate. Upstream cost still uses the unmodified raw usage.
        [$creditUser, $creditAlias, $creditKey] = $this->customer(['max_output_tokens' => 20]);
        $creditAlias->forceFill(['limits' => [
            'billing_multipliers_bps' => [
                'input' => 11_000,
                'output' => 12_000,
            ],
        ]])->save();
        $creditAlias->pricing()->create([
            'currency' => 'USD',
            'exponent' => 6,
            'input_per_million_minor' => 1_000_000,
            'output_per_million_minor' => 2_000_000,
            'upstream_input_per_million_minor' => 400_000,
            'upstream_output_per_million_minor' => 800_000,
            'upstream_cache_read_per_million_minor' => 400_000,
            'upstream_cache_write_per_million_minor' => 400_000,
            'upstream_reasoning_per_million_minor' => 800_000,
            'upstream_cost_verified_at' => now(),
        ]);
        $this->grant($creditUser, $creditAlias, 'CREDIT_BALANCE', 1_000, []);

        $creditPreflight = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($creditKey['secret'], [
            'estimated_input_tokens' => 10,
            'requested_max_output_tokens' => 20,
            'request_id' => 'multiplied-credit',
            'request_fingerprint' => hash('sha256', 'multiplied-credit'),
        ]))->assertOk()->assertJsonPath('data.reserved_units', '50');

        $creditReservation = $creditPreflight->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$creditReservation}/settle", [
            'input_tokens' => 10,
            'output_tokens' => 20,
        ])->assertOk()->assertJsonPath('data.settled_units', '50');
        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $creditReservation,
            'input_tokens' => 10,
            'output_tokens' => 20,
            'total_tokens' => 30,
            'metered_units' => 50,
            'credit_charge_minor' => 50,
            'upstream_cost_minor' => 20,
        ]);
    }

    public function test_token_quota_usage_also_snapshots_private_upstream_cost_for_admin_profitability(): void
    {
        [$user, $alias, $created] = $this->customer();
        $alias->pricing()->create([
            'currency' => 'USD',
            'exponent' => 6,
            'input_per_million_minor' => 1_000_000,
            'output_per_million_minor' => 2_000_000,
            'upstream_input_per_million_minor' => 400_000,
            'upstream_output_per_million_minor' => 800_000,
            'upstream_cache_read_per_million_minor' => 400_000,
            'upstream_cache_write_per_million_minor' => 400_000,
            'upstream_reasoning_per_million_minor' => 800_000,
            'upstream_cost_verified_at' => now(),
        ]);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 1_000, []);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'estimated_input_tokens' => 10,
            'requested_max_output_tokens' => 20,
        ]))->assertOk()->assertJsonPath('data.billing_mode', 'TOKEN_QUOTA');

        $reservationId = $response->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$reservationId}/settle", [
            'input_tokens' => 10,
            'output_tokens' => 20,
        ])->assertOk()->assertJsonPath('data.settled_units', '30');

        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $reservationId,
            'credit_charge_minor' => null,
            'upstream_cost_minor' => 20,
            'currency' => 'USD',
            'currency_exponent' => 6,
        ]);
    }

    public function test_unverified_upstream_rates_are_not_recorded_as_real_cost(): void
    {
        [$user, $alias, $created] = $this->customer();
        $alias->pricing()->create([
            'currency' => 'USD',
            'exponent' => 6,
            'input_per_million_minor' => 1_000_000,
            'output_per_million_minor' => 2_000_000,
            'upstream_input_per_million_minor' => 400_000,
            'upstream_output_per_million_minor' => 800_000,
            'upstream_cache_read_per_million_minor' => 400_000,
            'upstream_cache_write_per_million_minor' => 400_000,
            'upstream_reasoning_per_million_minor' => 800_000,
            'upstream_cost_verified_at' => null,
        ]);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 1_000, []);

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'estimated_input_tokens' => 10,
            'requested_max_output_tokens' => 20,
        ]))->assertOk();

        $reservationId = $response->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$reservationId}/settle", [
            'input_tokens' => 10,
            'output_tokens' => 20,
        ])->assertOk();

        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $reservationId,
            'upstream_cost_minor' => null,
        ]);
    }

    public function test_customer_api_key_cannot_spend_daily_playground_quota(): void
    {
        [$user, $alias, $created] = $this->customer();
        $free = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], 'PLAYGROUND_DAILY');
        $paid = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], 'ADMIN_GRANT');

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']))
            ->assertOk()->assertJsonPath('data.reserved_units', '60');

        $this->assertDatabaseHas('entitlement_lots', ['id' => $free->id, 'reserved_units' => 0]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $paid->id, 'reserved_units' => 60]);
        $this->gateway()->postJson('/api/v1/internal/gateway/inspect', ['customer_key' => $created['secret']])
            ->assertOk()->assertJsonPath('data.balances.token_quota_remaining', '440');
        $this->assertDatabaseHas('reservations', ['id' => $response->json('data.reservation_id'), 'api_key_id' => $created['key']->id]);
    }

    public function test_playground_key_defaults_to_daily_scope_and_cannot_silently_fall_through_to_paid_entitlements(): void
    {
        [$user, $alias, $created] = $this->customer();
        DB::table('playground_credentials')->insert([
            'user_id' => $user->id,
            'api_key_id' => $created['key']->id,
            'secret_ciphertext' => 'test-only-not-read',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $free = $this->grant($user, $alias, 'TOKEN_QUOTA', 20, [], 'PLAYGROUND_DAILY');
        $paid = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], 'ADMIN_GRANT');

        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']))
            ->assertStatus(402)->assertJsonPath('code', 'insufficient_tokens');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $free->id, 'reserved_units' => 0, 'remaining_units' => 20]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $paid->id, 'reserved_units' => 0, 'remaining_units' => 500]);
        $this->gateway()->postJson('/api/v1/internal/gateway/inspect', ['customer_key' => $created['secret']])
            ->assertOk()->assertJsonPath('data.balances.token_quota_remaining', '20');
    }

    public function test_playground_key_can_explicitly_use_customer_balance_without_touching_daily_quota(): void
    {
        [$user, $alias, $created] = $this->customer();
        DB::table('playground_credentials')->insert([
            'user_id' => $user->id,
            'api_key_id' => $created['key']->id,
            'secret_ciphertext' => 'test-only-not-read',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $free = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], 'PLAYGROUND_DAILY');
        $redeem = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], 'REDEEM_CODE');
        $paid = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, [], 'ORDER');

        $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'playground_funding_scope' => 'BALANCE',
        ]))->assertOk()->assertJsonPath('data.reserved_units', '60');

        $this->assertDatabaseHas('entitlement_lots', ['id' => $free->id, 'reserved_units' => 0]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $redeem->id, 'reserved_units' => 60]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $paid->id, 'reserved_units' => 0]);
        $this->assertDatabaseHas('reservations', ['id' => $response->json('data.reservation_id'), 'api_key_id' => $created['key']->id]);
    }

    public function test_invalid_key_model_protocol_size_and_depleted_balance_never_create_reservations(): void
    {
        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight('sk-invalid'))
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_api_key');
        [$user, $alias, $created] = $this->customer(['max_request_bytes' => 100]);
        $this->grant($user, $alias, 'TOKEN_QUOTA', 5, []);

        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], ['request_bytes' => 101]))
            ->assertStatus(413)->assertJsonPath('code', 'request_too_large');
        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], ['public_model' => 'unknown']))
            ->assertForbidden()->assertJsonPath('code', 'model_not_allowed');
        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], ['endpoint' => '/v1/responses']))
            ->assertStatus(400)->assertJsonPath('code', 'model_unavailable');
        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']))
            ->assertStatus(402)->assertJsonPath('code', 'insufficient_tokens');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_request_id_cannot_be_replayed_with_a_different_request_fingerprint(): void
    {
        [$user, $alias, $created] = $this->customer();
        $this->grant($user, $alias, 'TOKEN_QUOTA', 500, []);
        $first = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']))->assertOk();
        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']))
            ->assertOk()->assertJsonPath('data.reservation_id', $first->json('data.reservation_id'));
        $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'request_fingerprint' => hash('sha256', 'different-body'),
        ]))->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_request_log_failure_rolls_back_the_reservation_allocations_and_ledger(): void
    {
        [$user, $alias, $created] = $this->customer();
        $lot = $this->grant($user, $alias, 'TOKEN_QUOTA', 500, []);
        $ledgerCount = DB::table('credit_ledger')->count();
        DB::connection()->unsetEventDispatcher();
        DB::statement("CREATE TRIGGER fail_api_request_log BEFORE INSERT ON api_request_logs BEGIN SELECT RAISE(FAIL, 'forced request log failure'); END");

        try {
            $response = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret']));
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_api_request_log');
            DB::connection()->setEventDispatcher(app('events'));
        }

        $response->assertStatus(500)->assertJsonPath('code', 'server_error');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_allocations', 0);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($ledgerCount, DB::table('credit_ledger')->count());
        $this->assertDatabaseHas('entitlement_lots', [
            'id' => $lot->id,
            'remaining_units' => 500,
            'reserved_units' => 0,
        ]);
    }

    public function test_r39_reconcile_uses_local_usage_or_releases_without_waiting_for_provider_usage(): void
    {
        Event::fake([CustomerStateChanged::class]);
        [$user, $alias, $created] = $this->customer();
        $this->grant($user, $alias, 'TOKEN_QUOTA', 500, []);

        $released = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'request_id' => 'reconcile-release',
            'request_fingerprint' => hash('sha256', 'reconcile-release'),
        ]))->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$released}/reconcile", [
            'reason' => 'upstream_timeout',
        ])->assertOk()->assertJsonPath('data.status', 'RELEASED');
        $this->assertDatabaseHas('api_request_logs', ['reservation_id' => $released, 'state' => 'RELEASED', 'error_code' => null]);

        $settled = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'request_id' => 'reconcile-local-usage',
            'request_fingerprint' => hash('sha256', 'reconcile-local-usage'),
        ]))->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$settled}/reconcile", [
            'reason' => 'settlement_failed',
            'local_usage' => [
                'input_tokens' => 10,
                'output_tokens' => 20,
                'cache_read_tokens' => 999,
                'reasoning_tokens' => 999,
                'duration_ms' => 250,
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'SETTLED')
            ->assertJsonPath('data.settled_units', '60')
            ->assertJsonPath('data.recovered_locally', true);
        $this->assertDatabaseHas('usage_records', [
            'reservation_id' => $settled,
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cache_read_tokens' => 999,
            'reasoning_tokens' => 0,
            'metered_units' => 60,
        ]);
        $this->assertSame(0, (int) EntitlementLot::query()->where('user_id', $user->id)->sum('reserved_units'));
    }

    public function test_cross_terminal_gateway_operations_conflict_without_contradictory_side_effects(): void
    {
        Event::fake([CustomerStateChanged::class]);
        [$user, $alias, $created] = $this->customer();
        $this->grant($user, $alias, 'TOKEN_QUOTA', 500, []);

        $released = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'request_id' => 'released-then-settled',
            'request_fingerprint' => hash('sha256', 'released-then-settled'),
        ]))->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$released}/release")->assertOk();
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$released}/settle", [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseHas('reservations', ['id' => $released, 'status' => 'RELEASED', 'settled_units' => 0]);
        $this->assertDatabaseHas('api_request_logs', ['reservation_id' => $released, 'state' => 'RELEASED']);
        $this->assertDatabaseMissing('usage_records', ['reservation_id' => $released]);

        $settled = $this->gateway()->postJson('/api/v1/internal/gateway/preflight', $this->preflight($created['secret'], [
            'request_id' => 'settled-then-released',
            'request_fingerprint' => hash('sha256', 'settled-then-released'),
        ]))->json('data.reservation_id');
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$settled}/settle", [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ])->assertOk();
        Event::fake([CustomerStateChanged::class]);
        $this->gateway()->postJson("/api/v1/internal/gateway/reservations/{$settled}/release")
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseHas('reservations', ['id' => $settled, 'status' => 'SETTLED', 'settled_units' => 0]);
        $this->assertDatabaseHas('api_request_logs', ['reservation_id' => $settled, 'state' => 'SETTLED']);
        Event::assertNotDispatched(CustomerStateChanged::class);
    }

    private function customer(array $keyAttributes = []): array
    {
        $user = User::factory()->create();

        $existingAlias = ModelAlias::query()->where('public_alias', 'claude-coding')->first();
        if ($existingAlias) {
            $created = app(ApiKeySecretService::class)->create(
                $user,
                ['label' => 'Gateway', ...$keyAttributes],
                [$existingAlias->id],
            );

            return [$user, $existingAlias, $created];
        }

        $provider = Provider::query()->create(['name' => 'Provider', 'slug' => 'provider-'.uniqid(), 'enabled' => true]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'https://private-provider.example',
            'connection_type' => 'omniroute',
            'credential' => 'test-upstream-secret',
            'credential_suffix' => 'cret',
            'timeout_ms' => 30000,
            'policy_version' => 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_READY,
            'last_probe_status' => 'SUCCESS',
            'last_probe_at' => now(),
        ]);
        $provider->forceFill(['active_connection_revision_id' => $revision->id])->save();
        $model = AiModel::query()->create(['provider_id' => $provider->id, 'internal_model_id' => 'private-route', 'family' => 'claude', 'family_label' => 'Claude', 'commercial_resale_verified_at' => now(), 'enabled' => true]);
        $alias = ModelAlias::query()->create(['ai_model_id' => $model->id, 'public_alias' => 'claude-coding', 'display_name' => 'Claude Coding', 'capabilities' => ['messages_api' => true, 'max_output_tokens' => 100], 'limits' => [], 'status' => 'active', 'enabled' => true, 'customer_visible' => true]);
        $created = app(ApiKeySecretService::class)->create($user, ['label' => 'Gateway', ...$keyAttributes], [$alias->id]);

        return [$user, $alias, $created];
    }

    private function grant(User $user, ModelAlias $alias, string $mode, int $units, array $billingRules, string $sourceType = 'ADMIN_GRANT'): EntitlementLot
    {
        return app(EntitlementService::class)->grant($user, ['source_type' => $sourceType, 'source_id' => uniqid('grant-', true), 'package_name' => 'Test', 'family_label' => 'Claude', 'billing_mode' => $mode, 'original_units' => $units, 'unit_label' => $mode === 'CREDIT_BALANCE' ? 'microcredits' : 'tokens', 'currency' => $mode === 'CREDIT_BALANCE' ? 'USD' : null, 'currency_exponent' => $mode === 'CREDIT_BALANCE' ? 6 : null, 'allowed_model_aliases' => [$alias->public_alias], 'billing_snapshot' => ['billing_rules' => $billingRules], 'expires_at' => now()->addDay()], 'grant:'.uniqid('', true));
    }

    private function preflight(string $secret, array $overrides = []): array
    {
        return $overrides + ['customer_key' => $secret, 'public_model' => 'claude-coding', 'estimated_input_tokens' => 10, 'requested_max_output_tokens' => 50, 'request_bytes' => 100, 'request_id' => 'gateway-request-1', 'request_fingerprint' => hash('sha256', 'gateway-request-1'), 'endpoint' => '/v1/messages'];
    }

    private function gateway(): static
    {
        return $this->withToken('internal-test-secret');
    }
}
