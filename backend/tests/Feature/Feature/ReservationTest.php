<?php

namespace Tests\Feature\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_allocates_fefo_and_settlement_releases_unused_units_idempotently(): void
    {
        $user = User::factory()->create();
        $entitlements = app(EntitlementService::class);
        $early = $entitlements->grant($user, $this->snapshot(100, now()->addHour()), 'grant:early');
        $late = $entitlements->grant($user, $this->snapshot(100, now()->addDay()), 'grant:late');
        $service = app(ReservationService::class);
        $reservation = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 150, 'request:one');
        $this->assertSame([$early->id, $late->id], $reservation->allocations->pluck('entitlement_lot_id')->all());
        $this->assertSame([100, 50], $reservation->allocations->pluck('reserved_units')->all());
        $settled = $service->settle($reservation, 120);
        $service->settle($settled, 120);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $early->id, 'remaining_units' => 0, 'reserved_units' => 0, 'status' => 'DEPLETED']);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $late->id, 'remaining_units' => 80, 'reserved_units' => 0]);
        $this->assertDatabaseCount('credit_ledger', 8);
    }

    public function test_insufficient_reservation_rolls_back_without_mutation_or_ledger_entry(): void
    {
        $user = User::factory()->create();
        $lot = app(EntitlementService::class)->grant($user, $this->snapshot(50, now()->addDay()), 'grant:small');
        try {
            app(ReservationService::class)->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 51, 'request:too-large');
            $this->fail('Expected insufficient balance.');
        } catch (InsufficientBalanceException $exception) {
            $this->assertSame('TOKEN_QUOTA', $exception->billingMode);
        }
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'reserved_units' => 0, 'remaining_units' => 50]);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_release_is_idempotent_and_restores_all_spendable_capacity(): void
    {
        $user = User::factory()->create();
        $lot = app(EntitlementService::class)->grant($user, $this->snapshot(75, now()->addDay()), 'grant:release');
        $service = app(ReservationService::class);
        $reservation = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 60, 'request:release');
        $service->release($reservation);
        $service->release($reservation->fresh());
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 75, 'reserved_units' => 0]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'RELEASED', 'settled_units' => 0]);
    }

    public function test_cross_terminal_operations_are_rejected_even_when_final_units_match(): void
    {
        $user = User::factory()->create();
        app(EntitlementService::class)->grant($user, $this->snapshot(400, now()->addDay()), 'grant:terminal-conflicts');
        $service = app(ReservationService::class);

        $released = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:released-conflict');
        $service->release($released);
        $this->assertTerminalConflict(fn () => $service->settle($released->fresh(), 0));

        $expired = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:expired-conflict');
        $service->expire($expired);
        $this->assertTerminalConflict(fn () => $service->settle($expired->fresh(), 0));

        $settled = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:settled-conflict');
        $service->settle($settled, 0);
        $this->assertTerminalConflict(fn () => $service->release($settled->fresh()));
        $this->assertTerminalConflict(fn () => $service->expire($settled->fresh()));
    }

    public function test_repeating_the_same_terminal_operation_remains_idempotent(): void
    {
        $user = User::factory()->create();
        app(EntitlementService::class)->grant($user, $this->snapshot(300, now()->addDay()), 'grant:terminal-idempotency');
        $service = app(ReservationService::class);

        $settled = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:settled-idempotency');
        $service->settle($settled, 0);
        $this->assertSame('SETTLED', $service->settle($settled->fresh(), 0)->status);

        $released = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:released-idempotency');
        $service->release($released);
        $this->assertSame('RELEASED', $service->release($released->fresh())->status);

        $expired = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:expired-idempotency');
        $service->expire($expired);
        $this->assertSame('EXPIRED', $service->expire($expired->fresh())->status);
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_reservation(): void
    {
        $user = User::factory()->create();
        app(EntitlementService::class)->grant($user, $this->snapshot(100, now()->addDay()), 'grant:idempotency');
        $service = app(ReservationService::class);
        $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 50, 'request:duplicate');

        $this->expectException(InvalidArgumentException::class);
        $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 51, 'request:duplicate');
    }

    public function test_unused_reserved_units_are_forfeited_when_the_lot_expires_before_settlement(): void
    {
        $user = User::factory()->create();
        $entitlements = app(EntitlementService::class);
        $lot = $entitlements->grant($user, $this->snapshot(100, now()->addMinute()), 'grant:expires-during-request');
        $service = app(ReservationService::class);
        $reservation = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 80, 'request:expires-during-request');

        $lot->update(['expires_at' => now()->subSecond()]);
        $entitlements->expire($lot->fresh());
        $service->settle($reservation, 30);

        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 0, 'reserved_units' => 0, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('credit_ledger', ['idempotency_key' => "reservation-expiration:{$reservation->id}:{$lot->id}", 'type' => 'EXPIRATION', 'amount' => -50]);
    }

    public function test_stale_reservation_recovery_releases_capacity_once(): void
    {
        $user = User::factory()->create();
        $lot = app(EntitlementService::class)->grant($user, $this->snapshot(100, now()->addDay()), 'grant:stale');
        $service = app(ReservationService::class);
        $reservation = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 70, 'request:stale');
        $reservation->update(['expires_at' => now()->subSecond()]);

        $this->assertSame(1, $service->recoverStale());
        $this->assertSame(0, $service->recoverStale());
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'EXPIRED', 'settled_units' => 0]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 100, 'reserved_units' => 0]);
    }

    public function test_reconciliation_reservation_can_be_settled_when_authoritative_usage_arrives(): void
    {
        $user = User::factory()->create();
        $lot = app(EntitlementService::class)->grant($user, $this->snapshot(100, now()->addDay()), 'grant:reconcile-settle');
        $service = app(ReservationService::class);
        $reservation = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 70, 'request:reconcile-settle');
        $service->markForReconciliation($reservation, 'settlement_failed');

        $settled = $service->settle($reservation->fresh(), 45);

        $this->assertSame('SETTLED', $settled->status);
        $this->assertNull($settled->reconciliation_reason);
        $this->assertNull($settled->reconciliation_requested_at);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 55, 'reserved_units' => 0]);
    }

    public function test_stale_reconciliation_reservation_can_expire_without_spending_capacity(): void
    {
        $user = User::factory()->create();
        $lot = app(EntitlementService::class)->grant($user, $this->snapshot(100, now()->addDay()), 'grant:reconcile-expire');
        $service = app(ReservationService::class);
        $reservation = $service->reserve($user, 'claude-coding', 'TOKEN_QUOTA', 70, 'request:reconcile-expire');
        $service->markForReconciliation($reservation, 'usage_unavailable');
        $reservation->update(['expires_at' => now()->subSecond()]);

        $this->assertSame(1, $service->recoverStale());
        $this->assertSame(0, $service->recoverStale());
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'EXPIRED', 'settled_units' => 0, 'reconciliation_reason' => null]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $lot->id, 'remaining_units' => 100, 'reserved_units' => 0]);
    }

    private function assertTerminalConflict(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a cross-terminal idempotency conflict.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Reservation was already finalized by a different operation.', $exception->getMessage());
        }
    }

    private function snapshot(int $units, $expires): array
    {
        return ['source_type' => 'ADMIN_GRANT', 'source_id' => 'test', 'package_name' => 'Test', 'family_label' => 'Claude', 'billing_mode' => 'TOKEN_QUOTA', 'original_units' => $units, 'unit_label' => 'tokens', 'allowed_model_aliases' => ['claude-coding'], 'billing_snapshot' => ['input_weight_micros' => 1000000], 'expires_at' => $expires];
    }
}
