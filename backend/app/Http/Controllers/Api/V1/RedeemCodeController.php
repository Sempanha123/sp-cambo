<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RedeemCodeException;
use App\Http\Controllers\Controller;
use App\Services\RedeemCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedeemCodeController extends Controller
{
    public function store(Request $request, RedeemCodeService $codes): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);

        try {
            $lot = $codes->redeem($request->user(), $input['code'], $input['idempotency_key']);
            return response()->json(['data' => [
                'entitlement_id' => $lot->id,
                'package_name' => $lot->package_name,
                'billing_mode' => $lot->billing_mode,
                'units' => (string) $lot->original_units,
                'expires_at' => $lot->expires_at?->toAtomString(),
                'allowed_model_aliases' => $lot->allowed_model_aliases,
            ]]);
        } catch (RedeemCodeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        }
    }
}
