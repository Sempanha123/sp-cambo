<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\PlaygroundCredential;
use App\Models\User;
use App\Services\ApiKeySecretService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->user($request)->apiKeys()->with('modelAliases')->latest()->get()->map(fn (ApiKey $key) => $this->summary($key))->values()]);
    }

    public function store(Request $request, ApiKeySecretService $secrets): JsonResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:100'], 'allowed_model_aliases' => ['sometimes', 'array', 'max:100'], 'allowed_model_aliases.*' => ['string', 'distinct', Rule::exists('model_aliases', 'public_alias')], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $ids = $this->aliasIds($validated['allowed_model_aliases'] ?? []);
        $created = DB::transaction(fn () => $secrets->create($this->user($request), ['label' => trim($validated['label']), 'expires_at' => $validated['expires_at'] ?? null], $ids));
        CustomerStateChanged::dispatch((int) $request->user()->id, 'api_key.created', ['api_key_id' => $created['key']->id, 'status' => 'ACTIVE']);

        return response()->json(['data' => ['key' => $this->summary($created['key']), 'secret' => $created['secret']]], 201);
    }

    public function rotate(Request $request, ApiKey $apiKey, ApiKeySecretService $secrets): JsonResponse
    {
        $key = $this->owned($request, $apiKey);
        if ($key->status === 'REVOKED') {
            return response()->json(['message' => 'A revoked API key cannot be rotated.', 'code' => 'api_key_revoked'], 409);
        }
        $secret = DB::transaction(fn (): string => $secrets->rotate($key));
        CustomerStateChanged::dispatch((int) $key->user_id, 'api_key.rotated', ['api_key_id' => $key->id, 'status' => 'ACTIVE']);

        return response()->json(['data' => ['key' => $this->summary($key->fresh('modelAliases')), 'secret' => $secret]]);
    }

    public function updateStatus(Request $request, ApiKey $apiKey): JsonResponse
    {
        $key = $this->owned($request, $apiKey);
        $validated = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'DISABLED', 'REVOKED'])]]);
        if ($key->status === 'REVOKED' && $validated['status'] !== 'REVOKED') {
            return response()->json(['message' => 'A revoked API key cannot be reactivated.', 'code' => 'api_key_revoked'], 409);
        }
        $key->update(['status' => $validated['status']]);
        CustomerStateChanged::dispatch((int) $key->user_id, 'api_key.status_changed', ['api_key_id' => $key->id, 'status' => $key->status]);

        return response()->json(['data' => $this->summary($key->fresh('modelAliases'))]);
    }

    public function revoke(Request $request, ApiKey $apiKey): JsonResponse
    {
        $key = $this->owned($request, $apiKey);
        $key->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        CustomerStateChanged::dispatch((int) $key->user_id, 'api_key.revoked', ['api_key_id' => $key->id]);

        return response()->json(['data' => ['success' => true, 'revoked_at' => $key->fresh()->revoked_at?->toAtomString()]]);
    }

    public function check(Request $request): JsonResponse
    {
        $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:255']]);

        $digest = hash('sha256', $request->input('api_key'));
        $key = ApiKey::query()->with(['modelAliases', 'user', 'user.entitlementLots'])->where('lookup_digest', $digest)->first();

        if (! $key) {
            return response()->json(['message' => 'Invalid API key'], 404);
        }

        Log::channel('security')->info('API key check', ['key_id' => $key->id, 'ip' => $request->ip()]);

        // Report only the balance this particular credential is actually
        // allowed to spend. Daily Playground quota is isolated from customer keys.
        $isPlaygroundKey = PlaygroundCredential::query()
            ->where('user_id', $key->user_id)
            ->where('api_key_id', $key->id)
            ->exists();
        $creditRemaining = EntitlementLot::query()
            ->where('user_id', $key->user_id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('status', 'ACTIVE')
            ->when(
                $isPlaygroundKey,
                fn ($query) => $query->where('source_type', 'PLAYGROUND_DAILY'),
                fn ($query) => $query->where('source_type', '!=', 'PLAYGROUND_DAILY'),
            )
            ->get(['remaining_units', 'reserved_units'])
            ->sum(fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units));

        return response()->json(['data' => [
            'valid' => $key->status === 'ACTIVE' && ! $key->expires_at?->isPast(),
            'masked_key' => $key->prefix.'...'.$key->last_four,
            'status' => $key->status,
            'package' => $key->user->entitlementLots->first()?->package_name ?? 'N/A',
            'allowed_models' => $key->modelAliases->pluck('public_alias')->values(),
            'created_at' => $key->created_at->toAtomString(),
            'expires_at' => $key->expires_at?->toAtomString(),
            'last_used' => $key->last_used_at?->toAtomString(),
            'total_spend' => 0.00, // TODO: calculate total spend
            'quota_remaining' => $creditRemaining > 0 ? (int) $creditRemaining : null,
            'credit_remaining' => $creditRemaining > 0 ? (float) $creditRemaining : null,
        ]]);
    }

    public function status(Request $request, ApiKey $apiKey): JsonResponse
    {
        $key = $this->owned($request, $apiKey)->load('modelAliases');
        $status = $key->expires_at?->isPast() ? 'EXPIRED' : $key->status;

        // Return 404 if key is revoked (don't leak existence)
        if ($key->status === 'REVOKED') {
            return response()->json(['message' => 'API key not found'], 404);
        }

        return response()->json(['data' => ['valid' => $status === 'ACTIVE', 'status' => $status, 'expires_at' => $key->expires_at?->toAtomString(), 'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values(), 'token_quota_remaining' => null, 'credit_remaining' => null, 'limits' => $this->limits($key), 'service_status' => 'operational']]);
    }

    private function aliasIds(array $aliases): array
    {
        if ($aliases === []) {
            return ModelAlias::query()->published()->pluck('id')->all();
        }
        $models = ModelAlias::query()->published()->whereIn('public_alias', $aliases)->get(['id', 'public_alias']);
        if ($models->count() !== count($aliases)) {
            throw ValidationException::withMessages(['allowed_model_aliases' => ['Every model alias must be currently available.']]);
        }

        return $models->pluck('id')->all();
    }

    private function owned(Request $request, ApiKey $key): ApiKey
    {
        abort_unless((int) $key->user_id === (int) $this->user($request)->id, 404);

        return $key;
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }

    private function summary(ApiKey $key): array
    {
        $status = $key->expires_at?->isPast() ? 'EXPIRED' : $key->status;

        return ['id' => $key->id, 'label' => $key->label, 'prefix' => $key->prefix, 'last_four' => $key->last_four, 'status' => $status, 'created_at' => $key->created_at->toAtomString(), 'last_used_at' => $key->last_used_at?->toAtomString(), 'expires_at' => $key->expires_at?->toAtomString(), 'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values(), 'limits' => $this->limits($key), 'bound_entitlement_id' => null];
    }

    private function limits(ApiKey $key): array
    {
        return ['requests_per_minute' => $key->requests_per_minute, 'tokens_per_minute' => $key->tokens_per_minute, 'concurrency' => $key->concurrency_limit, 'max_request_bytes' => $key->max_request_bytes, 'max_output_tokens' => $key->max_output_tokens];
    }
}
