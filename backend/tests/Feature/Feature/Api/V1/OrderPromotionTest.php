<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Events\CustomerStateChanged;
use App\Models\Order;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_promotion_preview_is_200_with_safe_reason_and_exact_totals(): void
    {
        $user = User::factory()->create();
        $this->package();
        $this->actingAs($user)->postJson('/api/v1/promotions/preview', ['package_slug' => 'claude-20m', 'quantity' => 2, 'promotion_code' => 'NOPE'])->assertOk()
            ->assertJsonPath('data.valid', false)->assertJsonPath('data.subtotal.minor', '300')->assertJsonPath('data.discount_total.minor', '0')->assertJsonPath('data.total.minor', '300');
    }

    public function test_order_prices_and_promotion_are_server_calculated_and_immutable_snapshots(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package);
        $response = $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'quantity' => 2, 'promotion_code' => $promotion->code, 'idempotency_key' => 'priced-order-1', 'price_minor' => 1])->assertCreated();
        $id = $response->json('data.id');
        $response->assertJsonPath('data.subtotal.minor', '300')->assertJsonPath('data.discount_total.minor', '30')->assertJsonPath('data.total.minor', '270')->assertJsonPath('data.applied_promotion.label', 'Launch 10%');

        $package->update(['name' => 'Changed', 'price_minor' => 999]);
        $promotion->update(['label' => 'Changed promo']);
        $this->actingAs($user)->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('data.items.0.package_name', 'Claude 20M')->assertJsonPath('data.items.0.unit_price.minor', '150')->assertJsonPath('data.applied_promotion.label', 'Launch 10%');
    }

    public function test_orders_are_tenant_isolated(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $package = $this->package();
        $id = $this->actingAs($owner)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'idempotency_key' => 'owner-order-1'])->assertCreated()->json('data.id');
        $this->actingAs($attacker)->getJson("/api/v1/orders/{$id}")->assertNotFound();
        $this->actingAs($attacker)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_redemption_limit_is_enforced_during_locked_order_creation(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package, ['max_redemptions' => 1]);
        $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'promotion_code' => $promotion->code, 'idempotency_key' => 'limited-promo-1'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/promotions/preview', ['package_slug' => $package->slug, 'promotion_code' => $promotion->code])->assertOk()->assertJsonPath('data.valid', false);
        $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'promotion_code' => $promotion->code, 'idempotency_key' => 'limited-promo-2'])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('promotion_redemptions', 1);
    }

    public function test_identical_order_retry_returns_existing_order_without_duplicate_redemption_or_event(): void
    {
        Event::fake([CustomerStateChanged::class]);
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package);
        $payload = ['package_slug' => $package->slug, 'quantity' => 2, 'promotion_code' => ' launch10 ', 'idempotency_key' => 'order-retry-1'];

        $first = $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('promotion_redemptions', 1);
        Event::assertDispatchedTimes(CustomerStateChanged::class, 1);
    }

    public function test_order_idempotency_key_reuse_with_changed_inputs_is_a_conflict(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package);
        $key = 'order-conflict-1';

        $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'quantity' => 1, 'promotion_code' => $promotion->code, 'idempotency_key' => $key])->assertCreated();

        foreach ([
            ['package_slug' => $package->slug, 'quantity' => 2, 'promotion_code' => $promotion->code, 'idempotency_key' => $key],
            ['package_slug' => $package->slug, 'quantity' => 1, 'promotion_code' => null, 'idempotency_key' => $key],
            ['package_slug' => 'another-package', 'quantity' => 1, 'promotion_code' => $promotion->code, 'idempotency_key' => $key],
        ] as $payload) {
            $this->actingAs($user)->postJson('/api/v1/orders', $payload)
                ->assertConflict()
                ->assertJsonPath('code', 'idempotency_conflict');
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('promotion_redemptions', 1);
    }

    public function test_order_idempotency_keys_are_tenant_bound(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $package = $this->package();
        $payload = ['package_slug' => $package->slug, 'idempotency_key' => 'shared-browser-key'];

        $firstId = $this->actingAs($firstUser)->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');
        $secondId = $this->actingAs($secondUser)->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_order_requires_a_well_formed_idempotency_key(): void
    {
        $user = User::factory()->create();
        $package = $this->package();

        $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('idempotency_key');
        $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'idempotency_key' => 'contains spaces'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_admin_can_create_scoped_scheduled_promotion_with_audit(): void
    {
        $package = $this->package();
        $admin = $this->admin();
        $reason = 'Approved launch promotion for the published package.';
        $this->actingAs($admin)->postJson('/api/v1/admin/promotions', ['code' => ' launch20 ', 'label' => 'Launch 20%', 'type' => 'PERCENTAGE', 'currency' => 'USD', 'currency_exponent' => 2, 'percentage_bps' => 2000, 'fixed_discount_minor' => null, 'bonus_units' => null, 'minimum_order_minor' => 100, 'maximum_discount_minor' => 50, 'max_redemptions' => 10, 'per_user_limit' => 1, 'new_customer_only' => false, 'stackable' => false, 'priority' => 100, 'starts_at' => null, 'ends_at' => now()->addDay()->toAtomString(), 'enabled' => true, 'package_ids' => [$package->id], 'reason' => $reason])
            ->assertCreated()->assertJsonPath('data.code', 'LAUNCH20')->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.currency_exponent', 2)->assertJsonPath('data.package_slugs.0', $package->slug);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'promotion.created', 'reason' => $reason]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/promotions/preview', ['package_slug' => $package->slug, 'promotion_code' => 'launch20'])->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.discount_total.minor', '30');
    }

    public function test_price_override_promotion_round_trips_exact_money_scale(): void
    {
        $package = $this->package();
        $admin = $this->admin();
        $payload = ['code' => ' OVERRIDE ', 'label' => 'Operator override', 'type' => 'PRICE_OVERRIDE', 'currency' => 'USD', 'currency_exponent' => 2, 'percentage_bps' => null, 'fixed_discount_minor' => null, 'price_override_minor' => 99, 'bonus_units' => null, 'minimum_order_minor' => 0, 'maximum_discount_minor' => null, 'max_redemptions' => null, 'per_user_limit' => null, 'new_customer_only' => false, 'stackable' => false, 'priority' => 1, 'starts_at' => null, 'ends_at' => null, 'enabled' => true, 'package_ids' => [$package->id], 'reason' => 'Approved exact price override for campaign.'];

        $created = $this->actingAs($admin)->postJson('/api/v1/admin/promotions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.price_override_minor', '99')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.currency_exponent', 2)
            ->json('data');

        $payload['label'] = 'Updated override';
        $this->actingAs($admin)->putJson("/api/v1/admin/promotions/{$created['id']}", $payload)
            ->assertOk()
            ->assertJsonPath('data.price_override_minor', '99')
            ->assertJsonPath('data.label', 'Updated override');
    }

    public function test_promotion_with_different_currency_scale_is_rejected_safely(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package, ['currency' => 'KHR', 'currency_exponent' => 0]);

        $this->actingAs($user)->postJson('/api/v1/promotions/preview', ['package_slug' => $package->slug, 'promotion_code' => $promotion->code])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.reason', 'This promotion does not apply to the selected currency.');
        $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => $package->slug, 'promotion_code' => $promotion->code, 'idempotency_key' => 'currency-mismatch'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_paid_bonus_promotion_is_granted_from_the_order_snapshot_exactly_once(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package, [
            'type' => 'BONUS',
            'percentage_bps' => null,
            'bonus_units' => 500_000,
        ]);
        $payload = ['package_slug' => $package->slug, 'promotion_code' => $promotion->code, 'idempotency_key' => 'paid-bonus-order'];
        $order = $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');

        $promotion->update(['bonus_units' => 1]);
        app(OrderFulfillmentService::class)->fulfill(Order::query()->findOrFail($order));
        app(OrderFulfillmentService::class)->fulfill(Order::query()->findOrFail($order));

        $this->assertDatabaseCount('entitlement_lots', 2);
        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $user->id,
            'source_type' => 'PROMOTION',
            'source_id' => $order,
            'original_units' => 500_000,
            'remaining_units' => 500_000,
        ]);
        $this->assertDatabaseHas('credit_ledger', [
            'user_id' => $user->id,
            'type' => 'PROMOTION',
            'amount' => 500_000,
            'idempotency_key' => "order:{$order}:promotion:bonus",
        ]);
        $this->assertDatabaseCount('credit_ledger', 2);
    }

    public function test_free_bonus_promotion_is_granted_during_zero_price_fulfillment(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package, [
            'type' => 'FREE',
            'percentage_bps' => null,
            'bonus_units' => 250_000,
        ]);

        $this->actingAs($user)->postJson('/api/v1/orders', [
            'package_slug' => $package->slug,
            'promotion_code' => $promotion->code,
            'idempotency_key' => 'free-bonus-order',
        ])->assertCreated()->assertJsonPath('data.status', 'FULFILLED');

        $this->assertDatabaseCount('entitlement_lots', 2);
        $this->assertDatabaseHas('credit_ledger', ['type' => 'PROMOTION', 'amount' => 250_000]);
    }

    public function test_free_campaign_never_produces_a_negative_total(): void
    {
        $user = User::factory()->create();
        $package = $this->package();
        $promotion = $this->promotion($package, ['type' => 'FREE', 'percentage_bps' => null]);
        $this->actingAs($user)->postJson('/api/v1/promotions/preview', ['package_slug' => $package->slug, 'quantity' => 2, 'promotion_code' => $promotion->code])->assertOk()->assertJsonPath('data.discount_total.minor', '300')->assertJsonPath('data.total.minor', '0');
        $payload = ['package_slug' => $package->slug, 'promotion_code' => $promotion->code, 'idempotency_key' => 'free-order-1'];
        $first = $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('data.status', 'FULFILLED');
        $second = $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertOk()->assertJsonPath('data.status', 'FULFILLED');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('promotion_redemptions', 1);
        $this->assertDatabaseCount('entitlement_lots', 1);
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    private function package(): Package
    {
        $package = Package::query()->create(['slug' => 'claude-20m', 'name' => 'Claude 20M', 'billing_mode' => 'TOKEN_QUOTA', 'family' => 'claude', 'family_label' => 'Claude', 'advertised_units' => 20_000_000, 'unit_label' => 'tokens', 'price_minor' => 150, 'currency' => 'USD', 'currency_exponent' => 2, 'duration_seconds' => 86400, 'limits' => [], 'enabled' => true, 'customer_visible' => true]);
        $this->publishPackage($package);

        return $package;
    }

    private function promotion(Package $package, array $overrides = []): Promotion
    {
        $promotion = Promotion::query()->create($overrides + ['code' => 'LAUNCH10', 'label' => 'Launch 10%', 'type' => 'PERCENTAGE', 'currency' => 'USD', 'currency_exponent' => 2, 'percentage_bps' => 1000, 'minimum_order_minor' => 0, 'enabled' => true]);
        $promotion->packages()->attach($package);

        return $promotion;
    }

    private function admin(): User
    {
        $permission = Permission::query()->create(['name' => 'catalog.manage', 'label' => 'Manage catalog']);
        $role = Role::query()->create(['name' => 'ADMIN', 'label' => 'Administrator']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
