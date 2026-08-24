<?php

namespace App\Services;

use App\Exceptions\PackagePublicationException;
use App\Models\Package;
use RuntimeException;

class PackageProfitabilityService
{
    /** @return array{reviewable: bool, profitable: bool|null, price_minor: string, worst_case_cost_minor: string|null, margin_minor: string|null, margin_bps: int|null, minimum_margin_bps: int, missing_cost_aliases: array<int, string>, override_required: bool} */
    public function analyze(Package $package): array
    {
        $package->loadMissing('modelAliases.pricing');
        $missing = [];
        $worstPerMillion = 0;

        foreach ($package->modelAliases as $alias) {
            $pricing = $alias->pricing;
            $costs = $pricing ? array_filter([
                $pricing->upstream_input_per_million_minor,
                $pricing->upstream_output_per_million_minor,
                $pricing->upstream_cache_read_per_million_minor,
                $pricing->upstream_cache_write_per_million_minor,
            ], fn ($value): bool => $value !== null) : [];
            if (! $pricing || ! $pricing->upstream_cost_verified_at || $costs === []) {
                $missing[] = $alias->public_alias;

                continue;
            }
            $worstPerMillion = max($worstPerMillion, max(array_map('intval', $costs)));
        }

        $reviewable = $package->modelAliases->isNotEmpty() && $missing === [];
        $cost = $reviewable ? $this->ceilScaledCost((int) $package->advertised_units, $worstPerMillion) : null;
        $margin = $cost === null ? null : (int) $package->price_minor - $cost;
        $marginBps = $margin === null || (int) $package->price_minor === 0 ? null : intdiv($margin * 10_000, (int) $package->price_minor);
        $profitable = $marginBps === null ? null : $marginBps >= (int) $package->minimum_margin_bps;

        return ['reviewable' => $reviewable, 'profitable' => $profitable, 'price_minor' => (string) $package->price_minor, 'worst_case_cost_minor' => $cost === null ? null : (string) $cost, 'margin_minor' => $margin === null ? null : (string) $margin, 'margin_bps' => $marginBps, 'minimum_margin_bps' => (int) $package->minimum_margin_bps, 'missing_cost_aliases' => $missing, 'override_required' => $profitable !== true];
    }

    public function assertPublishable(Package $package): void
    {
        $analysis = $this->analyze($package);
        if ($analysis['profitable'] !== true && blank($package->profitability_override_reason)) {
            throw new PackagePublicationException('Package publication requires verified profitability or an explicit override reason.');
        }
    }

    private function ceilScaledCost(int $units, int $costPerMillion): int
    {
        $whole = intdiv($units, 1_000_000);
        if ($costPerMillion !== 0 && $whole > intdiv(PHP_INT_MAX, $costPerMillion)) {
            throw new RuntimeException('Package cost calculation exceeds the supported integer range.');
        }

        return ($whole * $costPerMillion) + intdiv((($units % 1_000_000) * $costPerMillion) + 999_999, 1_000_000);
    }
}
