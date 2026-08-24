<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Promotion;
use App\Models\User;

class PromotionService
{
    /** @return array{code: string, label: string, valid: bool, reason: string|null, discount_minor: int, total_minor: int, bonus_units: int|null, promotion: Promotion|null} */
    public function evaluate(?string $code, Package $package, User $user, int $subtotal, bool $forRedemption = false): array
    {
        $normalized = mb_strtoupper(trim((string) $code));
        if ($normalized === '') {
            return $this->invalid('', '', 'Enter a promotion code.', $subtotal);
        }

        $query = Promotion::query()->where('code', $normalized);
        $promotion = $forRedemption ? $query->lockForUpdate()->first() : $query->first();
        if (! $promotion || ! $promotion->enabled || $promotion->starts_at?->isFuture() || $promotion->ends_at?->isPast()) {
            return $this->invalid($normalized, $promotion?->label ?? '', 'This promotion is not available.', $subtotal);
        }
        if ($promotion->packages()->exists() && ! $promotion->packages()->whereKey($package->id)->exists()) {
            return $this->invalid($normalized, $promotion->label, 'This promotion does not apply to the selected package.', $subtotal);
        }
        if ($promotion->currency !== $package->currency || (int) $promotion->currency_exponent !== (int) $package->currency_exponent) {
            return $this->invalid($normalized, $promotion->label, 'This promotion does not apply to the selected currency.', $subtotal);
        }
        if ($subtotal < (int) $promotion->minimum_order_minor) {
            return $this->invalid($normalized, $promotion->label, 'The order does not meet this promotion minimum.', $subtotal);
        }
        if ($promotion->max_redemptions !== null && $promotion->redemptions()->count() >= $promotion->max_redemptions) {
            return $this->invalid($normalized, $promotion->label, 'This promotion has reached its redemption limit.', $subtotal);
        }
        if ($promotion->per_user_limit !== null && $promotion->redemptions()->where('user_id', $user->id)->count() >= $promotion->per_user_limit) {
            return $this->invalid($normalized, $promotion->label, 'You have already used this promotion the maximum number of times.', $subtotal);
        }
        if ($promotion->new_customer_only && $user->orders()->exists()) {
            return $this->invalid($normalized, $promotion->label, 'This promotion is available only before your first order.', $subtotal);
        }

        $discount = match ($promotion->type) {
            'PERCENTAGE' => intdiv($subtotal * (int) $promotion->percentage_bps, 10_000),
            'FIXED' => (int) $promotion->fixed_discount_minor,
            'BONUS' => 0,
            'PRICE_OVERRIDE' => max(0, $subtotal - (int) $promotion->price_override_minor),
            'FREE' => $subtotal,
            default => 0,
        };
        if ($promotion->maximum_discount_minor !== null) {
            $discount = min($discount, (int) $promotion->maximum_discount_minor);
        }
        $discount = min($discount, $subtotal);

        return ['code' => $promotion->code, 'label' => $promotion->label, 'valid' => true, 'reason' => null, 'discount_minor' => $discount, 'total_minor' => $subtotal - $discount, 'bonus_units' => $promotion->bonus_units === null ? null : (int) $promotion->bonus_units, 'promotion' => $promotion];
    }

    private function invalid(string $code, string $label, string $reason, int $subtotal): array
    {
        return ['code' => $code, 'label' => $label, 'valid' => false, 'reason' => $reason, 'discount_minor' => 0, 'total_minor' => $subtotal, 'bonus_units' => null, 'promotion' => null];
    }
}
