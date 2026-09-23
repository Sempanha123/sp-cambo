<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EntitlementLot;
use App\Services\ResellerStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResellerInventoryController extends Controller
{
    public function __invoke(
        Request $request,
        ResellerStockService $stocks
    ): JsonResponse {
        /*
         * Makes old untouched paid purchases follow the same rule as new ones.
         * This is idempotent; once adopted they no longer match source_type=ORDER.
         */
        $stocks->adoptEligiblePurchases($request->user());

        $lots = EntitlementLot::query()
            ->where('user_id', $request->user()->id)
            ->where('source_type', ResellerStockService::SOURCE_TYPE)
            ->where('access_scope', ResellerStockService::ACCESS_SCOPE)
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $lots->map(fn (EntitlementLot $lot): array => [
                'id' => (string) $lot->id,
                'billing_mode' => (string) $lot->billing_mode,
                'package_name' => (string) $lot->package_name,
                'family_label' => (string) $lot->family_label,
                'original_units' => (string) $lot->original_units,
                'remaining_units' => (string) $lot->remaining_units,
                'reserved_units' => (string) $lot->reserved_units,
                'unit_label' => (string) $lot->unit_label,
                'remaining_amount' => $lot->billing_mode === 'CREDIT_BALANCE'
                    ? [
                        'minor' => (string) $lot->remaining_units,
                        'currency' => $lot->currency ?? 'USD',
                        'exponent' => (int) ($lot->currency_exponent ?? 6),
                    ]
                    : null,
                'activated_at' => $lot->activated_at?->toAtomString(),
                'expires_at' => $lot->expires_at?->toAtomString(),
                'allowed_model_aliases' => $lot->allowed_model_aliases ?? [],
                'status' => (string) $lot->status,
                'source' => (string) $lot->source_type,
                'access_scope' => (string) ($lot->access_scope ?? 'ACCOUNT'),
                'fulfillment_claim_id' => $lot->fulfillment_claim_id,
                'bound_api_key' => null,
            ])->values(),
        ]);
    }
}
