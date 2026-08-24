<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Promotion::query()->with('packages:id,slug')->orderByDesc('priority')->orderBy('id')->get()->map(fn (Promotion $promotion) => $this->resource($promotion))]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->persist($request, new Promotion, $audit, 'promotion.created'))], 201);
    }

    public function update(Request $request, Promotion $promotion, AuditService $audit): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->persist($request, $promotion, $audit, 'promotion.updated'))]);
    }

    private function persist(Request $request, Promotion $promotion, AuditService $audit, string $action): Promotion
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('promotions', 'code')->ignore($promotion)],
            'label' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['PERCENTAGE', 'FIXED', 'BONUS', 'PRICE_OVERRIDE', 'FREE'])],
            'currency' => ['required', 'string', 'size:3'],
            'currency_exponent' => ['required', 'integer', 'between:0,6'],
            'percentage_bps' => ['nullable', 'integer', 'between:1,10000', 'required_if:type,PERCENTAGE'],
            'fixed_discount_minor' => ['nullable', 'integer', 'min:1', 'required_if:type,FIXED'],
            'price_override_minor' => ['nullable', 'integer', 'min:0', 'required_if:type,PRICE_OVERRIDE'],
            'bonus_units' => ['nullable', 'integer', 'min:1', 'required_if:type,BONUS'],
            'minimum_order_minor' => ['required', 'integer', 'min:0'],
            'maximum_discount_minor' => ['nullable', 'integer', 'min:1'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'new_customer_only' => ['required', 'boolean'],
            'stackable' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'enabled' => ['required', 'boolean'],
            'package_ids' => ['present', 'array'],
            'package_ids.*' => ['integer', 'distinct', 'exists:packages,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $packageIds = $data['package_ids'];
        $reason = $data['reason'];
        unset($data['package_ids'], $data['reason']);
        $data['code'] = mb_strtoupper(trim($data['code']));

        return DB::transaction(function () use ($request, $promotion, $data, $packageIds, $reason, $audit, $action): Promotion {
            $before = $promotion->exists ? $promotion->toArray() : null;
            $data['currency'] = mb_strtoupper($data['currency']);
            $promotion->fill($data)->save();
            $promotion->packages()->sync($packageIds);
            $audit->record($request->user(), $action, 'promotion', $promotion->id, $reason, ['before' => $before, 'after' => $promotion->only(array_keys($data)), 'package_ids' => $packageIds]);

            return $promotion->fresh('packages:id,slug');
        });
    }

    private function resource(Promotion $promotion): array
    {
        return ['id' => (string) $promotion->id, 'code' => $promotion->code, 'label' => $promotion->label, 'type' => $promotion->type, 'currency' => $promotion->currency, 'currency_exponent' => (int) $promotion->currency_exponent, 'percentage_bps' => $promotion->percentage_bps, 'fixed_discount_minor' => $promotion->fixed_discount_minor === null ? null : (string) $promotion->fixed_discount_minor, 'price_override_minor' => $promotion->price_override_minor === null ? null : (string) $promotion->price_override_minor, 'bonus_units' => $promotion->bonus_units === null ? null : (string) $promotion->bonus_units, 'minimum_order_minor' => (string) $promotion->minimum_order_minor, 'maximum_discount_minor' => $promotion->maximum_discount_minor === null ? null : (string) $promotion->maximum_discount_minor, 'max_redemptions' => $promotion->max_redemptions, 'per_user_limit' => $promotion->per_user_limit, 'new_customer_only' => $promotion->new_customer_only, 'stackable' => $promotion->stackable, 'priority' => (int) $promotion->priority, 'starts_at' => $promotion->starts_at?->toAtomString(), 'ends_at' => $promotion->ends_at?->toAtomString(), 'enabled' => $promotion->enabled, 'package_ids' => $promotion->packages->pluck('id')->map(fn ($id): int => (int) $id)->values(), 'package_slugs' => $promotion->packages->pluck('slug')->values()];
    }
}
