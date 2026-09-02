<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Order;
use App\Models\Package;
use App\Models\Provider;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_claim_referral_before_first_purchase_but_not_self_referral(): void
    {
        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);

        $this->actingAs($customer)->postJson('/api/v1/me/referrals/claim', ['referral_code' => $code])
            ->assertOk()
            ->assertJsonPath('data.claimed', true);

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'referred_by_user_id' => $referrer->id]);

        $selfCode = app(ReferralService::class)->ensureCode($customer);
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/me/referrals/claim', ['referral_code' => app(ReferralService::class)->ensureCode($other)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');
        $this->assertNotSame($selfCode, $code);
    }

    public function test_successful_referred_registration_grants_immediate_idempotent_referrer_reward(): void
    {
        $package = Package::query()->create([
            'slug' => 'referral-registration-alias',
            'name' => 'Referral Registration Alias',
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => 'test',
            'family_label' => 'Test',
            'advertised_units' => 1000,
            'unit_label' => 'tokens',
            'price_minor' => 100,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'duration_seconds' => 86400,
            'limits' => [],
            'billing_rules' => [],
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $this->publishPackage($package);

        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);

        app(ReferralService::class)->claim($customer, $code);
        app(ReferralService::class)->claim($customer->fresh(), $code);

        $this->assertDatabaseCount('referral_registration_rewards', 1);
        $this->assertDatabaseHas('referral_registration_rewards', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $customer->id,
            'reward_mode' => 'CREDIT_BALANCE',
            'reward_units' => 25,
            'status' => 'EARNED',
        ]);
        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $referrer->id,
            'source_type' => 'REFERRAL',
            'billing_mode' => 'CREDIT_BALANCE',
            'original_units' => 25,
            'access_scope' => 'ACCOUNT',
        ]);
    }

    public function test_signup_reward_is_not_blocked_by_temporarily_unpublished_provider_route(): void
    {
        $alias = $this->createCustomerVisibleAliasWithoutReadyRoute();
        $this->assertSame([], ModelAlias::query()->published()->pluck('public_alias')->all());

        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);

        app(ReferralService::class)->claim($customer, $code);

        $this->assertDatabaseHas('referral_registration_rewards', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $customer->id,
            'reward_mode' => 'CREDIT_BALANCE',
            'reward_units' => 25,
            'status' => 'EARNED',
        ]);
        $lot = $referrer->entitlementLots()->where('source_type', 'REFERRAL')->firstOrFail();
        $this->assertSame([$alias->public_alias], $lot->allowed_model_aliases);
    }

    public function test_signup_reward_is_recorded_even_when_no_model_alias_exists_yet(): void
    {
        $this->assertSame([], ModelAlias::query()->pluck('public_alias')->all());

        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);

        app(ReferralService::class)->claim($customer, $code);

        $registrationReward = \App\Models\ReferralRegistrationReward::query()
            ->where('referred_user_id', $customer->id)
            ->firstOrFail();

        $this->assertSame('EARNED', $registrationReward->status);
        $this->assertSame([], $registrationReward->allowed_model_aliases);
        $this->assertTrue((bool) ($registrationReward->metadata['awaiting_model_aliases'] ?? false));

        $lot = $referrer->entitlementLots()->where('source_type', 'REFERRAL')->firstOrFail();
        $this->assertSame(25, (int) $lot->original_units);
        $this->assertSame([], $lot->allowed_model_aliases);
    }

    public function test_reconciliation_repairs_empty_signup_reward_model_scope_when_alias_becomes_available(): void
    {
        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);
        app(ReferralService::class)->claim($customer, $code);

        $alias = $this->createCustomerVisibleAliasWithoutReadyRoute();
        $result = app(ReferralService::class)->reconcileRegistrations(100);

        $this->assertSame(1, $result['repaired']);
        $reward = \App\Models\ReferralRegistrationReward::query()->where('referred_user_id', $customer->id)->firstOrFail();
        $this->assertSame([$alias->public_alias], $reward->allowed_model_aliases);
        $this->assertFalse((bool) ($reward->metadata['awaiting_model_aliases'] ?? true));
        $this->assertSame([$alias->public_alias], $reward->entitlementLot()->firstOrFail()->allowed_model_aliases);
    }

    public function test_historical_referral_can_be_backfilled_explicitly_without_changing_scheduler_boundary(): void
    {
        $this->createCustomerVisibleAliasWithoutReadyRoute();
        $referrer = User::factory()->create();
        $customer = User::factory()->create();

        $customer->forceFill([
            'referred_by_user_id' => $referrer->id,
            'referred_at' => now()->subDays(2),
        ])->saveQuietly();

        $settings = app(ReferralService::class)->settings();
        $settings->forceFill(['registration_reward_started_at' => now()->subDay()])->save();

        $normal = app(ReferralService::class)->reconcileRegistrations(100, false);
        $this->assertSame(0, $normal['checked']);

        $backfill = app(ReferralService::class)->reconcileRegistrations(100, true);
        $this->assertSame(1, $backfill['checked']);
        $this->assertSame(1, $backfill['rewarded']);
        $this->assertDatabaseHas('referral_registration_rewards', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $customer->id,
            'status' => 'EARNED',
        ]);
    }

    public function test_referral_dashboard_earned_credit_includes_signup_credit(): void
    {
        $this->createCustomerVisibleAliasWithoutReadyRoute();
        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);
        app(ReferralService::class)->claim($customer, $code);

        $this->actingAs($referrer)
            ->getJson('/api/v1/me/referrals')
            ->assertOk()
            ->assertJsonPath('data.metrics.rewarded_registrations', 1)
            ->assertJsonPath('data.metrics.earned.0.minor', '25')
            ->assertJsonPath('data.metrics.earned.0.currency', 'USD')
            ->assertJsonPath('data.metrics.earned.0.exponent', 2);
    }

    public function test_fulfilled_referred_order_grants_idempotent_shared_credit_to_both_sides(): void
    {
        $referrer = User::factory()->create();
        $customer = User::factory()->create();
        $code = app(ReferralService::class)->ensureCode($referrer);
        app(ReferralService::class)->claim($customer, $code);

        $package = Package::query()->create([
            'slug' => 'referral-credit-test',
            'name' => 'Referral Test',
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => 'test',
            'family_label' => 'Test',
            'advertised_units' => 1_000_000,
            'unit_label' => 'tokens',
            'price_minor' => 1000,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'duration_seconds' => 86400,
            'limits' => [],
            'billing_rules' => [],
            'enabled' => true,
            'customer_visible' => true,
        ]);
        $this->publishPackage($package);

        $orderId = $this->actingAs($customer)->postJson('/api/v1/orders', [
            'package_slug' => $package->slug,
            'quantity' => 1,
            'idempotency_key' => 'referral-order-test-1',
        ])->assertCreated()->json('data.id');

        $service = app(OrderFulfillmentService::class);
        $service->fulfill(Order::query()->findOrFail($orderId));
        $service->fulfill(Order::query()->findOrFail($orderId));

        $this->assertDatabaseCount('referral_rewards', 1);
        $this->assertDatabaseHas('referral_rewards', [
            'order_id' => $orderId,
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $customer->id,
            'referrer_reward_minor' => 100,
            'referred_bonus_minor' => 50,
            'status' => 'EARNED',
        ]);
        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $referrer->id,
            'source_type' => 'REFERRAL',
            'billing_mode' => 'CREDIT_BALANCE',
            'original_units' => 100,
            'access_scope' => 'ACCOUNT',
        ]);
        $this->assertDatabaseHas('entitlement_lots', [
            'user_id' => $customer->id,
            'source_type' => 'REFERRAL',
            'billing_mode' => 'CREDIT_BALANCE',
            'original_units' => 50,
            'access_scope' => 'ACCOUNT',
        ]);
    }
    private function createCustomerVisibleAliasWithoutReadyRoute(): ModelAlias
    {
        $provider = Provider::query()->create([
            'name' => 'Referral Pending Provider '.uniqid(),
            'slug' => 'referral-pending-'.uniqid(),
            'enabled' => true,
        ]);
        $model = AiModel::query()->create([
            'provider_id' => $provider->id,
            'internal_model_id' => 'pending/model-'.uniqid(),
            'family' => 'referral-test',
            'family_label' => 'Referral Test',
            'commercial_resale_verified_at' => null,
            'enabled' => true,
        ]);

        return ModelAlias::query()->create([
            'ai_model_id' => $model->id,
            'public_alias' => 'referral-pending-'.uniqid(),
            'display_name' => 'Referral Pending Model',
            'capabilities' => [],
            'limits' => [],
            'status' => 'active',
            'enabled' => true,
            'customer_visible' => true,
        ]);
    }

}
