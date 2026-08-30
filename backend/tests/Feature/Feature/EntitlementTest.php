<?php

namespace Tests\Feature\Feature;

use App\Events\CustomerStateChanged;
use App\Models\CreditLedger;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_is_idempotent_and_exact_24_hour_expiry_is_preserved(): void
    {
        $user = User::factory()->create();
        $activated = now()->startOfSecond();
        $snapshot = $this->snapshot(['activated_at' => $activated, 'expires_at' => $activated->copy()->addSeconds(86400)]);
        $first = app(EntitlementService::class)->grant($user, $snapshot, 'grant:one');
        $second = app(EntitlementService::class)->grant($user, $snapshot, 'grant:one');
        $this->assertTrue($first->is($second));
        $this->assertSame(86400, (int) $first->activated_at->diffInSeconds($first->expires_at));
        $this->assertDatabaseCount('entitlement_lots', 1);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_grant_stores_reason_only_in_credit_ledger(): void
    {
        $user = User::factory()->create();
        $reason = 'Referral reward granted when an invited customer registered successfully.';

        $lot = app(EntitlementService::class)->grant(
            $user,
            $this->snapshot([
                'source_type' => 'REFERRAL',
                'source_id' => 'registration-reward-test',
                'billing_mode' => 'CREDIT_BALANCE',
                'original_units' => 25,
                'unit_label' => 'USD credit',
                'currency' => 'USD',
                'currency_exponent' => 2,
                'reason' => $reason,
            ]),
            'grant:reason-ledger-only',
        );

        $this->assertDatabaseHas('entitlement_lots', [
            'id' => $lot->id,
            'source_type' => 'REFERRAL',
            'original_units' => 25,
        ]);
        $this->assertDatabaseHas('credit_ledger', [
            'entitlement_lot_id' => $lot->id,
            'idempotency_key' => 'grant:reason-ledger-only',
            'type' => 'REFERRAL_REWARD',
            'amount' => 25,
            'reason' => $reason,
        ]);
    }

    public function test_expiration_forfeits_spendable_remainder_once_and_records_immutable_ledger(): void
    {
        $lot = app(EntitlementService::class)->grant(User::factory()->create(), $this->snapshot(['original_units' => 1000, 'expires_at' => now()->subSecond()]), 'grant:expiry');
        app(EntitlementService::class)->expire($lot);
        app(EntitlementService::class)->expire($lot->fresh());
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 0, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('credit_ledger', ['idempotency_key' => "expiration:{$lot->id}", 'amount' => -1000]);
        $this->assertDatabaseCount('credit_ledger', 2);
        $this->expectException(LogicException::class);
        CreditLedger::query()->where('idempotency_key', 'grant:expiry')->firstOrFail()->update(['amount' => 1]);
    }

    public function test_expired_lots_are_discovered_in_batches_and_emit_one_safe_event_each(): void
    {
        Event::fake([CustomerStateChanged::class]);
        $service = app(EntitlementService::class);
        $first = $service->grant(User::factory()->create(), $this->snapshot(['original_units' => 100, 'expires_at' => now()->subMinutes(2)]), 'grant:expired-first');
        $second = $service->grant(User::factory()->create(), $this->snapshot(['original_units' => 200, 'expires_at' => now()->subMinute()]), 'grant:expired-second');
        $active = $service->grant(User::factory()->create(), $this->snapshot(['original_units' => 300, 'expires_at' => now()->addMinute()]), 'grant:active');

        $this->assertSame(1, $service->expireDue(1));
        $this->assertSame(1, $service->expireDue(1));
        $this->assertSame(0, $service->expireDue(1));
        $this->assertDatabaseHas('entitlement_lots', ['id' => $first->id, 'remaining_units' => 0, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $second->id, 'remaining_units' => 0, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $active->id, 'remaining_units' => 300, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('credit_ledger', 5);
        Event::assertDispatchedTimes(CustomerStateChanged::class, 2);
        Event::assertDispatched(CustomerStateChanged::class, fn (CustomerStateChanged $event): bool => $event->event === 'entitlement.expired'
            && $event->safeData === ['entitlement_id' => $first->id, 'status' => 'EXPIRED']);
    }

    public function test_scheduled_expiration_preserves_reserved_capacity_until_reservation_finalizes(): void
    {
        $user = User::factory()->create();
        $service = app(EntitlementService::class);
        $lot = $service->grant($user, $this->snapshot(['original_units' => 100, 'expires_at' => now()->addMinute()]), 'grant:reserved-expiry');
        $reservation = app(ReservationService::class)->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 80, 'request:reserved-expiry');
        $lot->update(['expires_at' => now()->subSecond()]);

        $this->artisan('billing:expire-entitlements --batch=10')
            ->expectsOutput('Expired 1 entitlement lot(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 80, 'reserved_units' => 80, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('credit_ledger', ['idempotency_key' => "expiration:{$lot->id}", 'amount' => -20]);

        app(ReservationService::class)->release($reservation);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 0, 'reserved_units' => 0, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('credit_ledger', ['idempotency_key' => "reservation-expiration:{$reservation->id}:{$lot->id}", 'amount' => -80]);
    }

    public function test_expired_lot_batch_size_is_bounded(): void
    {
        foreach ([0, 1001] as $batchSize) {
            try {
                app(EntitlementService::class)->expireDue($batchSize);
                $this->fail("Expected batch size {$batchSize} to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Expiration batch size must be between 1 and 1000.', $exception->getMessage());
            }
        }
    }

    public function test_customer_balance_separates_token_packages_from_quota_backed_credit_packages(): void
    {
        $user = User::factory()->create();
        $service = app(EntitlementService::class);

        $service->grant($user, $this->snapshot([
            'source_id' => 'token-package',
            'original_units' => 1_000,
            'package_name' => 'Claude 1K Tokens',
            'unit_label' => 'Tokens',
        ]), 'grant:token-package');

        $service->grant($user, $this->snapshot([
            'source_id' => 'credit-package',
            'original_units' => 10_000_000,
            'package_name' => 'Claude 100 Credits',
            'unit_label' => 'Tokens',
            'billing_snapshot' => [
                'billing_rules' => [
                    'package_kind' => 'SP_CREDITS',
                    'display_units' => 100,
                    'display_unit_label' => 'Credits',
                    'sp_credit_billable_units' => 100_000,
                ],
            ],
        ]), 'grant:credit-package');

        $this->actingAs($user)->getJson('/api/v1/me/balance')
            ->assertOk()
            ->assertJsonPath('data.token_quota.remaining_units', '1000')
            ->assertJsonPath('data.sp_credit_quota.remaining', '100')
            ->assertJsonPath('data.active_lot_count', 2);
    }

    public function test_customer_balance_and_lots_are_exact_and_tenant_isolated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        app(EntitlementService::class)->grant($owner, $this->snapshot(['original_units' => 20000000]), 'grant:owner');
        app(EntitlementService::class)->grant($other, $this->snapshot(['original_units' => 999]), 'grant:other');
        $this->actingAs($owner)->getJson('/api/v1/me/balance')->assertOk()->assertJsonPath('data.token_quota.remaining_units', '20000000')->assertJsonPath('data.active_lot_count', 1);
        $this->actingAs($owner)->getJson('/api/v1/me/entitlements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.original_units', '20000000');
    }

    private function snapshot(array $overrides = []): array
    {
        return $overrides + ['source_type' => 'ADMIN_GRANT', 'source_id' => 'test', 'package_name' => 'Claude 20M', 'family_label' => 'Claude', 'billing_mode' => 'TOKEN_QUOTA', 'original_units' => 20000000, 'unit_label' => 'tokens', 'allowed_model_aliases' => ['claude-coding'], 'billing_snapshot' => ['input_weight_micros' => 1000000], 'expires_at' => now()->addDay()];
    }
}
