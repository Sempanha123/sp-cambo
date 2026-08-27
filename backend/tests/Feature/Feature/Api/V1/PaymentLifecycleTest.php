<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Models\Package;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeKhqrGenerator $generator;

    private FakeBakongVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bakong.account_id' => 'merchant@bank', 'services.bakong.merchant_name' => 'SP Cambo Test', 'services.bakong.merchant_city' => 'Phnom Penh', 'services.bakong.attempt_ttl_seconds' => 300, 'services.bakong.reconcile_interval_seconds' => 60, 'services.bakong.reconcile_expired_grace_seconds' => 900]);
        $this->generator = new FakeKhqrGenerator;
        $this->verifier = new FakeBakongVerifier;
        $this->app->instance(KhqrGenerator::class, $this->generator);
        $this->app->instance(BakongVerifier::class, $this->verifier);
    }

    public function test_read_before_create_returns_not_found_and_live_attempt_is_reused(): void
    {
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->getJson("/api/v1/orders/{$orderId}/payment")->assertNotFound()->assertJsonPath('code', 'not_found');
        $first = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->assertJsonPath('data.server_time', fn ($value) => is_string($value))->json('data.id');
        $second = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->generator->calls);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_verified_payment_fulfills_once_and_duplicate_verification_is_idempotent(): void
    {
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk();
        $this->verifier->result = ['found' => true, 'transaction_hash' => str_repeat('a', 64), 'to_account_id' => 'merchant@bank', 'currency' => 'USD', 'amount_decimal' => '1.50'];
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/verify")->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/verify")->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'FULFILLED']);
        $this->assertDatabaseCount('entitlement_lots', 1);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_wrong_amount_never_fulfills(): void
    {
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk();
        $this->verifier->result = ['found' => true, 'transaction_hash' => str_repeat('b', 64), 'to_account_id' => 'merchant@bank', 'currency' => 'USD', 'amount_decimal' => '1.49'];
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/verify")->assertStatus(422)->assertJsonPath('code', 'payment_verification_failed');
        $this->assertDatabaseCount('entitlement_lots', 0);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'fulfilled_at' => null]);
    }

    public function test_expired_attempt_is_replaced_only_on_explicit_create(): void
    {
        [$user, $orderId] = $this->order();
        $old = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        PaymentAttempt::query()->findOrFail($old)->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($user)->getJson("/api/v1/orders/{$orderId}/payment")->assertOk()->assertJsonPath('data.status', 'EXPIRED');
        $new = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        $this->assertNotSame($old, $new);
        $this->assertDatabaseHas('payment_attempts', ['id' => $old, 'status' => 'EXPIRED']);
        $this->assertSame(2, $this->generator->calls);
    }

    public function test_same_bakong_transaction_cannot_fulfill_two_orders(): void
    {
        [$firstUser, $firstOrder] = $this->order();
        [$secondUser, $secondOrder] = $this->order();
        $this->actingAs($firstUser)->postJson("/api/v1/orders/{$firstOrder}/payment")->assertOk();
        $this->actingAs($secondUser)->postJson("/api/v1/orders/{$secondOrder}/payment")->assertOk();
        $this->verifier->result = ['found' => true, 'transaction_hash' => str_repeat('c', 64), 'to_account_id' => 'merchant@bank', 'currency' => 'USD', 'amount_decimal' => '1.50'];
        $this->actingAs($firstUser)->postJson("/api/v1/orders/{$firstOrder}/payment/verify")->assertOk();
        $this->actingAs($secondUser)->postJson("/api/v1/orders/{$secondOrder}/payment/verify")->assertStatus(409)->assertJsonPath('code', 'payment_replayed');
        $this->assertDatabaseCount('entitlement_lots', 1);
        $this->assertDatabaseHas('orders', ['id' => $secondOrder, 'fulfilled_at' => null]);
    }

    public function test_bakong_operational_failure_restores_pending_state_and_returns_safe_code(): void
    {
        [$user, $orderId] = $this->order();
        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        $this->verifier->exception = new \RuntimeException('Bakong verification rate limit was reached (HTTP 429).');

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$orderId}/payment/verify")
            ->assertStatus(503)
            ->assertJsonPath('code', 'payment_verification_unavailable')
            ->assertJsonPath('message', 'SP Cambo could not verify this transfer with Bakong right now. Your order remains pending; do not pay again. Please retry shortly.');

        $this->assertDatabaseHas('payment_attempts', ['id' => $attemptId, 'status' => 'PENDING']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'PENDING_PAYMENT']);
    }

    public function test_customer_checkout_auto_check_settles_paid_order_without_manual_button(): void
    {
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk();
        $this->verifier->result = [
            'found' => true,
            'transaction_hash' => str_repeat('a', 64),
            'to_account_id' => 'merchant@bank',
            'currency' => 'USD',
            'amount_decimal' => '1.50',
        ];

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$orderId}/payment/auto-check")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        $this->assertSame(1, $this->verifier->calls);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'FULFILLED']);
        $this->assertDatabaseCount('entitlement_lots', 1);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$orderId}/payment/auto-check")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');
        $this->assertSame(1, $this->verifier->calls);
    }

    public function test_customer_auto_check_is_server_rate_limited_per_payment_attempt(): void
    {
        config(['services.bakong.customer_auto_check_interval_seconds' => 15]);
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk();

        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/auto-check")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/auto-check")->assertOk();
        $this->assertSame(1, $this->verifier->calls);

        $this->travel(16)->seconds();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/auto-check")->assertOk();
        $this->assertSame(2, $this->verifier->calls);
    }

    public function test_scheduled_reconciliation_checks_each_eligible_attempt_at_most_once_per_run(): void
    {
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk();
        $this->artisan('payments:reconcile-pending')->assertSuccessful();
        $this->assertSame(1, $this->verifier->calls);
        $this->artisan('payments:reconcile-pending')->assertSuccessful();
        $this->assertSame(1, $this->verifier->calls);
    }


    public function test_scheduled_reconciliation_rechecks_live_attempt_after_configured_interval(): void
    {
        [$user, $orderId] = $this->order();
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk();

        $this->artisan('payments:reconcile-pending')->assertSuccessful();
        $this->assertSame(1, $this->verifier->calls);

        $this->travel(59)->seconds();
        $this->artisan('payments:reconcile-pending')->assertSuccessful();
        $this->assertSame(1, $this->verifier->calls);

        $this->travel(2)->seconds();
        $this->artisan('payments:reconcile-pending')->assertSuccessful();
        $this->assertSame(2, $this->verifier->calls);
    }

    public function test_manual_operator_verification_can_recover_expired_attempt_outside_automatic_grace_window(): void
    {
        [$user, $orderId] = $this->order();
        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');

        PaymentAttempt::query()->findOrFail($attemptId)->forceFill([
            'status' => 'EXPIRED',
            'expires_at' => now()->subHours(2),
            'last_checked_at' => now()->subHours(2),
        ])->save();

        $this->verifier->result = [
            'found' => true,
            'transaction_hash' => str_repeat('e', 64),
            'to_account_id' => 'merchant@bank',
            'currency' => 'USD',
            'amount_decimal' => '1.50',
        ];

        $this->artisan('payments:reconcile-pending')
            ->expectsOutputToContain('Checked 0 payment(s)')
            ->assertSuccessful();
        $this->assertSame(0, $this->verifier->calls);

        $this->artisan('payments:verify-attempt', [
            'attempt' => $attemptId,
            '--reason' => 'Recover customer-reported paid QR after outage',
        ])
            ->expectsOutputToContain('Payment verified successfully')
            ->assertSuccessful();

        $this->assertSame(1, $this->verifier->calls);
        $this->assertDatabaseHas('payment_attempts', ['id' => $attemptId, 'status' => 'PAID']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'FULFILLED']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.manual_verification_requested',
            'subject_type' => 'payment_attempt',
            'subject_id' => $attemptId,
        ]);
    }

    public function test_reconcile_command_rejects_oversized_batch_without_stack_trace_path(): void
    {
        $this->artisan('payments:reconcile-pending', ['--batch' => 20])
            ->expectsOutputToContain('Payment reconciliation batch must be between 1 and 4')
            ->assertExitCode(2);

        $this->assertSame(0, $this->verifier->calls);
    }

    public function test_scheduled_reconciliation_recovers_recently_expired_paid_attempt(): void
    {
        [$user, $orderId] = $this->order();
        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        PaymentAttempt::query()->findOrFail($attemptId)->update([
            'status' => 'EXPIRED',
            'expires_at' => now()->subSecond(),
            'last_checked_at' => now()->subMinutes(2),
        ]);
        $this->verifier->result = [
            'found' => true,
            'transaction_hash' => str_repeat('d', 64),
            'to_account_id' => 'merchant@bank',
            'currency' => 'USD',
            'amount_decimal' => '1.50',
        ];

        $this->artisan('payments:reconcile-pending')->assertSuccessful();

        $this->assertSame(1, $this->verifier->calls);
        $this->assertDatabaseHas('payment_attempts', ['id' => $attemptId, 'status' => 'PAID']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'FULFILLED']);
    }

    public function test_read_does_not_expire_an_attempt_while_verification_owns_a_live_lease(): void
    {
        [$user, $orderId] = $this->order();
        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        PaymentAttempt::query()->findOrFail($attemptId)->forceFill([
            'status' => 'VERIFYING',
            'verification_lease_token' => hash('sha256', 'read-race-lease'),
            'verification_lease_expires_at' => now()->addMinute(),
            'expires_at' => now()->subSecond(),
        ])->save();

        $this->actingAs($user)->getJson("/api/v1/orders/{$orderId}/payment")
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING');

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'status' => 'VERIFYING',
        ]);
    }

    public function test_active_verification_lease_prevents_duplicate_bakong_lookup_and_second_qr(): void
    {
        [$user, $orderId] = $this->order();
        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        PaymentAttempt::query()->findOrFail($attemptId)->forceFill([
            'status' => 'VERIFYING',
            'verification_lease_token' => hash('sha256', 'active-test-lease'),
            'verification_lease_expires_at' => now()->addMinute(),
            'expires_at' => now()->subSecond(),
        ])->save();

        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING');
        $same = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")
            ->assertOk()
            ->json('data.id');

        $this->assertSame($attemptId, $same);
        $this->assertSame(0, $this->verifier->calls);
        $this->assertSame(1, $this->generator->calls);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_scheduler_recovers_stale_verifying_attempt_and_fulfills_once(): void
    {
        [$user, $orderId] = $this->order();
        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        PaymentAttempt::query()->findOrFail($attemptId)->forceFill([
            'status' => 'VERIFYING',
            'verification_lease_token' => hash('sha256', 'stale-test-lease'),
            'verification_lease_expires_at' => now()->subSecond(),
            'last_checked_at' => now()->subMinutes(2),
        ])->save();
        $this->verifier->result = [
            'found' => true,
            'transaction_hash' => str_repeat('9', 64),
            'to_account_id' => 'merchant@bank',
            'currency' => 'USD',
            'amount_decimal' => '1.50',
        ];

        $this->artisan('payments:reconcile-pending')->assertSuccessful();
        $this->artisan('payments:reconcile-pending')->assertSuccessful();

        $this->assertSame(1, $this->verifier->calls);
        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'status' => 'PAID',
            'verification_lease_token' => null,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'FULFILLED']);
        $this->assertDatabaseCount('entitlement_lots', 1);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    private function order(): array
    {
        $user = User::factory()->create();
        $package = Package::query()->firstOrCreate(['slug' => 'paid-package'], ['name' => 'Paid Package', 'billing_mode' => 'TOKEN_QUOTA', 'family' => 'claude', 'family_label' => 'Claude', 'advertised_units' => 1_000_000, 'unit_label' => 'tokens', 'price_minor' => 150, 'currency' => 'USD', 'currency_exponent' => 2, 'duration_seconds' => 86400, 'limits' => [], 'enabled' => true, 'customer_visible' => true]);
        $this->publishPackage($package);
        $orderId = $this->actingAs($user)->postJson('/api/v1/orders', ['package_slug' => 'paid-package', 'idempotency_key' => 'payment-order-'.strtolower((string) Str::ulid())])->assertCreated()->json('data.id');

        return [$user, $orderId];
    }
}

class FakeKhqrGenerator implements KhqrGenerator
{
    public int $calls = 0;

    public function generate(string $accountId, string $merchantName, string $merchantCity, string $currency, string $amountDecimal, string $reference): array
    {
        $this->calls++;

        return ['qr_payload' => "test-qr-{$this->calls}", 'md5' => md5("test-qr-{$this->calls}")];
    }
}

class FakeBakongVerifier implements BakongVerifier
{
    public int $calls = 0;

    public ?\Throwable $exception = null;

    public array $result = ['found' => false, 'transaction_hash' => null, 'to_account_id' => null, 'currency' => null, 'amount_decimal' => null];

    public function checkByMd5(string $md5): array
    {
        $this->calls++;

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }
}
