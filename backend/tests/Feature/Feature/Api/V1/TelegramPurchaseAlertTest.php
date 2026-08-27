<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\TelegramAccount;
use App\Models\TelegramAnnouncement;
use App\Models\TelegramPurchase;
use App\Models\TelegramPurchaseAlert;
use App\Models\User;
use App\Services\TelegramAnnouncementService;
use App\Services\TelegramBotClient;
use App\Services\TelegramCommerceService;
use App\Services\TelegramPurchaseAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramPurchaseAlertTest extends TestCase
{
    use RefreshDatabase;

    private AlertCaptureBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_username' => 'SPCamboStoreBot',
            'services.telegram.purchase_activity_enabled' => true,
            'services.telegram.purchase_activity_min_delay_seconds' => 0,
            'services.telegram.purchase_activity_max_per_hour' => 30,
            'services.telegram.announcement_lease_seconds' => 120,
            'services.telegram.announcement_max_attempts' => 8,
            'services.telegram.public_purchase_template_en' => '',
            'services.telegram.public_purchase_template_km' => '',
        ]);

        $this->bot = new AlertCaptureBot;
        $this->app->instance(TelegramBotClient::class, $this->bot);
    }

    public function test_website_orders_are_completely_telegram_silent(): void
    {
        [, $order] = $this->order('website-order');
        $order = $this->paidAndFulfilled($order);
        $alerts = app(TelegramPurchaseAlertService::class);

        $alerts->orderCreated($order);
        $alerts->orderFulfilled($order);

        $this->assertSame(0, TelegramPurchaseAlert::query()->count());
        $this->assertSame(0, TelegramAnnouncement::query()->count());
    }

    public function test_delivered_telegram_purchase_queues_exactly_one_verified_new_order_activity(): void
    {
        [$buyer, $order] = $this->order('telegram:buyer:package');
        $buyerAccount = $this->telegramAccount($buyer, 'buyer-chat', true);
        $order = $this->paidAndFulfilled($order);
        $purchase = TelegramPurchase::query()->create([
            'tenant_id' => $buyer->tenant_id,
            'user_id' => $buyer->id,
            'telegram_account_id' => $buyerAccount->id,
            'order_id' => $order->id,
            'status' => 'DELIVERED',
            'delivered_at' => now(),
            'last_checked_at' => now(),
        ]);

        $alerts = app(TelegramPurchaseAlertService::class);
        $alerts->telegramPurchaseDelivered($purchase);
        $alerts->telegramPurchaseDelivered($purchase->fresh());

        $this->assertSame(1, TelegramAnnouncement::query()->where('kind', 'PURCHASE_ACTIVITY')->count());
        $this->assertSame(0, TelegramPurchaseAlert::query()->count());
    }

    public function test_new_order_activity_goes_to_opted_in_subscribers_not_the_buyer_and_has_buy_now(): void
    {
        [$buyer, $order] = $this->order('telegram:buyer:activity', 'Panha Sok');
        $buyerAccount = $this->telegramAccount($buyer, 'buyer-chat', true);
        $subscriber = User::factory()->create();
        $this->telegramAccount($subscriber, 'subscriber-chat', true);
        $order = $this->paidAndFulfilled($order);
        $purchase = TelegramPurchase::query()->create([
            'tenant_id' => $buyer->tenant_id,
            'user_id' => $buyer->id,
            'telegram_account_id' => $buyerAccount->id,
            'order_id' => $order->id,
            'status' => 'DELIVERED',
            'delivered_at' => now(),
            'last_checked_at' => now(),
        ]);

        app(TelegramPurchaseAlertService::class)->telegramPurchaseDelivered($purchase);
        app(TelegramAnnouncementService::class)->dispatchPending();

        $this->assertFalse(collect($this->bot->messages)->contains(fn (array $message): bool => $message['chat_id'] === 'buyer-chat'));
        $message = collect($this->bot->messages)->firstWhere('chat_id', 'subscriber-chat');
        $this->assertNotNull($message);
        $this->assertStringContainsString('NEW ORDER', $message['text']);
        $this->assertStringContainsString('Pan***', $message['text']);
        $this->assertStringContainsString('20,000,000 tokens', $message['text']);
        $this->assertStringContainsString('Successfully delivered', $message['text']);
        $this->assertSame('🛒 Buy Now', data_get($message, 'reply_markup.inline_keyboard.0.0.text'));
    }

    public function test_start_home_uses_the_final_click_first_button_template(): void
    {
        $user = User::factory()->create(['name' => 'Button Tester']);
        $account = $this->telegramAccount($user, 'button-chat', true)->load('user');

        app(TelegramCommerceService::class)->sendHome($account);

        $message = collect($this->bot->messages)->firstWhere('chat_id', 'button-chat');
        $this->assertNotNull($message);
        $this->assertStringContainsString('SP CAMBO AI STORE', $message['text']);
        $this->assertSame([
            ['🛍 Buy Package', '💰 My Balance'],
            ['🔑 My API Keys', '🧾 My Orders'],
            ['🧠 Models', '🔔 Updates'],
            ['🌐 Language', '📞 Support'],
        ], collect(data_get($message, 'reply_markup.keyboard', []))
            ->map(fn (array $row): array => collect($row)->pluck('text')->values()->all())
            ->values()
            ->all());
        $this->assertTrue((bool) data_get($message, 'reply_markup.is_persistent'));
    }

    public function test_muted_subscriber_receives_no_store_activity(): void
    {
        [$buyer, $order] = $this->order('telegram:buyer:muted');
        $buyerAccount = $this->telegramAccount($buyer, 'buyer-chat', true);
        $muted = User::factory()->create();
        $this->telegramAccount($muted, 'muted-chat', false);
        $order = $this->paidAndFulfilled($order);
        $purchase = TelegramPurchase::query()->create([
            'tenant_id' => $buyer->tenant_id,
            'user_id' => $buyer->id,
            'telegram_account_id' => $buyerAccount->id,
            'order_id' => $order->id,
            'status' => 'DELIVERED',
            'delivered_at' => now(),
            'last_checked_at' => now(),
        ]);

        app(TelegramPurchaseAlertService::class)->telegramPurchaseDelivered($purchase);
        app(TelegramAnnouncementService::class)->dispatchPending();

        $this->assertFalse(collect($this->bot->messages)->contains(fn (array $message): bool => $message['chat_id'] === 'muted-chat'));
    }

    public function test_fix17_legacy_purchase_alert_rows_are_cancelled_not_sent(): void
    {
        [, $order] = $this->order('legacy-row');
        TelegramPurchaseAlert::query()->create([
            'order_id' => $order->id,
            'event_key' => 'legacy-fix17-admin-row',
            'event_type' => 'ORDER_CREATED',
            'audience' => 'ADMIN',
            'chat_id' => '111111',
            'payload' => ['reference' => $order->reference],
            'status' => 'PENDING',
        ]);

        $result = app(TelegramPurchaseAlertService::class)->dispatchPending();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['sent']);
        $this->assertDatabaseHas('telegram_purchase_alerts', ['event_key' => 'legacy-fix17-admin-row', 'status' => 'CANCELLED']);
        $this->assertSame([], $this->bot->messages);
    }

    private function telegramAccount(User $user, string $chatId, bool $announcementsEnabled): TelegramAccount
    {
        return TelegramAccount::query()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'telegram_user_id' => (string) random_int(100000, 999999999),
            'chat_id' => $chatId,
            'username' => 'user'.random_int(1000, 9999),
            'locale' => 'en',
            'announcements_enabled' => $announcementsEnabled,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function paidAndFulfilled(Order $order): Order
    {
        $nonce = (string) $order->id;
        PaymentAttempt::query()->create([
            'order_id' => $order->id,
            'status' => 'PAID',
            'qr_payload' => 'TEST-KHQR-'.$nonce,
            'qr_md5' => md5($nonce),
            'amount_minor' => $order->total_minor,
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'transaction_hash' => hash('sha256', $nonce),
            'expires_at' => now()->addMinutes(5),
            'last_checked_at' => now(),
            'paid_at' => now(),
        ]);
        $order->forceFill(['status' => 'FULFILLED', 'fulfilled_at' => now()])->save();

        return $order->fresh(['items', 'user', 'paymentAttempts']);
    }

    /** @return array{0:User,1:Order} */
    private function order(string $idempotencyKey, string $name = 'Store Buyer'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', $idempotencyKey),
            'reference' => 'SPC-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12)),
            'status' => 'PENDING_PAYMENT',
            'currency' => 'USD',
            'currency_exponent' => 2,
            'subtotal_minor' => 250,
            'discount_total_minor' => 0,
            'total_minor' => 250,
        ]);
        $order->items()->create([
            'package_id' => null,
            'package_slug' => 'claude-pro-20m',
            'package_name' => 'Claude Pro 20M',
            'quantity' => 1,
            'unit_price_minor' => 250,
            'line_total_minor' => 250,
            'package_snapshot' => [
                'billing_mode' => 'TOKEN_QUOTA',
                'family_label' => 'Claude',
                'advertised_units' => '20000000',
                'unit_label' => 'tokens',
                'currency' => 'USD',
                'currency_exponent' => 2,
                'duration_seconds' => 86400,
                'allowed_model_aliases' => [],
                'limits' => [],
                'auto_creates_api_key' => true,
            ],
        ]);

        return [$user, $order->fresh(['items', 'user', 'paymentAttempts'])];
    }
}

class AlertCaptureBot extends TelegramBotClient
{
    /** @var array<int,array{chat_id:string,text:string,reply_markup:array|null}> */
    public array $messages = [];

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->messages[] = ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => $replyMarkup];
    }
}
