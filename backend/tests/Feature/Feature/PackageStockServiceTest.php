<?php

namespace Tests\Feature\Feature;

use App\Exceptions\PackageStockException;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\PackageStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageStockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finite_stock_is_reserved_and_released_exactly_once(): void
    {
        $package = $this->package(1);
        $order = $this->orderFor($package, 'stock-reserve');
        $stock = app(PackageStockService::class);

        DB::transaction(fn () => $stock->reserveForOrder($order));
        $this->assertSame(0, (int) $package->fresh()->stock_quantity);
        $this->assertNotNull($order->fresh()->stock_reserved_at);

        $this->assertTrue($stock->release($order));
        $this->assertSame(1, (int) $package->fresh()->stock_quantity);
        $this->assertFalse($stock->release($order));
        $this->assertSame(1, (int) $package->fresh()->stock_quantity);
    }

    public function test_last_unit_cannot_be_reserved_by_two_orders(): void
    {
        $package = $this->package(1);
        $stock = app(PackageStockService::class);
        $first = $this->orderFor($package, 'first');
        $second = $this->orderFor($package, 'second');

        DB::transaction(fn () => $stock->reserveForOrder($first));

        $this->expectException(PackageStockException::class);
        DB::transaction(fn () => $stock->reserveForOrder($second));
    }

    public function test_unlimited_stock_is_not_decremented(): void
    {
        $package = $this->package(null);
        $order = $this->orderFor($package, 'unlimited');

        DB::transaction(fn () => app(PackageStockService::class)->reserveForOrder($order));

        $this->assertNull($package->fresh()->stock_quantity);
        $this->assertNotNull($order->fresh()->stock_reserved_at);
    }

    private function package(?int $stock): Package
    {
        return Package::query()->create([
            'slug' => 'stock-'.($stock === null ? 'unlimited' : $stock).'-'.bin2hex(random_bytes(3)),
            'name' => 'Stock package',
            'billing_mode' => 'TOKEN_QUOTA',
            'family' => 'test',
            'family_label' => 'Test',
            'advertised_units' => 1000,
            'unit_label' => 'tokens',
            'price_minor' => 100,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'duration_seconds' => 86400,
            'stock_quantity' => $stock,
            'limits' => [],
            'auto_creates_api_key' => true,
            'enabled' => true,
            'customer_visible' => true,
        ]);
    }

    private function orderFor(Package $package, string $key): Order
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', $key),
            'reference' => 'SPC-STOCK-'.strtoupper(substr(hash('sha256', $key.random_int(1, 999999)), 0, 10)),
            'status' => 'PENDING_PAYMENT',
            'currency' => 'USD',
            'currency_exponent' => 2,
            'subtotal_minor' => 100,
            'discount_total_minor' => 0,
            'total_minor' => 100,
        ]);
        $order->items()->create([
            'package_id' => $package->id,
            'package_slug' => $package->slug,
            'package_name' => $package->name,
            'quantity' => 1,
            'unit_price_minor' => 100,
            'line_total_minor' => 100,
            'package_snapshot' => [],
        ]);

        return $order->fresh('items');
    }
}
