<?php

namespace App\Services;

use App\Exceptions\PackagePublicationException;
use App\Models\ModelAlias;
use App\Models\Package;
use RuntimeException;

class PackageProfitabilityService
{
    private const BPS_SCALE = 10_000;

    private const RATIO_SCALE = 1_000_000;

    /** @return array{reviewable: bool, profitable: bool|null, price_minor: string, worst_case_cost_minor: string|null, margin_minor: string|null, margin_bps: int|null, minimum_margin_bps: int, missing_cost_aliases: array<int, string>, override_required: bool} */
    public function analyze(Package $package): array
    {
        $package->loadMissing('modelAliases.pricing');
        $missing = [];
        $worstPerMillion = 0;
        $worstCreditCostRatio = 0;

        foreach ($package->modelAliases as $alias) {
            $pricing = $alias->pricing;
            if (! $pricing || ! $pricing->upstream_cost_verified_at) {
                $missing[] = $alias->public_alias;

                continue;
            }

            $costs = [
                'input' => $pricing->upstream_input_per_million_minor,
                'output' => $pricing->upstream_output_per_million_minor,
                'cache_read' => $pricing->upstream_cache_read_per_million_minor,
                'cache_write' => $pricing->upstream_cache_write_per_million_minor,
                'reasoning' => $pricing->upstream_reasoning_per_million_minor,
            ];
            $sell = [
                'input' => $pricing->input_per_million_minor,
                'output' => $pricing->output_per_million_minor,
                'cache_read' => $pricing->cache_read_per_million_minor,
                'cache_write' => $pricing->cache_write_per_million_minor,
                'reasoning' => $pricing->reasoning_per_million_minor,
            ];
            $limits = is_array($alias->limits) ? $alias->limits : [];
            $classes = is_array($limits['billing_usage_classes'] ?? null)
                ? array_values(array_intersect(
                    ['input', 'output', 'cache_read', 'cache_write', 'reasoning'],
                    $limits['billing_usage_classes'],
                ))
                : array_values(array_filter(
                    ['input', 'output', 'cache_read', 'cache_write', 'reasoning'],
                    fn (string $class): bool => ($costs[$class] ?? null) !== null && ($sell[$class] ?? null) !== null,
                ));

            if ($classes === []) {
                $missing[] = $alias->public_alias;

                continue;
            }

            if ($package->billing_mode === 'TOKEN_QUOTA') {
                $rules = is_array($package->billing_rules) ? $package->billing_rules : [];
                $weights = [
                    'input' => (int) ($rules['input_weight_microunits'] ?? 1_000_000),
                    'output' => (int) ($rules['output_weight_microunits'] ?? 1_000_000),
                    'cache_read' => (int) ($rules['cache_read_weight_microunits'] ?? 1_000_000),
                    'cache_write' => (int) ($rules['cache_write_weight_microunits'] ?? 1_000_000),
                    'reasoning' => (int) ($rules['reasoning_weight_microunits'] ?? 1_000_000),
                ];
                $ruleMultipliers = is_array($rules['billing_multipliers_bps'] ?? null) ? $rules['billing_multipliers_bps'] : [];
                $aliasMultipliers = is_array($limits['billing_multipliers_bps'] ?? null) ? $limits['billing_multipliers_bps'] : [];
                $aliasRates = [];

                foreach ($classes as $class) {
                    $cost = $costs[$class] ?? null;
                    if ($cost === null) {
                        $missing[] = $alias->public_alias;
                        $aliasRates = [];
                        break;
                    }

                    $weight = max(1, $weights[$class] ?? 1_000_000);
                    $multiplier = max(self::BPS_SCALE, (int) ($ruleMultipliers[$class] ?? $aliasMultipliers[$class] ?? self::BPS_SCALE));
                    $effectiveWeight = $this->ceilMulDiv($weight, $multiplier, self::BPS_SCALE);

                    // Private estimated cost of one million SP billable quota
                    // units if they were spent entirely on this locally-metered
                    // usage class. This uses SP Cambo's configured reference-cost
                    // baseline, never OmniRoute/provider runtime usage data.
                    $normalizedRate = $this->ceilMulDiv((int) $cost, 1_000_000, max(1, $effectiveWeight));
                    $aliasRates[] = $this->convertExponent(
                        $normalizedRate,
                        (int) $pricing->exponent,
                        (int) $package->currency_exponent,
                    );
                }

                if ($aliasRates !== []) {
                    $worstPerMillion = max($worstPerMillion, max($aliasRates));
                }
            } else {
                $aliasMultipliers = is_array($limits['billing_multipliers_bps'] ?? null) ? $limits['billing_multipliers_bps'] : [];
                $aliasRatios = [];

                foreach ($classes as $class) {
                    $cost = $costs[$class] ?? null;
                    $price = $sell[$class] ?? null;
                    if ($cost === null || $price === null || (int) $price <= 0) {
                        $missing[] = $alias->public_alias;
                        $aliasRatios = [];
                        break;
                    }

                    $multiplier = max(self::BPS_SCALE, (int) ($aliasMultipliers[$class] ?? self::BPS_SCALE));
                    // One credit unit is evaluated against SP Cambo's private
                    // reference-cost baseline at the effective customer sell rate
                    // (base rate x published billing multiplier). No provider
                    // runtime usage or invoice data is required.
                    $denominator = $this->checkedMultiply((int) $price, $multiplier);
                    $numerator = $this->checkedMultiply(
                        $this->checkedMultiply((int) $cost, self::RATIO_SCALE),
                        self::BPS_SCALE,
                    );
                    $aliasRatios[] = intdiv($numerator + $denominator - 1, $denominator);
                }

                if ($aliasRatios !== []) {
                    $worstCreditCostRatio = max($worstCreditCostRatio, max($aliasRatios));
                }
            }
        }

        $missing = array_values(array_unique($missing));
        $reviewable = $package->modelAliases->isNotEmpty() && $missing === [];
        $cost = null;

        if ($reviewable && $package->billing_mode === 'TOKEN_QUOTA') {
            $cost = $this->ceilScaledCost((int) $package->advertised_units, $worstPerMillion);
        } elseif ($reviewable && $package->billing_mode === 'CREDIT_BALANCE') {
            $cost = $this->ceilMulDiv((int) $package->advertised_units, $worstCreditCostRatio, self::RATIO_SCALE);
        }

        $margin = $cost === null ? null : (int) $package->price_minor - $cost;
        $marginBps = $margin === null || (int) $package->price_minor === 0 ? null : intdiv($margin * self::BPS_SCALE, (int) $package->price_minor);
        $profitable = $marginBps === null ? null : $marginBps >= (int) $package->minimum_margin_bps;

        return [
            'reviewable' => $reviewable,
            'profitable' => $profitable,
            'price_minor' => (string) $package->price_minor,
            'worst_case_cost_minor' => $cost === null ? null : (string) $cost,
            'margin_minor' => $margin === null ? null : (string) $margin,
            'margin_bps' => $marginBps,
            'minimum_margin_bps' => (int) $package->minimum_margin_bps,
            'missing_cost_aliases' => $missing,
            'override_required' => $profitable !== true,
        ];
    }

    public function assertPublishable(Package $package): void
    {
        $package->loadMissing('modelAliases.model.provider.activeConnectionRevision');

        if ($package->modelAliases->isEmpty()) {
            throw new PackagePublicationException('A customer-visible package must allow at least one public model.');
        }

        $selectedIds = $package->modelAliases->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $publishedIds = ModelAlias::query()->published()->whereIn('id', $selectedIds)->pluck('id')->map(fn ($id): int => (int) $id);
        $blocked = $package->modelAliases->whereNotIn('id', $publishedIds)->pluck('public_alias')->values()->all();

        if ($blocked !== []) {
            $details = [];
            foreach ($package->modelAliases->whereIn('public_alias', $blocked) as $alias) {
                $reasons = [];
                $model = $alias->model;
                $provider = $model?->provider;
                if (! $provider || ! $provider->enabled) $reasons[] = 'provider disabled/missing';
                if ($provider && (! $provider->activeConnectionRevision || ! $provider->activeConnectionRevision->isRouteReady())) $reasons[] = 'no active READY connection';
                if (! $model || ! $model->enabled) $reasons[] = 'private model disabled/missing';
                elseif ($model->commercial_resale_verified_at === null) $reasons[] = 'commercial resale not verified';
                if (! $alias->enabled) $reasons[] = 'public alias disabled';
                if (! $alias->customer_visible) $reasons[] = 'public alias hidden';
                if (! in_array($alias->status, ['active', 'beta'], true)) $reasons[] = 'public alias status not publishable';
                $details[] = $alias->public_alias.' ['.implode(', ', $reasons ?: ['not publishable']).']';
            }

            throw new PackagePublicationException(
                'Package contains public models that are not sell-ready: '.implode('; ', $details).'. Fix the listed provider/model/alias blockers and save again.'
            );
        }

        $analysis = $this->analyze($package);
        if ($analysis['profitable'] !== true && blank($package->profitability_override_reason)) {
            throw new PackagePublicationException('Package publication requires verified profitability or an explicit override reason.');
        }
    }

    private function convertExponent(int $amount, int $fromExponent, int $toExponent): int
    {
        $delta = $fromExponent - $toExponent;
        if ($delta === 0) {
            return $amount;
        }

        $factor = 1;
        for ($i = 0; $i < abs($delta); $i++) {
            $factor *= 10;
        }

        if ($delta > 0) {
            return intdiv($amount + $factor - 1, $factor);
        }

        if ($amount !== 0 && $factor > intdiv(PHP_INT_MAX, $amount)) {
            throw new RuntimeException('Package currency conversion exceeds the supported integer range.');
        }

        return $amount * $factor;
    }

    private function ceilScaledCost(int $units, int $costPerMillion): int
    {
        $whole = intdiv($units, 1_000_000);
        if ($costPerMillion !== 0 && $whole > intdiv(PHP_INT_MAX, $costPerMillion)) {
            throw new RuntimeException('Package cost calculation exceeds the supported integer range.');
        }

        return ($whole * $costPerMillion) + intdiv((($units % 1_000_000) * $costPerMillion) + 999_999, 1_000_000);
    }

    private function ceilMulDiv(int $left, int $right, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new RuntimeException('Package profitability divisor must be positive.');
        }
        $product = $this->checkedMultiply($left, $right);

        return intdiv($product + $divisor - 1, $divisor);
    }

    private function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new RuntimeException('Package profitability values cannot be negative.');
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new RuntimeException('Package profitability calculation exceeds the supported integer range.');
        }

        return $left * $right;
    }
}
