<?php

namespace App\Services;

use App\Exceptions\InferenceIdempotencyException;
use App\Exceptions\PackageStockException;
use App\Models\Order;
use App\Models\Package;
use App\Models\PromotionRedemption;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly TelegramPurchaseAlertService $purchaseAlerts,
        private readonly PackageStockService $stock,
    ) {}

    /**
     * Create an order exactly once for one user-scoped idempotency key.
     *
     * @return array{order: Order, created: bool}
     */
    public function create(User $user, string $packageSlug, int $quantity, ?string $promotionCode, string $idempotencyKey): array
    {
        $packageSlug = trim($packageSlug);
        $promotionCode = $this->normalizePromotionCode($promotionCode);
        $idempotencyKey = trim($idempotencyKey);
        $fingerprint = $this->fingerprint($packageSlug, $quantity, $promotionCode);

        try {
            $result = DB::transaction(function () use ($user, $packageSlug, $quantity, $promotionCode, $idempotencyKey, $fingerprint): array {
                $existing = $this->existing($user, $idempotencyKey, $fingerprint);
                if ($existing !== null) {
                    return ['order' => $existing, 'created' => false];
                }

                $package = Package::query()->published()->where('slug', $packageSlug)->lockForUpdate()->firstOrFail();
                if ((int) $package->price_minor > intdiv(PHP_INT_MAX, $quantity)) {
                    throw ValidationException::withMessages(['quantity' => ['The requested quantity is too large.']]);
                }
                $subtotal = (int) $package->price_minor * $quantity;
                $promotion = $promotionCode === null ? null : $this->promotions->evaluate($promotionCode, $package, $user, $subtotal, true);
                if ($promotion !== null && ! $promotion['valid']) {
                    throw ValidationException::withMessages(['promotion_code' => [$promotion['reason']]]);
                }
                $discount = $promotion['discount_minor'] ?? 0;
                $order = Order::query()->create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint, 'reference' => 'SPC-'.Str::ulid(), 'status' => 'PENDING_PAYMENT', 'currency' => $package->currency, 'currency_exponent' => $package->currency_exponent, 'subtotal_minor' => $subtotal, 'discount_total_minor' => $discount, 'total_minor' => $subtotal - $discount, 'promotion_id' => $promotion['promotion']->id ?? null, 'promotion_snapshot' => $promotion === null ? null : ['code' => $promotion['code'], 'label' => $promotion['label'], 'type' => $promotion['promotion']->type, 'discount_minor' => $discount, 'bonus_units' => $promotion['bonus_units']]]);
                $order->items()->create(['package_id' => $package->id, 'package_slug' => $package->slug, 'package_name' => $package->name, 'quantity' => $quantity, 'unit_price_minor' => $package->price_minor, 'line_total_minor' => $subtotal, 'package_snapshot' => ['billing_mode' => $package->billing_mode, 'family_label' => $package->family_label, 'advertised_units' => (string) $package->advertised_units, 'unit_label' => $package->unit_label, 'currency' => $package->currency, 'currency_exponent' => (int) $package->currency_exponent, 'duration_seconds' => (int) $package->duration_seconds, 'allowed_model_aliases' => $package->modelAliases()->published()->pluck('public_alias')->values()->all(), 'limits' => $package->limits, 'billing_rules' => $package->billing_rules, 'auto_creates_api_key' => $package->auto_creates_api_key]]);
                try {
                    $order = $this->stock->reserveForOrder($order->fresh('items'));
                } catch (PackageStockException $exception) {
                    throw ValidationException::withMessages(['package_slug' => [$exception->getMessage()]]);
                }
                if ($promotion !== null) {
                    PromotionRedemption::query()->create(['promotion_id' => $promotion['promotion']->id, 'user_id' => $user->id, 'order_id' => $order->id, 'discount_minor' => $discount, 'bonus_units' => $promotion['bonus_units']]);
                }
                $this->purchaseAlerts->orderCreated($order->load('items'));

                if ((int) $order->total_minor === 0) {
                    $order = $this->fulfillment->fulfill($order);
                }

                return ['order' => $order->load('items'), 'created' => true];
            });

            return $result;
        } catch (UniqueConstraintViolationException $exception) {
            return $this->recoverConcurrentInsert($user, $idempotencyKey, $fingerprint, $exception);
        } catch (QueryException $exception) {
            // SQLite reports a concurrent writer as a locked database rather than the
            // unique violation MySQL emits after the winning transaction commits.
            if (DB::getDriverName() === 'sqlite' && str_contains(mb_strtolower($exception->getMessage()), 'database is locked')) {
                return $this->recoverConcurrentInsert($user, $idempotencyKey, $fingerprint, $exception);
            }

            throw $exception;
        }
    }

    /** @return array{order: Order, created: false} */
    private function recoverConcurrentInsert(User $user, string $idempotencyKey, string $fingerprint, QueryException $exception): array
    {
        // A concurrent request can win the unique-key insert after our first lookup.
        // Retry briefly so MySQL's committed winner is visible, then compare the
        // immutable fingerprint. Never query or expose another tenant's order.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $existing = $this->existing($user, $idempotencyKey, $fingerprint);
            if ($existing !== null) {
                return ['order' => $existing, 'created' => false];
            }
            usleep(20_000);
        }

        throw $exception;
    }

    private function existing(User $user, string $idempotencyKey, string $fingerprint): ?Order
    {
        $existing = Order::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            return null;
        }
        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            throw new InferenceIdempotencyException('The order idempotency key was already used for different purchase inputs.');
        }

        return $existing->load('items');
    }

    private function normalizePromotionCode(?string $promotionCode): ?string
    {
        $normalized = mb_strtoupper(trim((string) $promotionCode));

        return $normalized === '' ? null : $normalized;
    }

    private function fingerprint(string $packageSlug, int $quantity, ?string $promotionCode): string
    {
        return hash('sha256', json_encode([
            'package_slug' => $packageSlug,
            'quantity' => $quantity,
            'promotion_code' => $promotionCode,
        ], JSON_THROW_ON_ERROR));
    }
}
