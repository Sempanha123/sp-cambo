<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperationsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_queue_requires_admin_view_and_does_not_auto_release_stale_unknown_usage(): void
    {
        [$customer, $lot, $reservation] = $this->reconciliationReservation();
        $reservation->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(0, app(ReservationService::class)->recoverStale());
        $this->assertSame('RECONCILIATION_REQUIRED', $reservation->fresh()->status);
        $this->assertSame(70, (int) $lot->fresh()->reserved_units);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/admin/operations/reconciliation-reservations')
            ->assertForbidden();

        $admin = $this->admin(view: true, access: false);
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/operations/reconciliation-reservations')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $reservation->id)
            ->assertJsonPath('data.0.user.email', $customer->email)
            ->assertJsonPath('data.0.reserved_units', '70')
            ->assertJsonPath('data.0.reason', 'usage_unavailable');
    }

    public function test_reconciliation_release_requires_access_permission_exact_confirmation_and_audits_the_resolution(): void
    {
        [, $lot, $reservation] = $this->reconciliationReservation();

        $viewOnly = $this->admin(view: true, access: false);
        $this->actingAs($viewOnly)->postJson("/api/v1/admin/operations/reservations/{$reservation->id}/release-confirmed", [
            'reason' => 'Provider confirms that this request never reached inference.',
            'confirmation' => 'CONFIRMED NO UPSTREAM USAGE',
        ])->assertForbidden();

        $admin = $this->admin(view: true, access: true);
        $this->actingAs($admin)->postJson("/api/v1/admin/operations/reservations/{$reservation->id}/release-confirmed", [
            'reason' => 'Provider confirms that this request never reached inference.',
            'confirmation' => 'release it',
        ])->assertUnprocessable();

        $this->assertSame('RECONCILIATION_REQUIRED', $reservation->fresh()->status);
        $this->assertSame(70, (int) $lot->fresh()->reserved_units);

        $this->actingAs($admin)->postJson("/api/v1/admin/operations/reservations/{$reservation->id}/release-confirmed", [
            'reason' => 'Provider confirms that this request never reached inference.',
            'confirmation' => 'CONFIRMED NO UPSTREAM USAGE',
        ])->assertOk()
            ->assertJsonPath('data.status', 'RELEASED')
            ->assertJsonPath('data.settled_units', '0');

        $this->assertSame(0, (int) $lot->fresh()->reserved_units);
        $this->assertSame(100, (int) $lot->fresh()->remaining_units);
        $this->assertDatabaseHas('api_request_logs', [
            'reservation_id' => $reservation->id,
            'state' => 'RELEASED',
            'error_code' => 'operator_confirmed_no_upstream_usage',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'operations.reconciliation_released',
            'subject_id' => (string) $reservation->id,
        ]);
        $this->assertStringNotContainsString('CONFIRMED NO UPSTREAM USAGE', json_encode(AuditLog::query()->latest('id')->firstOrFail()->metadata));
    }

    private function reconciliationReservation(): array
    {
        $customer = User::factory()->create();
        $lot = app(EntitlementService::class)->grant($customer, [
            'source_type' => 'ADMIN_GRANT',
            'source_id' => 'reconciliation-test',
            'package_name' => 'Reconciliation Test',
            'family_label' => 'Claude',
            'billing_mode' => 'TOKEN_QUOTA',
            'original_units' => 100,
            'unit_label' => 'tokens',
            'allowed_model_aliases' => ['claude-coding'],
            'billing_snapshot' => ['input_weight_microunits' => 1000000],
            'expires_at' => now()->addDay(),
        ], 'grant:admin-reconciliation');

        $service = app(ReservationService::class);
        $reservation = $service->reserve($customer, 'claude-coding', 'TOKEN_QUOTA', 70, 'request:admin-reconciliation');
        $service->markForReconciliation($reservation, 'usage_unavailable');

        ApiRequestLog::query()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $customer->id,
            'api_key_id' => null,
            'public_model' => 'claude-coding',
            'endpoint' => '/v1/messages',
            'state' => 'RECONCILING',
            'estimated_units' => 70,
            'error_code' => 'billing_settlement_pending',
            'started_at' => now()->subMinute(),
        ]);

        return [$customer, $lot, $reservation->fresh()];
    }

    private function admin(bool $view, bool $access): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'SUPER_ADMIN'], ['label' => 'Super Administrator']);
        $permissionIds = [];
        if ($view) {
            $permissionIds[] = Permission::query()->firstOrCreate(['name' => 'admin.view'], ['label' => 'View admin analytics'])->id;
        }
        if ($access) {
            $permissionIds[] = Permission::query()->firstOrCreate(['name' => 'access.manage'], ['label' => 'Manage customer access'])->id;
        }
        $role->permissions()->syncWithoutDetaching($permissionIds);
        $admin = User::factory()->create();
        $admin->roles()->attach($role);

        return $admin;
    }
}
