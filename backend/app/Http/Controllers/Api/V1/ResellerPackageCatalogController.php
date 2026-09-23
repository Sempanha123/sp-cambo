<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResellerPackageCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reseller.manage'), 403);

        $packages = Package::query()
            ->published()
            ->where('fulfillment_target', 'RESELLER')
            ->with(['modelAliases' => fn ($query) => $query->published()->orderBy('public_alias')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $packages->map(fn (Package $package) => [
            'id' => (string) $package->id,
            'slug' => $package->slug,
            'name' => $package->name,
            'subtitle' => $package->subtitle,
            'badge' => $package->badge,
            'billing_mode' => $package->billing_mode,
            'fulfillment_target' => 'RESELLER',
            'family' => $package->family,
            'family_label' => $package->family_label,
            'advertised_units' => (string) $package->advertised_units,
            'unit_label' => $package->unit_label,
            'display_units' => isset(($package->billing_rules ?? [])['display_units'])
                ? (string) ($package->billing_rules['display_units'])
                : null,
            'display_unit_label' => isset(($package->billing_rules ?? [])['display_unit_label'])
                ? (string) ($package->billing_rules['display_unit_label'])
                : null,
            'package_kind' => (string) (($package->billing_rules ?? [])['package_kind']
                ?? (in_array((($package->billing_rules ?? [])['display_unit_label'] ?? null), ['Credits', 'SP Credits'], true)
                    ? 'SP_CREDITS'
                    : ($package->billing_mode === 'CREDIT_BALANCE' ? 'WALLET_CREDIT' : 'SP_TOKENS'))),
            'credit_amount' => $package->billing_mode === 'CREDIT_BALANCE'
                ? $this->money($package, (int) $package->advertised_units)
                : null,
            'price' => $this->money($package, (int) $package->price_minor),
            'compare_at_price' => $package->compare_at_price_minor === null
                ? null
                : $this->money($package, (int) $package->compare_at_price_minor),
            'duration_seconds' => (int) $package->duration_seconds,
            'stock_remaining' => $package->stock_quantity === null ? null : (string) $package->stock_quantity,
            'allowed_model_aliases' => $package->modelAliases->pluck('public_alias')->values(),
            'limits' => $package->limits,
            // Reseller-stock purchases must never create/claim a personal inference key.
            'auto_creates_api_key' => false,
            'featured' => (bool) $package->featured,
            'sort_order' => (int) $package->sort_order,
        ])->values()]);
    }

    /** @return array{minor:string,currency:string,exponent:int} */
    private function money(Package $package, int $minor): array
    {
        return [
            'minor' => (string) $minor,
            'currency' => $package->currency,
            'exponent' => (int) $package->currency_exponent,
        ];
    }
}
