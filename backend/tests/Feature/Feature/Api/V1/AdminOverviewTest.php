<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\UsageRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_requires_permission_and_returns_exact_revenue(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/api/v1/admin/overview')->assertForbidden();
        Order::query()->create(['user_id' => $customer->id, 'reference' => 'SPC-ADMIN-TEST', 'status' => 'FULFILLED', 'currency' => 'USD', 'currency_exponent' => 2, 'subtotal_minor' => 150, 'discount_total_minor' => 0, 'total_minor' => 150, 'fulfilled_at' => now()]);

        $this->actingAs($this->admin())->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('data.fulfilled_revenue.minor', '150')->assertJsonPath('data.fulfilled_revenue.currency', 'USD')->assertJsonPath('data.fulfilled_revenue.exponent', 2)->assertJsonPath('data.fulfilled_revenue.by_currency.0.minor', '150')->assertJsonPath('data.orders.by_status.FULFILLED', 1);
    }

    public function test_admin_overview_exposes_private_finance_only_to_admins(): void
    {
        $customer = User::factory()->create();
        Order::query()->create([
            'user_id' => $customer->id,
            'reference' => 'SPC-PRIVATE-FINANCE',
            'status' => 'FULFILLED',
            'currency' => 'USD',
            'currency_exponent' => 2,
            'subtotal_minor' => 1000,
            'discount_total_minor' => 0,
            'total_minor' => 1000,
            'fulfilled_at' => now(),
        ]);
        $reservation = Reservation::query()->create([
            'user_id' => $customer->id,
            'public_model_alias' => 'openai-codex',
            'billing_mode' => 'CREDIT_BALANCE',
            'reserved_units' => 50,
            'settled_units' => 50,
            'status' => 'SETTLED',
            'idempotency_key' => 'admin-private-finance-test',
            'expires_at' => now()->addMinute(),
            'settled_at' => now(),
        ]);
        UsageRecord::query()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $customer->id,
            'public_model' => 'openai-codex',
            'endpoint' => '/v1/messages',
            'input_tokens' => 1000,
            'output_tokens' => 100,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 1100,
            'metered_units' => 50,
            'credit_charge_minor' => 50,
            'upstream_cost_minor' => 30,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'settled_at' => now(),
        ]);

        $this->actingAs($customer)
            ->getJson('/api/v1/admin/overview')
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.private_finance.visibility', 'ADMIN_ONLY')
            ->assertJsonPath('data.private_finance.by_currency.0.upstream_cost_minor', '30')
            ->assertJsonPath('data.private_finance.by_currency.0.gross_position_minor', '970')
            ->assertJsonPath('data.private_finance.by_currency.0.known_credit_profit_minor', '20')
            ->assertJsonPath('data.private_finance.by_currency.0.known_credit_margin_bps', 4000);
    }

    public function test_admin_overview_does_not_sum_unlike_currencies_or_exponents(): void
    {
        $customer = User::factory()->create();
        foreach ([['USD', 2, 150], ['KHR', 0, 40_000]] as [$currency, $exponent, $minor]) {
            Order::query()->create(['user_id' => $customer->id, 'reference' => "SPC-{$currency}-TEST", 'status' => 'FULFILLED', 'currency' => $currency, 'currency_exponent' => $exponent, 'subtotal_minor' => $minor, 'discount_total_minor' => 0, 'total_minor' => $minor, 'fulfilled_at' => now()]);
        }

        $this->actingAs($this->admin())->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.fulfilled_revenue.mixed_currency', true)
            ->assertJsonPath('data.fulfilled_revenue.minor', '0')
            ->assertJsonPath('data.fulfilled_revenue.currency', null)
            ->assertJsonPath('data.fulfilled_revenue.exponent', null)
            ->assertJsonCount(2, 'data.fulfilled_revenue.by_currency');
    }

    public function test_system_health_uses_measured_scheduler_heartbeat_and_safe_details(): void
    {
        config(['services.bakong.token' => 'must-never-appear', 'services.spcambo.gateway_secret' => 'must-never-appear']);
        Http::fake(fn () => Http::response(['ok' => true, 'data' => ['status' => 'ok']], 200));
        $this->artisan('system:heartbeat')->assertSuccessful();
        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/system-health')->assertOk();
        $this->assertStringNotContainsString('must-never-appear', $response->getContent());
        $response->assertJsonFragment(['key' => 'scheduler', 'status' => 'operational', 'detail' => null]);
        $response->assertJsonFragment(['key' => 'gateway', 'status' => 'operational', 'detail' => null]);
    }

    private function admin(): User
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'admin.view'], ['label' => 'View admin analytics']);
        $role = Role::query()->firstOrCreate(['name' => 'ADMIN'], ['label' => 'Administrator']);
        $role->permissions()->syncWithoutDetaching($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
