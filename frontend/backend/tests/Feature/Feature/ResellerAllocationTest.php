<?php

namespace Tests\Feature\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\ResellerCustomer;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\ResellerAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ResellerAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_is_fefo_paired_audited_and_idempotent(): void
    {
        [$reseller, $customer] = $this->managedPair();
        $entitlements = app(EntitlementService::class);
        $early = $entitlements->grant($reseller, $this->snapshot(60, now()->addHour()), 'reseller:early');
        $late = $entitlements->grant($reseller, $this->snapshot(100, now()->addDay()), 'reseller:late');
        $service = app(ResellerAllocationService::class);
        $first = $service->allocate($reseller, $customer, 'TOKEN_QUOTA', 'claude-coding', 100, 'allocation:one', 'Customer purchased reseller allocation.');
        $second = $service->allocate($reseller, $customer, 'TOKEN_QUOTA', 'claude-coding', 100, 'allocation:one', 'Customer purchased reseller allocation.');

        $this->assertTrue($first->is($second));
        $this->assertSame([$early->id, $late->id], $first->allocations->pluck('source_entitlement_lot_id')->all());
        $this->assertDatabaseHas('entitlement_lots', ['id' => $early->id, 'remaining_units' => 0]);
        $this->assertDatabaseHas('entitlement_lots', ['id' => $late->id, 'remaining_units' => 60]);
        $this->assertSame(100, (int) $customer->entitlementLots()->sum('remaining_units'));
        $this->assertDatabaseCount('reseller_transfers', 1);
        $this->assertDatabaseCount('credit_ledger', 6);
    }

    public function test_reseller_cannot_allocate_to_another_resellers_customer(): void
    {
        [$owner, $customer] = $this->managedPair();
        $attacker = User::factory()->create();
        app(EntitlementService::class)->grant($attacker, $this->snapshot(100, now()->addDay()), 'attacker:inventory');

        $this->expectException(InvalidArgumentException::class);
        app(ResellerAllocationService::class)->allocate($attacker, $customer, 'TOKEN_QUOTA', 'claude-coding', 10, 'allocation:attack', 'Unauthorized cross-tenant transfer attempt.');
    }

    public function test_insufficient_inventory_rolls_back_every_effect(): void
    {
        [$reseller, $customer] = $this->managedPair();
        app(EntitlementService::class)->grant($reseller, $this->snapshot(10, now()->addDay()), 'reseller:small');
        try {
            app(ResellerAllocationService::class)->allocate($reseller, $customer, 'TOKEN_QUOTA', 'claude-coding', 11, 'allocation:too-large', 'Requested allocation exceeds inventory.');
            $this->fail('Expected insufficient inventory.');
        } catch (InsufficientBalanceException) {
            $this->assertDatabaseCount('reseller_transfers', 0);
            $this->assertSame(0, (int) $customer->entitlementLots()->count());
        }
    }

    private function managedPair(): array
    {
        $reseller = User::factory()->create();
        $customer = User::factory()->create();
        ResellerCustomer::query()->create(['reseller_user_id' => $reseller->id, 'customer_user_id' => $customer->id, 'label' => 'Managed customer', 'status' => 'ACTIVE']);

        return [$reseller, $customer];
    }

    private function snapshot(int $units, $expiresAt): array
    {
        return ['source_type' => 'ADMIN_GRANT', 'source_id' => 'inventory', 'package_name' => 'Reseller inventory', 'family_label' => 'Claude', 'billing_mode' => 'TOKEN_QUOTA', 'original_units' => $units, 'unit_label' => 'tokens', 'allowed_model_aliases' => ['claude-coding'], 'billing_snapshot' => ['input_weight_micros' => 1_000_000], 'expires_at' => $expiresAt];
    }
}
