<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ResellerManagementKey;
use App\Services\AuditService;
use App\Services\ResellerManagementKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResellerManagementKeyController extends Controller
{
    private const SCOPES = ['customers:read', 'customers:write', 'keys:read', 'keys:write', 'allocations:read', 'allocations:write', 'usage:read'];

    public function index(Request $request): JsonResponse
    {
        $keys = ResellerManagementKey::query()->where('user_id', $request->user()->id)->latest()->get();

        return response()->json(['data' => $keys->map(fn ($key) => $this->resource($key))]);
    }

    public function store(Request $request, ResellerManagementKeyService $keys, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string', 'distinct', Rule::in(self::SCOPES)], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $created = $keys->create($request->user(), $data['label'], $data['scopes'], $data['expires_at'] ?? null);
        $audit->record($request->user(), 'reseller_management_key.created', 'reseller_management_key', $created['key']->id, 'Reseller created scoped management key.', ['scopes' => $created['key']->scopes]);

        return response()->json(['data' => ['key' => $this->resource($created['key']), 'secret' => $created['secret']]], 201);
    }

    public function revoke(Request $request, string $managementKey, AuditService $audit): JsonResponse
    {
        $key = ResellerManagementKey::query()->where('user_id', $request->user()->id)->findOrFail($managementKey);
        if ($key->status !== 'REVOKED') {
            $key->update(['status' => 'REVOKED']);
            $audit->record($request->user(), 'reseller_management_key.revoked', 'reseller_management_key', $key->id, 'Reseller revoked management key.');
        }

        return response()->json(['data' => $this->resource($key->fresh())]);
    }

    private function resource(ResellerManagementKey $key): array
    {
        return ['id' => $key->id, 'label' => $key->label, 'prefix' => $key->prefix, 'last_four' => $key->last_four, 'scopes' => $key->scopes, 'status' => $key->status, 'last_used_at' => $key->last_used_at?->toAtomString(), 'expires_at' => $key->expires_at?->toAtomString(), 'created_at' => $key->created_at->toAtomString()];
    }
}
