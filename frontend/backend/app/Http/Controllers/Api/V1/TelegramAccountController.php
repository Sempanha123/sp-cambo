<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\TelegramCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = TelegramAccount::query()->where('user_id', $request->user()->id)->whereNull('revoked_at')->first();
        return response()->json(['data' => $account ? [
            'linked' => true,
            'username' => $account->username,
            'linked_at' => $account->linked_at?->toAtomString(),
        ] : ['linked' => false, 'username' => null, 'linked_at' => null]]);
    }

    public function token(Request $request, TelegramCommerceService $telegram): JsonResponse
    {
        return response()->json(['data' => $telegram->createLinkToken($request->user())], 201);
    }

    public function destroy(Request $request, TelegramCommerceService $telegram): JsonResponse
    {
        $telegram->unlink($request->user());

        return response()->json(['data' => ['linked' => false]]);
    }
}
