<?php

namespace App\Services;

use App\Exceptions\PackageStockException;
use App\Models\Order;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

/**
 * Optional package inventory.
 *
 * A null package stock_quantity is unlimited. Limited stock is reserved when an
 * order is created so two buyers cannot purchase the same last unit. Abandoned
 * reservations are released by the scheduler and are reacquired when a customer
 * resumes payment. A transfer that is verified after its reservation expired is
 * always honored: the service tries to reacquire stock first and records an
 * oversold marker rather than denying already-paid access.
 */
class PackageStockService
{
    public function reserveForOrder(Order $order): Order
    {
        $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);

        if ($locked->stock_consumed_at !== null) {
            return $locked;
        }
        if ($locked->stock_reserved_at !== null && $locked->stock_released_at === null) {
            return $locked;
        }

        $packages = $this->lockedPackagesFor($locked);
        foreach ($locked->items as $item) {
            if ($item->package_id === null) {
                continue;
            }
            $package = $packages->get((int) $item->package_id);
            if (! $package || $package->stock_quantity === null) {
                continue;
            }
            $requested = max(1, (int) $item->quantity);
            $available = (int) $package->stock_quantity;
            if ($available < $requested) {
                throw new PackageStockException((string) $item->package_name, $requested, $available);
            }
        }

        foreach ($locked->items as $item) {
            if ($item->package_id === null) {
                continue;
            }
            $package = $packages->get((int) $item->package_id);
            if (! $package || $package->stock_quantity === null) {
                continue;
            }
            $package->decrement('stock_quantity', max(1, (int) $item->quantity));
        }

        $locked->forceFill([
            'stock_reserved_at' => now(),
            'stock_released_at' => null,
            'stock_oversold_at' => null,
        ])->save();

        return $locked->fresh('items');
    }

    public function consumeForFulfillment(Order $order): Order
    {
        $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
        if ($locked->stock_consumed_at !== null) {
            return $locked;
        }

        if ($locked->stock_reserved_at === null || $locked->stock_released_at !== null) {
            try {
                $locked = $this->reserveForOrder($locked);
            } catch (PackageStockException $exception) {
                // Never strand a customer after a real payment was verified. This can
                // only occur when payment arrives after an abandoned reservation was
                // released and another buyer consumed the remaining stock.
                logger()->warning('Paid SP Cambo order exceeded limited package stock; fulfillment was honored.', [
                    'order_id' => (string) $locked->id,
                    'package' => $exception->packageName,
                    'requested' => $exception->requested,
                    'available' => $exception->available,
                ]);
                $locked->forceFill(['stock_oversold_at' => now()])->save();
            }
        }

        $locked->forceFill(['stock_consumed_at' => now()])->save();
        return $locked->fresh('items');
    }

    public function release(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($locked->stock_reserved_at === null || $locked->stock_released_at !== null || $locked->stock_consumed_at !== null) {
                return false;
            }

            $packages = $this->lockedPackagesFor($locked);
            foreach ($locked->items as $item) {
                if ($item->package_id === null) {
                    continue;
                }
                $package = $packages->get((int) $item->package_id);
                if (! $package || $package->stock_quantity === null) {
                    continue;
                }
                $package->increment('stock_quantity', max(1, (int) $item->quantity));
            }

            $locked->forceFill(['stock_released_at' => now()])->save();
            return true;
        });
    }

    /** @return array{checked:int,released:int} */
    public function releaseExpired(int $batch = 100): array
    {
        $batch = max(1, min($batch, 500));
        $ttl = max(300, (int) config('services.spcambo.package_stock_reservation_ttl_seconds', 900));
        $ids = Order::query()
            ->whereIn('status', ['PENDING_PAYMENT', 'VERIFYING'])
            ->whereNotNull('stock_reserved_at')
            ->whereNull('stock_released_at')
            ->whereNull('stock_consumed_at')
            ->where('stock_reserved_at', '<=', now()->subSeconds($ttl))
            ->whereDoesntHave('paymentAttempts', function ($q): void {
                $q->where('status', 'PAID')
                    ->orWhere(function ($active): void {
                        $active->whereIn('status', ['PENDING', 'VERIFYING'])
                            ->where('expires_at', '>', now());
                    });
            })
            ->oldest('stock_reserved_at')
            ->limit($batch)
            ->pluck('id');

        $released = 0;
        foreach ($ids as $id) {
            $order = Order::query()->find($id);
            if ($order && $this->release($order)) {
                $released++;
            }
        }

        return ['checked' => $ids->count(), 'released' => $released];
    }

    private function lockedPackagesFor(Order $order)
    {
        $ids = $order->items->pluck('package_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        return Package::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
    }
}
