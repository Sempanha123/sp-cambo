<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\AuditService;
use App\Services\PackageProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    public function index(PackageProfitabilityService $profitability): JsonResponse
    {
        $packages = Package::query()->with('modelAliases')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $packages->map(fn (Package $package) => $this->resource($package, $profitability))]);
    }

    public function store(Request $request, PackageProfitabilityService $profitability, AuditService $audit): JsonResponse
    {
        $data = $this->validated($request);
        $data['limits'] ??= [];
        $package = DB::transaction(function () use ($request, $data, $profitability, $audit): Package {
            $aliases = $data['allowed_model_alias_ids'];
            unset($data['allowed_model_alias_ids']);
            $package = Package::query()->create($data);
            $package->modelAliases()->sync($aliases);
            if ($package->enabled && $package->customer_visible) {
                $profitability->assertPublishable($package);
            }
            $audit->record($request->user(), 'package.created', 'package', $package->id, $package->profitability_override_reason, ['slug' => $package->slug, 'enabled' => $package->enabled, 'customer_visible' => $package->customer_visible]);

            return $package;
        });

        return response()->json(['data' => $this->resource($package->fresh('modelAliases'), $profitability)], 201);
    }

    public function update(Request $request, Package $package, PackageProfitabilityService $profitability, AuditService $audit): JsonResponse
    {
        $data = $this->validated($request, $package);
        $data['limits'] ??= [];
        DB::transaction(function () use ($request, $data, $package, $profitability, $audit): void {
            $before = $package->only(['slug', 'enabled', 'customer_visible', 'price_minor', 'minimum_margin_bps']);
            $aliases = $data['allowed_model_alias_ids'];
            unset($data['allowed_model_alias_ids']);
            $package->update($data);
            $package->modelAliases()->sync($aliases);
            if ($package->enabled && $package->customer_visible) {
                $profitability->assertPublishable($package);
            }
            $audit->record($request->user(), 'package.updated', 'package', $package->id, $package->profitability_override_reason, ['before' => $before, 'after' => $package->only(array_keys($before))]);
        });

        return response()->json(['data' => $this->resource($package->fresh('modelAliases'), $profitability)]);
    }

    public function profitability(Package $package, PackageProfitabilityService $profitability): JsonResponse
    {
        return response()->json(['data' => $profitability->analyze($package)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Package $package = null): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:100', Rule::unique('packages', 'slug')->ignore($package)],
            'name' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'badge' => ['nullable', 'string', 'max:100'],
            'billing_mode' => ['required', Rule::in(['TOKEN_QUOTA', 'CREDIT_BALANCE'])],
            'family' => ['required', 'string', 'max:100'],
            'family_label' => ['required', 'string', 'max:100'],
            'advertised_units' => ['required', 'integer', 'min:1'],
            'unit_label' => ['required', 'string', 'max:50'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'compare_at_price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'currency_exponent' => ['required', 'integer', 'between:0,6'],
            'duration_seconds' => ['required', 'integer', 'min:1'],
            'limits' => ['present', 'array'],
            'limits.requests_per_minute' => ['sometimes', 'integer', 'min:1'],
            'limits.tokens_per_minute' => ['sometimes', 'integer', 'min:1'],
            'limits.concurrency' => ['sometimes', 'integer', 'min:1'],
            'limits.max_request_bytes' => ['sometimes', 'integer', 'min:1024'],
            'limits.max_output_tokens' => ['sometimes', 'integer', 'min:1'],
            'billing_rules' => ['nullable', 'array'],
            'billing_rules.input_weight_microunits' => ['sometimes', 'integer', 'min:0'],
            'billing_rules.output_weight_microunits' => ['sometimes', 'integer', 'min:0'],
            'billing_rules.cache_read_weight_microunits' => ['sometimes', 'integer', 'min:0'],
            'billing_rules.cache_write_weight_microunits' => ['sometimes', 'integer', 'min:0'],
            'billing_rules.reasoning_weight_microunits' => ['sometimes', 'integer', 'min:0'],
            'auto_creates_api_key' => ['required', 'boolean'],
            'featured' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'enabled' => ['required', 'boolean'],
            'customer_visible' => ['required', 'boolean'],
            'minimum_margin_bps' => ['required', 'integer', 'between:0,10000'],
            'profitability_override_reason' => ['nullable', 'string', 'min:10', 'max:2000'],
            'allowed_model_alias_ids' => ['required', 'array'],
            'allowed_model_alias_ids.*' => ['integer', 'distinct', 'exists:model_aliases,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(Package $package, PackageProfitabilityService $profitability): array
    {
        return [
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
            'price_minor' => (string) $package->price_minor,
            'compare_at_price_minor' => $package->compare_at_price_minor === null ? null : (string) $package->compare_at_price_minor,
            'currency' => $package->currency,
            'currency_exponent' => (int) $package->currency_exponent,
            'price' => ['minor' => (string) $package->price_minor, 'currency' => $package->currency, 'exponent' => (int) $package->currency_exponent],
            'compare_at_price' => $package->compare_at_price_minor === null ? null : ['minor' => (string) $package->compare_at_price_minor, 'currency' => $package->currency, 'exponent' => (int) $package->currency_exponent],
            'duration_seconds' => (int) $package->duration_seconds,
            'limits' => $package->limits,
            'billing_rules' => $package->billing_rules,
            'auto_creates_api_key' => (bool) $package->auto_creates_api_key,
            'featured' => (bool) $package->featured,
            'sort_order' => (int) $package->sort_order,
            'starts_at' => $package->starts_at?->toAtomString(),
            'ends_at' => $package->ends_at?->toAtomString(),
            'allowed_model_alias_ids' => $package->modelAliases->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            'allowed_model_aliases' => $package->modelAliases->pluck('public_alias')->values(),
            'enabled' => (bool) $package->enabled,
            'customer_visible' => (bool) $package->customer_visible,
            'minimum_margin_bps' => (int) $package->minimum_margin_bps,
            'profitability_override_reason' => $package->profitability_override_reason,
            'profitability' => $profitability->analyze($package),
        ];
    }
}
