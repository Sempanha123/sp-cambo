<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Exceptions\InsufficientBalanceException;
use App\Models\AiModel;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\FulfillmentClaim;
use App\Models\ModelAlias;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedCustomerAccessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_fulfillment_waits_for_customer_access_choice_instead_of_auto_merging(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $order = $this->order($user, $alias, 'order-choice');

        app(OrderFulfillmentService::class)->fulfill($order);

        $claim = FulfillmentClaim::query()->where('order_id', $order->id)->firstOrFail();
        $lot = EntitlementLot::query()->where('fulfillment_claim_id', $claim->id)->firstOrFail();
        $this->assertSame('PENDING', $claim->status);
        $this->assertSame('UNASSIGNED', $lot->access_scope);
        $this->assertNull($lot->bound_api_key_id);
        $this->assertSame(0, ApiKey::query()->where('user_id', $user->id)->count());
    }

    public function test_customer_can_allocate_purchase_to_playground_without_creating_api_key(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $order = $this->order($user, $alias, 'playground-choice');
        app(OrderFulfillmentService::class)->fulfill($order);
        $claim = FulfillmentClaim::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($user)->postJson("/api/v1/me/api-key-claims/{$claim->id}/claim", [
            'mode' => 'PLAYGROUND',
        ])->assertOk()
            ->assertJsonPath('data.delivery_mode', 'PLAYGROUND')
            ->assertJsonPath('data.key_id', null);

        $lot = EntitlementLot::query()->where('fulfillment_claim_id', $claim->id)->firstOrFail();
        $this->assertSame('PLAYGROUND', $lot->access_scope);
        $this->assertNull($lot->bound_api_key_id);
        $this->assertDatabaseCount('api_keys', 0);

        $this->actingAs($user)->getJson('/api/v1/me/playground/quota')
            ->assertOk()
            ->assertJsonPath('data.fallback_model_aliases.0', $alias->public_alias)
            ->assertJsonPath('data.available_models.0.public_alias', $alias->public_alias);
    }

    public function test_customer_can_create_a_new_dedicated_key_for_one_purchase(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $order = $this->order($user, $alias, 'new-key-choice');
        app(OrderFulfillmentService::class)->fulfill($order);
        $claim = FulfillmentClaim::query()->where('order_id', $order->id)->firstOrFail();

        $response = $this->actingAs($user)->postJson("/api/v1/me/api-key-claims/{$claim->id}/claim", [
            'mode' => 'NEW',
        ])->assertOk()
            ->assertJsonPath('data.delivery_mode', 'NEW');

        $keyId = $response->json('data.key_id');
        $this->assertNotEmpty($response->json('data.api_key'));
        $this->assertDatabaseHas('entitlement_lots', [
            'fulfillment_claim_id' => $claim->id,
            'access_scope' => 'API_KEY',
            'bound_api_key_id' => $keyId,
        ]);

        $this->actingAs($user)->getJson("/api/v1/me/api-keys/{$keyId}")
            ->assertOk()
            ->assertJsonPath('data.balance_source', 'loading')
            ->assertJsonPath('data.funding_status', 'deferred');

        $this->actingAs($user)->getJson("/api/v1/me/api-keys/{$keyId}/funding")
            ->assertOk()
            ->assertJsonPath('data.balance_source', 'dedicated_and_legacy_entitlements')
            ->assertJsonPath('data.funding.0.dedicated_to_this_key', true)
            ->assertJsonPath('data.funding.0.remaining_units', '20000000');
    }

    public function test_customer_can_add_purchase_only_to_one_existing_key(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $existingId = $this->actingAs($user)->postJson('/api/v1/me/api-keys', [
            'label' => 'Friend key',
            'allowed_model_aliases' => [$alias->public_alias],
        ])->assertCreated()->json('data.key.id');

        $order = $this->order($user, $alias, 'existing-key-choice');
        app(OrderFulfillmentService::class)->fulfill($order);
        $claim = FulfillmentClaim::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($user)->postJson("/api/v1/me/api-key-claims/{$claim->id}/claim", [
            'mode' => 'EXISTING',
            'existing_api_key_id' => $existingId,
        ])->assertOk()
            ->assertJsonPath('data.delivery_mode', 'EXISTING')
            ->assertJsonPath('data.key_id', $existingId);

        $this->assertDatabaseHas('entitlement_lots', [
            'fulfillment_claim_id' => $claim->id,
            'access_scope' => 'API_KEY',
            'bound_api_key_id' => $existingId,
        ]);
    }

    public function test_two_dedicated_keys_cannot_spend_each_others_purchased_balance(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();

        $keyIds = [];
        foreach (['isolated-one', 'isolated-two'] as $reference) {
            $order = $this->order($user, $alias, $reference);
            app(OrderFulfillmentService::class)->fulfill($order);
            $claim = FulfillmentClaim::query()->where('order_id', $order->id)->firstOrFail();
            $keyIds[] = $this->actingAs($user)->postJson("/api/v1/me/api-key-claims/{$claim->id}/claim", ['mode' => 'NEW'])
                ->assertOk()->json('data.key_id');
        }

        $firstLots = EntitlementLot::query()->where('bound_api_key_id', $keyIds[0])->sum('remaining_units');
        $secondLots = EntitlementLot::query()->where('bound_api_key_id', $keyIds[1])->sum('remaining_units');
        $this->assertSame(20_000_000, (int) $firstLots);
        $this->assertSame(20_000_000, (int) $secondLots);

        $this->expectException(InsufficientBalanceException::class);
        app(ReservationService::class)->reserve(
            $user,
            $alias->public_alias,
            'TOKEN_QUOTA',
            20_000_001,
            'dedicated-key-isolation',
            $keyIds[0]
        );
    }

    public function test_customer_can_remove_completed_order_from_visible_history_without_deleting_accounting_record(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $order = $this->order($user, $alias, 'history-order');
        app(OrderFulfillmentService::class)->fulfill($order);

        $this->actingAs($user)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->deleteJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.hidden', true);
        $this->actingAs($user)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'FULFILLED']);
        $this->assertNotNull(Order::query()->findOrFail($order->id)->customer_hidden_at);
    }

    public function test_clear_history_hides_completed_orders_but_keeps_an_active_payment_order_visible(): void
    {
        $user = User::factory()->create();
        $alias = $this->alias();
        $completed = $this->order($user, $alias, 'clear-completed');
        app(OrderFulfillmentService::class)->fulfill($completed);

        $active = $this->order($user, $alias, 'clear-active');
        PaymentAttempt::query()->create([
            'order_id' => $active->id,
            'status' => 'PENDING',
            'qr_payload' => 'test-qr-payload',
            'qr_md5' => md5('test-qr-payload'),
            'amount_minor' => 100,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->actingAs($user)->deleteJson('/api/v1/orders/history')
            ->assertOk()->assertJsonPath('data.hidden_count', 1);
        $this->assertNotNull(Order::query()->findOrFail($completed->id)->customer_hidden_at);
        $this->assertNull(Order::query()->findOrFail($active->id)->customer_hidden_at);
    }

    private function order(User $user, ModelAlias $alias, string $reference): Order
    {
        $tenant = $user->requireTenant();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'reference' => $reference,
            'status' => 'PENDING_PAYMENT',
            'currency' => 'USD',
            'currency_exponent' => 2,
            'subtotal_minor' => 100,
            'discount_total_minor' => 0,
            'total_minor' => 100,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'package_slug' => 'claude-purchased',
            'package_name' => 'Claude Purchased',
            'quantity' => 1,
            'unit_price_minor' => 100,
            'line_total_minor' => 100,
            'package_snapshot' => [
                'family_label' => 'Claude',
                'billing_mode' => 'TOKEN_QUOTA',
                'advertised_units' => 20_000_000,
                'unit_label' => 'tokens',
                'currency' => 'USD',
                'currency_exponent' => 2,
                'allowed_model_aliases' => [$alias->public_alias],
                'limits' => [],
                'billing_rules' => [],
                'duration_seconds' => 86400,
                'auto_creates_api_key' => true,
            ],
        ]);

        return $order->fresh('items');
    }

    private function alias(): ModelAlias
    {
        $provider = Provider::query()->create(['name' => 'Unified Provider', 'slug' => 'unified-provider', 'enabled' => true]);
        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => 1,
            'origin' => 'http://provider.test',
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
            'internal_model_id' => 'private/unified-model',
            'family' => 'claude',
            'family_label' => 'Claude',
            'commercial_resale_verified_at' => now(),
            'enabled' => true,
        ]);

        return ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'claude-purchased',
            'display_name' => 'Claude Purchased',
            'capabilities' => ['messages_api' => true, 'streaming' => true, 'tools' => true, 'vision' => false, 'reasoning' => false, 'context_tokens' => 200000, 'max_output_tokens' => 8192],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
    }
}
