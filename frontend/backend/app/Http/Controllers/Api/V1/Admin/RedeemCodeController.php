<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Models\RedeemCode;
use App\Services\RedeemCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RedeemCodeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => RedeemCode::query()->latest()->get()->map(fn (RedeemCode $code) => $this->resource($code))]);
    }

    public function store(Request $request, RedeemCodeService $codes): JsonResponse
    {
        $data = $this->validated($request);
        $aliases = ModelAlias::query()->whereIn('id', $data['allowed_model_alias_ids'])->pluck('public_alias')->values()->all();
        unset($data['allowed_model_alias_ids']);
        $result = $codes->issue($request->user(), $data + ['allowed_model_aliases' => $aliases]);

        return response()->json(['data' => $this->resource($result['redeem_code']) + ['code' => $result['secret']]], 201);
    }

    public function update(Request $request, RedeemCode $redeemCode): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'label' => ['required', 'string', 'max:150'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $redeemCode->update($data);
        return response()->json(['data' => $this->resource($redeemCode->fresh())]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'billing_mode' => ['required', Rule::in(['TOKEN_QUOTA', 'CREDIT_BALANCE'])],
            'units' => ['required', 'integer', 'min:1'],
            'duration_seconds' => ['required', 'integer', 'min:60'],
            'allowed_model_alias_ids' => ['required', 'array', 'min:1'],
            'allowed_model_alias_ids.*' => ['integer', 'distinct', 'exists:model_aliases,id'],
            'billing_rules' => ['nullable', 'array'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    private function resource(RedeemCode $code): array
    {
        return [
            'id' => $code->id,
            'masked_code' => $code->prefix.'••••••••'.$code->last_four,
            'label' => $code->label,
            'billing_mode' => $code->billing_mode,
            'units' => (string) $code->units,
            'duration_seconds' => (int) $code->duration_seconds,
            'allowed_model_aliases' => $code->allowed_model_aliases,
            'max_redemptions' => $code->max_redemptions,
            'per_user_limit' => (int) $code->per_user_limit,
            'redemptions' => $code->redemptions()->count(),
            'starts_at' => $code->starts_at?->toAtomString(),
            'ends_at' => $code->ends_at?->toAtomString(),
            'enabled' => (bool) $code->enabled,
            'created_at' => $code->created_at?->toAtomString(),
        ];
    }
}
