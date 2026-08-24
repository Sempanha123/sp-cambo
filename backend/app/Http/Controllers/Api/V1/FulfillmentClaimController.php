<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FulfillmentClaimException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\FulfillmentClaim;
use App\Models\User;
use App\Services\FulfillmentClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FulfillmentClaimController extends Controller
{
    public function __construct(private readonly FulfillmentClaimService $claims) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $user->tenant;

        if (! $tenant) {
            return response()->json(['data' => []]);
        }

        $claims = FulfillmentClaim::query()
            ->where('tenant_id', $tenant->id)
            ->with(['orderItem', 'apiKey'])
            ->latest()
            ->get()
            ->map(fn (FulfillmentClaim $claim) => [
                'id' => (string) $claim->id,
                'order_id' => (string) $claim->order_id,
                'order_item_id' => (int) $claim->order_item_id,
                'status' => $claim->status,
                'expires_at' => $claim->expires_at?->toAtomString(),
                'package_name' => $claim->claim_snapshot['package_name']
                    ?? $claim->claim_snapshot['package_slug']
                    ?? 'Unknown',
                'allowed_model_aliases' => $claim->claim_snapshot['allowed_model_aliases'] ?? [],
                'model_alias' => ($claim->claim_snapshot['allowed_model_aliases'] ?? [])[0] ?? null,
                'created_at' => $claim->created_at->toAtomString(),
                'claimed_at' => $claim->claimed_at?->toAtomString(),
                'api_key_id' => $claim->api_key_id,
                'masked_key' => $claim->apiKey
                    ? $claim->apiKey->prefix.'…'.$claim->apiKey->last_four
                    : null,
            ]);

        return response()->json(['data' => $claims]);
    }

    public function claim(Request $request, FulfillmentClaim $claim): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $user->tenant;

        if (! $tenant || (string) $claim->tenant_id !== (string) $tenant->id) {
            abort(404);
        }

        if ($claim->status === 'CLAIMED') {
            $apiKey = $claim->apiKey;

            return response()->json([
                'message' => 'This claim has already been fulfilled.',
                'code' => 'already_claimed',
                'key_id' => $apiKey?->id,
                'masked_key' => $apiKey
                    ? $apiKey->prefix.'…'.$apiKey->last_four
                    : null,
                'expires_at' => $apiKey?->expires_at?->toAtomString(),
            ], 409);
        }

        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['NEW', 'EXISTING'])],
            'existing_api_key_id' => [
                'nullable',
                'string',
                'required_if:mode,EXISTING',
                Rule::exists('api_keys', 'id')->where(
                    fn ($query) => $query->where('user_id', $user->id)
                ),
            ],
        ]);

        $mode = $data['mode'] ?? (filled($data['existing_api_key_id'] ?? null) ? 'EXISTING' : 'NEW');
        $existing = null;

        if ($mode === 'EXISTING' && filled($data['existing_api_key_id'] ?? null)) {
            $existing = ApiKey::query()
                ->where('user_id', $user->id)
                ->findOrFail($data['existing_api_key_id']);
        }

        $idempotencyKey = $request->header('Idempotency-Key')
            ?? "claim:{$claim->id}:{$user->id}:{$mode}:".($existing?->id ?? 'new');

        try {
            $result = $this->claims->claim(
                $tenant,
                $claim,
                $idempotencyKey,
                $existing
            );
        } catch (FulfillmentClaimException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }

        $key = $result['key'];

        return response()->json([
            'data' => [
                'delivery_mode' => $result['reused'] ? 'EXISTING' : 'NEW',
                'api_key' => $result['secret'],
                'key_id' => (string) $key->id,
                'masked_key' => $key->prefix.'…'.$key->last_four,
                'reused_existing_key' => $result['reused'],
                'expires_at' => $key->expires_at?->toAtomString(),
                'models' => $key->modelAliases->pluck('public_alias')->values(),
                'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values(),
            ],
        ]);
    }
}
