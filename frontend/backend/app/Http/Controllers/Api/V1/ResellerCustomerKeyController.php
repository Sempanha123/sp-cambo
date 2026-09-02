<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ModelAlias;
use App\Models\ResellerCustomer;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResellerCustomerKeyController extends Controller
{
    public function index(Request $request, string $resellerCustomer): JsonResponse
    {
        $customer = $this->customer($request, $resellerCustomer, false);

        return response()->json(['data' => $customer->apiKeys()->with('modelAliases')->latest()->get()->map(fn (ApiKey $key) => $this->summary($key))]);
    }

    public function store(Request $request, string $resellerCustomer, ApiKeySecretService $secrets, AuditService $audit): JsonResponse
    {
        $customer = $this->customer($request, $resellerCustomer);
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'allowed_model_aliases' => ['sometimes', 'array', 'max:100'], 'allowed_model_aliases.*' => ['string', 'distinct', Rule::exists('model_aliases', 'public_alias')], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $aliases = $data['allowed_model_aliases'] ?? [];
        $models = $aliases === [] ? ModelAlias::query()->published()->get(['id', 'public_alias']) : ModelAlias::query()->published()->whereIn('public_alias', $aliases)->get(['id', 'public_alias']);
        if ($aliases !== [] && $models->count() !== count($aliases)) {
            throw ValidationException::withMessages(['allowed_model_aliases' => ['Every model alias must be currently available.']]);
        }
        $created = DB::transaction(function () use ($request, $customer, $data, $models, $secrets, $audit): array {
            $created = $secrets->create($customer, ['label' => trim($data['label']), 'expires_at' => $data['expires_at'] ?? null], $models->pluck('id')->all());
            $audit->record($request->user(), 'reseller_customer_api_key.created', 'api_key', $created['key']->id, 'Reseller issued managed customer inference key.', ['customer_user_id' => $customer->id]);

            return $created;
        });

        return response()->json(['data' => ['key' => $this->summary($created['key']), 'secret' => $created['secret']]], 201);
    }

    public function revoke(Request $request, string $resellerCustomer, string $apiKey, AuditService $audit): JsonResponse
    {
        $customer = $this->customer($request, $resellerCustomer, false);
        $key = $customer->apiKeys()->findOrFail($apiKey);
        if ($key->status !== 'REVOKED') {
            $key->update(['status' => 'REVOKED']);
            $audit->record($request->user(), 'reseller_customer_api_key.revoked', 'api_key', $key->id, 'Reseller revoked managed customer inference key.', ['customer_user_id' => $customer->id]);
        }

        return response()->json(['data' => $this->summary($key->fresh('modelAliases'))]);
    }

    private function customer(Request $request, string $managedId, bool $activeOnly = true): User
    {
        $managed = ResellerCustomer::query()
            ->where('reseller_user_id', $request->user()->id)
            ->when($activeOnly, fn ($query) => $query->where('status', 'ACTIVE'))
            ->findOrFail($managedId);

        return User::query()->findOrFail($managed->customer_user_id);
    }

    private function summary(ApiKey $key): array
    {
        $key->loadMissing('modelAliases');

        return ['id' => $key->id, 'label' => $key->label, 'prefix' => $key->prefix, 'last_four' => $key->last_four, 'status' => $key->expires_at?->isPast() ? 'EXPIRED' : $key->status, 'created_at' => $key->created_at->toAtomString(), 'last_used_at' => $key->last_used_at?->toAtomString(), 'expires_at' => $key->expires_at?->toAtomString(), 'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values()];
    }
}
