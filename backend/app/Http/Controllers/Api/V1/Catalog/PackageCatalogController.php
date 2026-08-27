<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;

class PackageCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $packages = Package::query()->published()
            ->with(['modelAliases' => fn ($query) => $query->published()->orderBy('public_alias')])
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $packages->map(fn (Package $package) => [
            'id' => (string) $package->id,
            'slug' => $package->slug,
            'name' => $package->name,
            'subtitle' => $package->subtitle,
            'badge' => $package->badge,
            'billing_mode' => $package->billing_mode,
            'family' => $package->family,
            'family_label' => $package->family_label,
            'advertised_units' => (string) $package->advertised_units,
            'unit_label' => $package->unit_label,
            'credit_amount' => $package->billing_mode === 'CREDIT_BALANCE'
                ? $this->money($package, (int) $package->advertised_units)
                : null,
            'price' => $this->money($package, $package->price_minor),
            'compare_at_price' => $package->compare_at_price_minor === null ? null : $this->money($package, $package->compare_at_price_minor),
            'duration_seconds' => (int) $package->duration_seconds,
            'stock_remaining' => $package->stock_quantity === null ? null : (string) $package->stock_quantity,
            'allowed_model_aliases' => $package->modelAliases->pluck('public_alias')->values(),
            'limits' => $package->limits,
            'auto_creates_api_key' => $package->auto_creates_api_key,
            'featured' => $package->featured,
            'sort_order' => $package->sort_order,
        ])->values()]);
    }

    private function money(Package $package, int $minor): array
    {
        return ['minor' => (string) $minor, 'currency' => $package->currency, 'exponent' => $package->currency_exponent];
    }
}
