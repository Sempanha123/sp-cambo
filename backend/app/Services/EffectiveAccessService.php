<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EffectiveAccessService
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * Get effective access for a key and alias combination.
     *
     * @return array{billing_mode: string, limits: array, allowed_models: array, expiry: string|null, remaining: array}
     */
    public function getEffectiveAccess(ApiKey $key, ModelAlias $alias): array
    {
        $user = $key->user;
        $billingMode = $key->billing_mode;

        $lots = EntitlementLot::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereJsonContains('allowed_model_aliases', $alias->public_alias)
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('created_at')
            ->get();

        $matching = $lots->where('billing_mode', $billingMode);

        if ($matching->isEmpty()) {
            throw new InvalidArgumentException('No matching entitlement lots found for this key and model.');
        }

        $effectiveLimits = $this->calculateEffectiveLimits($matching, $key);
        $allowedModels = $this->calculateAllowedModels($matching);
        $expiry = $this->calculateExpiry($matching);
        $remaining = $this->calculateRemaining($matching, $billingMode);

        return [
            'billing_mode' => $billingMode,
            'limits' => $effectiveLimits,
            'allowed_models' => $allowedModels,
            'expiry' => $expiry,
            'remaining' => $remaining,
        ];
    }

    /**
     * Calculate effective limits from entitlement lots and key limits.
     */
    private function calculateEffectiveLimits(Collection $lots, ApiKey $key): array
    {
        $effectiveLimits = [
            'requests_per_minute' => null,
            'tokens_per_minute' => null,
            'concurrency' => null,
            'max_request_bytes' => null,
            'max_output_tokens' => null,
        ];

        foreach ($lots as $lot) {
            $lotLimits = $lot->billing_snapshot['limits'] ?? [];

            foreach ($effectiveLimits as $limitKey => $currentValue) {
                if (isset($lotLimits[$limitKey])) {
                    $lotValue = $lotLimits[$limitKey];
                    $effectiveLimits[$limitKey] = $currentValue === null ? $lotValue : min($currentValue, $lotValue);
                }
            }
        }

        // Apply key limits
        foreach ($effectiveLimits as $limitKey => &$value) {
            $keyValue = $key->{$limitKey};
            if ($keyValue !== null) {
                $value = $value === null ? $keyValue : min($value, $keyValue);
            }
        }

        return $effectiveLimits;
    }

    /**
     * Calculate allowed models from entitlement lots.
     */
    private function calculateAllowedModels(Collection $lots): array
    {
        $allowedModels = [];

        foreach ($lots as $lot) {
            $lotModels = $lot->allowed_model_aliases ?? [];
            $allowedModels = array_unique(array_merge($allowedModels, $lotModels));
        }

        return $allowedModels;
    }

    /**
     * Calculate expiry date from entitlement lots.
     */
    private function calculateExpiry(Collection $lots): ?string
    {
        $expiry = null;

        foreach ($lots as $lot) {
            if ($lot->expires_at !== null) {
                if ($expiry === null || $lot->expires_at < $expiry) {
                    $expiry = $lot->expires_at;
                }
            }
        }

        return $expiry;
    }

    /**
     * Calculate remaining balance/quota from entitlement lots.
     */
    private function calculateRemaining(Collection $lots, string $billingMode): array
    {
        $remaining = [
            'token_quota' => null,
            'credit_balance' => null,
        ];

        foreach ($lots as $lot) {
            if ($billingMode === 'TOKEN_QUOTA') {
                $remaining['token_quota'] = $remaining['token_quota'] === null
                    ? $lot->remaining_units
                    : $remaining['token_quota'] + $lot->remaining_units;
            } elseif ($billingMode === 'CREDIT_BALANCE') {
                $remaining['credit_balance'] = $remaining['credit_balance'] === null
                    ? $lot->remaining_amount
                    : $remaining['credit_balance'] + $lot->remaining_amount;
            }
        }

        return $remaining;
    }
}
