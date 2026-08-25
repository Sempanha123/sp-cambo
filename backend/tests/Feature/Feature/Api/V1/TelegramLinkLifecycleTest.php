<?php

namespace Tests\Feature\Feature\Api\V1;

use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramPurchase;
use App\Models\User;
use App\Services\TelegramCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TelegramLinkLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'services.telegram.link_secret' => 'telegram-link-test-secret',
        ]);
    }

    public function test_active_telegram_identity_cannot_be_silently_moved_to_another_account(): void
    {
        $telegram = app(TelegramCommerceService::class);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $firstToken = $telegram->createLinkToken($first)['token'];
        $firstAccount = $telegram->link($firstToken, '10001', '10001', 'first_user');
        $secondToken = $telegram->createLinkToken($second)['token'];

        try {
            $telegram->link($secondToken, '10001', '10001', 'second_user');
            $this->fail('An active Telegram identity must not be transferred silently.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already linked', $exception->getMessage());
        }

        $this->assertDatabaseHas('telegram_accounts', [
            'id' => $firstAccount->id,
            'user_id' => $first->id,
            'telegram_user_id' => '10001',
            'chat_id' => '10001',
        ]);
        $this->assertDatabaseMissing('telegram_accounts', [
            'user_id' => $second->id,
            'telegram_user_id' => '10001',
        ]);
    }

    public function test_unlink_preserves_purchase_history_and_releases_identity_for_later_relink(): void
    {
        $telegram = app(TelegramCommerceService::class);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $firstToken = $telegram->createLinkToken($first)['token'];
        $firstAccount = $telegram->link($firstToken, '20002', '20002', 'first_user');
        $order = Order::query()->create([
            'tenant_id' => $first->tenant_id,
            'user_id' => $first->id,
            'reference' => 'SPC-TELEGRAM-HISTORY',
            'status' => 'PENDING_PAYMENT',
            'currency' => 'USD',
            'currency_exponent' => 2,
            'subtotal_minor' => 100,
            'discount_total_minor' => 0,
            'total_minor' => 100,
        ]);
        $purchase = TelegramPurchase::query()->create([
            'tenant_id' => $first->tenant_id,
            'user_id' => $first->id,
            'telegram_account_id' => $firstAccount->id,
            'order_id' => $order->id,
            'status' => 'AWAITING_PAYMENT',
        ]);

        $telegram->unlink($first);
        $firstAccount->refresh();

        $this->assertNotNull($firstAccount->revoked_at);
        $this->assertStringStartsWith('rvk:', $firstAccount->telegram_user_id);
        $this->assertStringStartsWith('rvk:', $firstAccount->chat_id);
        $this->assertDatabaseHas('telegram_purchases', [
            'id' => $purchase->id,
            'telegram_account_id' => $firstAccount->id,
            'user_id' => $first->id,
        ]);

        $secondToken = $telegram->createLinkToken($second)['token'];
        $secondAccount = $telegram->link($secondToken, '20002', '20002', 'second_user');

        $this->assertNotSame($firstAccount->id, $secondAccount->id);
        $this->assertDatabaseHas('telegram_accounts', [
            'id' => $secondAccount->id,
            'user_id' => $second->id,
            'telegram_user_id' => '20002',
            'chat_id' => '20002',
        ]);
        $this->assertDatabaseHas('telegram_purchases', [
            'id' => $purchase->id,
            'telegram_account_id' => $firstAccount->id,
            'user_id' => $first->id,
        ]);
    }

    public function test_legacy_revoked_identity_is_released_without_deleting_its_row(): void
    {
        $telegram = app(TelegramCommerceService::class);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $legacy = TelegramAccount::query()->create([
            'tenant_id' => $first->tenant_id,
            'user_id' => $first->id,
            'telegram_user_id' => '30003',
            'chat_id' => '30003',
            'username' => 'legacy',
            'linked_at' => now()->subDay(),
            'revoked_at' => now()->subHour(),
        ]);

        $secondToken = $telegram->createLinkToken($second)['token'];
        $secondAccount = $telegram->link($secondToken, '30003', '30003', 'second_user');
        $legacy->refresh();

        $this->assertStringStartsWith('rvk:', $legacy->telegram_user_id);
        $this->assertDatabaseHas('telegram_accounts', ['id' => $legacy->id, 'user_id' => $first->id]);
        $this->assertDatabaseHas('telegram_accounts', [
            'id' => $secondAccount->id,
            'user_id' => $second->id,
            'telegram_user_id' => '30003',
        ]);
    }
    public function test_private_telegram_user_gets_a_storefront_customer_without_website_linking(): void
    {
        $telegram = app(TelegramCommerceService::class);

        $account = $telegram->ensureStorefrontAccount('40004', '40004', 'direct_buyer', 'Direct Buyer');

        $this->assertSame('40004', $account->telegram_user_id);
        $this->assertSame('40004', $account->chat_id);
        $this->assertSame('direct_buyer', $account->username);
        $this->assertStringStartsWith('telegram-40004@', $account->user->email);
        $this->assertTrue($account->user->hasRole('CUSTOMER'));
        $this->assertNotNull($account->user->tenant_id);

        $same = $telegram->ensureStorefrontAccount('40004', '40004', 'direct_buyer_renamed', 'Direct Buyer');
        $this->assertSame($account->id, $same->id);
        $this->assertSame('direct_buyer_renamed', $same->username);
    }

}
