<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionPreviewController extends Controller
{
    public function __invoke(Request $request, PromotionService $promotions): JsonResponse
    {
        $data = $request->validate(['package_slug' => ['required', 'string', 'max:100'], 'quantity' => ['sometimes', 'integer', 'between:1,100'], 'promotion_code' => ['required', 'string', 'max:50']]);
        $package = Package::query()->published()->where('slug', $data['package_slug'])->firstOrFail();
        $quantity = $data['quantity'] ?? 1;
        $subtotal = (int) $package->price_minor * $quantity;
        $result = $promotions->evaluate($data['promotion_code'], $package, $request->user(), $subtotal);
        $money = fn (int $minor): array => ['minor' => (string) $minor, 'currency' => $package->currency, 'exponent' => (int) $package->currency_exponent];

        return response()->json(['data' => ['code' => $result['code'], 'label' => $result['label'], 'valid' => $result['valid'], 'reason' => $result['reason'], 'subtotal' => $money($subtotal), 'discount_total' => $money($result['discount_minor']), 'total' => $money($result['total_minor']), 'bonus_units' => $result['bonus_units'] === null ? null : (string) $result['bonus_units']]]);
    }
}
