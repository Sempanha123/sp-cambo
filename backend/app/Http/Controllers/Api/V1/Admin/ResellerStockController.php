<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntitlementLot;
use App\Models\User;
use App\Services\ResellerStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ResellerStockController extends Controller
{
    public function index(User $reseller): JsonResponse
    {
        $lots = EntitlementLot::query()
            ->where('user_id', $reseller->id)
            ->where('source_type', ResellerStockService::SOURCE_TYPE)
            ->where('access_scope', ResellerStockService::ACCESS_SCOPE)
            ->latest()
            ->get();

        return response()->json(['data' => $lots->map(fn (EntitlementLot $lot): array => $this->resource($lot))->values()]);
    }

    public function store(Request $request, User $reseller, ResellerStockService $stocks): JsonResponse
    {
        $data = $request->validate([
            'billing_mode' => ['required', 'string', 'in:TOKEN_QUOTA,CREDIT_BALANCE'],
            'units' => ['required', 'integer', 'min:1'],
            'public_model_aliases' => ['required', 'array', 'min:1', 'max:100'],
            'public_model_aliases.*' => ['required', 'string', 'distinct', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'idempotency_key' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $result = $stocks->grant(
            $request->user(),
            $reseller,
            $data['billing_mode'],
            (int) $data['units'],
            $data['public_model_aliases'],
            $data['reason'],
            $data['idempotency_key'],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            'data' => $this->resource($result['lot']),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    private function resource(EntitlementLot $lot): array
    {
        return [
            'id' => (string) $lot->id,
            'user_id' => (string) $lot->user_id,
            'billing_mode' => (string) $lot->billing_mode,
            'original_units' => (string) $lot->original_units,
            'remaining_units' => (string) $lot->remaining_units,
            'reserved_units' => (string) $lot->reserved_units,
            'unit_label' => (string) $lot->unit_label,
            'currency' => $lot->currency,
            'currency_exponent' => $lot->currency_exponent,
            'allowed_model_aliases' => $lot->allowed_model_aliases ?? [],
            'status' => (string) $lot->status,
            'source_type' => (string) $lot->source_type,
            'access_scope' => (string) ($lot->access_scope ?? 'ACCOUNT'),
            'activated_at' => $lot->activated_at?->toAtomString(),
            'expires_at' => $lot->expires_at?->toAtomString(),
            'created_at' => $lot->created_at?->toAtomString(),
        ];
    }
}
