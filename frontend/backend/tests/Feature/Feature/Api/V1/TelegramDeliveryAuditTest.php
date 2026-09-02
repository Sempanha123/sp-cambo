<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Contracts\BakongVerifier;
use App\Contracts\KhqrGenerator;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\FulfillmentClaim;
use App\Models\Package;
use App\Models\TelegramPurchase;
use App\Models\User;
use App\Services\TelegramBotClient;
use App\Services\TelegramCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TelegramDeliveryAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'services.bakong.account_id' => 'merchant@bank',
            'services.bakong.merchant_name' => 'SP Cambo Test',
            'services.bakong.merchant_city' => 'Phnom Penh',
            'services.bakong.attempt_ttl_seconds' => 300,
            'services.bakong.reconcile_interval_seconds' => 60,
            'services.telegram.link_secret' => 'telegram-link-test-secret',
            'services.telegram.bot_token' => 'telegram-test-token',
        ]);

        $this->app->instance(KhqrGenerator::class, new AuditKhqrGenerator);
        $this->app->instance(BakongVerifier::class, new AuditBakongVerifier);
    }

    public function test_ambiguous_telegram_delivery_preserves_secret_and_retries_same_key_exactly_once(): void
    {
        $this->app->instance(TelegramBotClient::class, new AuditTelegramBotClient);
        [, $purchase] = $this->paidTelegramPurchase();

        // Simulate a timeout/API failure after Telegram has accepted the plaintext payload.
        $failureBot = new AuditTelegramBotClient(failOnCall: 1);
        $this->app->instance(TelegramBotClient::class, $failureBot);

        try {
            app(TelegramCommerceService::class)->reconcile($purchase);
            $this->fail('Expected Telegram message to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit telegram failure', $exception->getMessage());
        }

        $sentSecret = $failureBot->secrets[0] ?? null;
        $this->assertIsString($sentSecret, 'Secret should have entered the outbound payload before failure.');

        $failedPurchase = $purchase->fresh();
        $claim = FulfillmentClaim::query()->where('order_id', $purchase->order_id)->firstOrFail();
        $key = ApiKey::query()->findOrFail($claim->api_key_id);

        $this->assertSame('CLAIMED', $claim->status);
        $this->assertSame('NEW', $claim->delivery_mode);
        $this->assertSame('ACTIVE', $key->status);
        $this->assertTrue(EntitlementLot::query()
            ->where('fulfillment_claim_id', $claim->id)
            ->where('access_scope', 'API_KEY')
            ->where('bound_api_key_id', $key->id)
            ->exists(), 'Telegram purchase balance must be dedicated to the automatically created key.');
        $this->assertNull($key->revoked_at);
        $this->assertSame($claim->id, $failedPurchase->fulfillment_claim_id);
        $this->assertSame($key->id, $failedPurchase->api_key_id);
        $this->assertSame($sentSecret, $failedPurchase->delivery_secret_ciphertext);
        $this->assertNull($failedPurchase->delivered_at);
        $this->assertSame(1, ApiKey::query()->where('user_id', $failedPurchase->user_id)->count());

        $retryBot = new AuditTelegramBotClient;
        $this->app->instance(TelegramBotClient::class, $retryBot);

        app(TelegramCommerceService::class)->reconcile($purchase->fresh());

        $retriedSecret = $retryBot->secrets[0] ?? null;
        $this->assertIsString($retriedSecret);
        $this->assertSame($sentSecret, $retriedSecret, 'Retry must use the original plaintext secret.');

        $deliveredPurchase = $purchase->fresh();
        $this->assertSame('DELIVERED', $deliveredPurchase->status);
        $this->assertNotNull($deliveredPurchase->delivered_at);
        $this->assertNull($deliveredPurchase->delivery_secret_ciphertext);
        $this->assertSame(1, ApiKey::query()->where('user_id', $deliveredPurchase->user_id)->count());
        $this->assertSame($key->id, $deliveredPurchase->api_key_id);
        $this->assertSame($sentSecret, $key->fresh()->secret_ciphertext, 'Telegram transport escrow may clear, but the customer-owned key keeps its encrypted recovery copy.');

        app(TelegramCommerceService::class)->reconcile($deliveredPurchase);
        $this->assertCount(1, $retryBot->messages, 'Delivered purchases must not send or mint again.');
    }

    public function test_reconcile_pending_sanitizes_delivery_errors_without_secret_leakage(): void
    {
        $this->app->instance(TelegramBotClient::class, new AuditTelegramBotClient);
        [, $purchase] = $this->paidTelegramPurchase();
        $failureBot = new AuditTelegramBotClient(failOnCall: 1, failureMessage: 'Telegram API timeout');
        $this->app->instance(TelegramBotClient::class, $failureBot);

        $result = app(TelegramCommerceService::class)->reconcilePending();

        $this->assertSame(['checked' => 1, 'failed' => 1], $result);
        $failedPurchase = $purchase->fresh();
        $this->assertSame('DELIVERY_FAILED', $failedPurchase->status);
        $this->assertSame('Telegram API timeout', $failedPurchase->last_error);
        $this->assertNotNull($failedPurchase->delivery_secret_ciphertext);
        $this->assertStringNotContainsString($failedPurchase->delivery_secret_ciphertext, $failedPurchase->last_error);
        $this->assertSame(1, ApiKey::query()->where('user_id', $failedPurchase->user_id)->count());
    }

    public function test_reconcile_pending_redacts_a_secret_in_delivery_error(): void
    {
        $this->app->instance(TelegramBotClient::class, new AuditTelegramBotClient);
        [, $purchase] = $this->paidTelegramPurchase();
        $failureBot = new AuditTelegramBotClient(failOnCall: 1, failureMessageFromSecret: true);
        $this->app->instance(TelegramBotClient::class, $failureBot);

        app(TelegramCommerceService::class)->reconcilePending();

        $failedPurchase = $purchase->fresh();
        $this->assertSame('Delivery failed for [redacted]', $failedPurchase->last_error);
        $this->assertNotNull($failedPurchase->delivery_secret_ciphertext);
        $this->assertStringNotContainsString($failedPurchase->delivery_secret_ciphertext, $failedPurchase->last_error);
    }

    public function test_telegram_http_error_preserves_escrow_for_a_later_retry(): void
    {
        $this->app->instance(TelegramBotClient::class, new AuditTelegramBotClient);
        [, $purchase] = $this->paidTelegramPurchase();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 502)]);
        $this->app->instance(TelegramBotClient::class, new TelegramBotClient);

        try {
            app(TelegramCommerceService::class)->reconcile($purchase);
            $this->fail('Expected Telegram HTTP failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram sendMessage was rejected (HTTP 502)', $exception->getMessage());
        }

        $failedPurchase = $purchase->fresh();
        $claim = FulfillmentClaim::query()->where('order_id', $purchase->order_id)->firstOrFail();
        $this->assertSame('CLAIMED', $claim->status);
        $this->assertSame('ACTIVE', ApiKey::query()->findOrFail($claim->api_key_id)->status);
        $this->assertNotNull($failedPurchase->delivery_secret_ciphertext);
        $this->assertNull($failedPurchase->delivered_at);
    }

    public function test_telegram_timeout_preserves_escrow_for_a_later_retry(): void
    {
        $this->app->instance(TelegramBotClient::class, new AuditTelegramBotClient);
        [, $purchase] = $this->paidTelegramPurchase();
        Http::fake(['https://api.telegram.org/*' => Http::failedConnection('cURL timeout')]);
        $this->app->instance(TelegramBotClient::class, new TelegramBotClient);

        try {
            app(TelegramCommerceService::class)->reconcile($purchase);
            $this->fail('Expected Telegram timeout.');
        } catch (ConnectionException $exception) {
            $this->assertSame('cURL timeout', $exception->getMessage());
        }

        $failedPurchase = $purchase->fresh();
        $claim = FulfillmentClaim::query()->where('order_id', $purchase->order_id)->firstOrFail();
        $this->assertSame('CLAIMED', $claim->status);
        $this->assertSame('ACTIVE', ApiKey::query()->findOrFail($claim->api_key_id)->status);
        $this->assertNotNull($failedPurchase->delivery_secret_ciphertext);
        $this->assertNull($failedPurchase->delivered_at);
    }

    public function test_website_customer_choice_is_idempotent_and_retry_does_not_return_secret(): void
    {
        [$user, $claimId] = $this->fulfilledClaim();
        $claim = FulfillmentClaim::query()->findOrFail($claimId);
        $this->assertSame('PENDING', $claim->status);
        $this->assertNull($claim->api_key_id);

        $first = $this->actingAs($user)->postJson("/api/v1/me/api-key-claims/{$claimId}/claim", [
            'mode' => 'NEW',
        ])->assertOk()->assertJsonPath('data.delivery_mode', 'NEW');
        $this->assertNotEmpty($first->json('data.api_key'));

        $this->actingAs($user)->postJson("/api/v1/me/api-key-claims/{$claimId}/claim", [
            'mode' => 'NEW',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'already_claimed')
            ->assertJsonMissingPath('api_key');
    }

    private function paidTelegramPurchase(): array
    {
        $telegram = app(TelegramCommerceService::class);
        $account = $telegram->ensureStorefrontAccount('audit-telegram-user', 'audit-chat', 'audit_user', 'Audit User');
        $package = $this->package();
        $purchase = $telegram->beginPurchase($account, $package->slug, 'audit-update');
        $attempt = $purchase->order->paymentAttempts()->firstOrFail();
        app(BakongVerifier::class)->result = [
            'found' => true,
            'transaction_hash' => str_repeat('f', 64),
            'to_account_id' => 'merchant@bank',
            'currency' => 'USD',
            'amount_decimal' => '1.50',
        ];
        app(\App\Services\PaymentService::class)->verify($attempt);

        return [$telegram, $purchase->fresh()];
    }

    private function fulfilledClaim(): array
    {
        $user = User::factory()->create();
        $package = $this->package();
        $orderId = $this->actingAs($user)->postJson('/api/v1/orders', [
            'package_slug' => $package->slug,
            'idempotency_key' => 'audit-claim-'.strtolower((string) Str::ulid()),
        ])->assertCreated()->json('data.id');

        $attemptId = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment")->assertOk()->json('data.id');
        app(BakongVerifier::class)->result = [
            'found' => true,
            'transaction_hash' => str_repeat('e', 64),
            'to_account_id' => 'merchant@bank',
            'currency' => 'USD',
            'amount_decimal' => '1.50',
        ];
        $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/payment/verify")->assertOk();

        return [$user, FulfillmentClaim::query()->where('order_id', $orderId)->firstOrFail()->id];
    }

    private function package(): Package
    {
        $package = Package::query()->firstOrCreate(
            ['slug' => 'audit-telegram-package'],
            [
                'name' => 'Audit Telegram Package',
                'billing_mode' => 'TOKEN_QUOTA',
                'family' => 'claude',
                'family_label' => 'Claude',
                'advertised_units' => 1000000,
                'unit_label' => 'tokens',
                'price_minor' => 150,
                'currency' => 'USD',
                'currency_exponent' => 2,
                'duration_seconds' => 86400,
                'limits' => [],
                'auto_creates_api_key' => true,
                'enabled' => true,
                'customer_visible' => true,
            ]
        );
        $this->publishPackage($package);

        return $package->fresh();
    }
}

class AuditTelegramBotClient extends TelegramBotClient
{
    public array $messages = [];
    public array $secrets = [];

    public function __construct(
        private readonly ?int $failOnCall = null,
        private readonly string $failureMessage = 'audit telegram failure',
        private readonly bool $failureMessageFromSecret = false,
    ) {}

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $this->messages[] = $text;
        if (preg_match('/sk-[a-z0-9]+/', $text, $matches)) {
            $this->secrets[] = $matches[0];
        }

        if ($this->failOnCall !== null && count($this->messages) === $this->failOnCall) {
            $message = $this->failureMessageFromSecret
                ? 'Delivery failed for '.($this->secrets[0] ?? '')
                : $this->failureMessage;

            throw new RuntimeException($message);
        }

        return [
            'message_id' => count($this->messages),
            'chat' => [
                'id' => $chatId,
            ],
        ];
    }
}

class AuditKhqrGenerator implements KhqrGenerator
{
    public function generate(string $accountId, string $merchantName, string $merchantCity, string $currency, string $amountDecimal, string $reference): array
    {
        return ['qr_payload' => 'audit-qr', 'md5' => md5('audit-qr')];
    }
}

class AuditBakongVerifier implements BakongVerifier
{
    public int $calls = 0;
    public array $result = ['found' => false, 'transaction_hash' => null, 'to_account_id' => null, 'currency' => null, 'amount_decimal' => null];

    public function checkByMd5(string $md5): array
    {
        $this->calls++;
        return $this->result;
    }
}
