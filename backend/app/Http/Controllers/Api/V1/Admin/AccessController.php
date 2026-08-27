<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $limit = $this->limit($request);
        $search = trim((string) $request->query('q', ''));

        $customers = User::query()
            ->with('roles:id,name')
            ->withCount(['apiKeys', 'entitlementLots', 'orders'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%');
            }))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'status' => $user->status instanceof AccountStatus ? $user->status->value : (string) $user->status,
                'roles' => $user->roles->pluck('name')->values(),
                'api_keys_count' => (int) $user->api_keys_count,
                'entitlements_count' => (int) $user->entitlement_lots_count,
                'orders_count' => (int) $user->orders_count,
                'created_at' => $user->created_at?->toAtomString(),
            ]);

        return response()->json(['data' => $customers]);
    }

    public function updateCustomerStatus(Request $request, User $customer, AuditService $audit): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (AccountStatus $status): string => $status->value, AccountStatus::cases()))],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        if ((int) $request->user()->id === (int) $customer->id && $input['status'] !== AccountStatus::Active->value) {
            throw ValidationException::withMessages(['status' => ['You cannot suspend or disable your own administrator account.']]);
        }

        $before = $customer->status instanceof AccountStatus ? $customer->status->value : (string) $customer->status;
        $customer->forceFill(['status' => $input['status']])->save();
        $audit->record($request->user(), 'customer.status_changed', 'user', $customer->id, trim($input['reason']), ['before' => $before, 'after' => $input['status']]);
        CustomerStateChanged::dispatch((int) $customer->id, 'account.status_changed', ['status' => $input['status']]);

        return response()->json(['data' => [
            'id' => (string) $customer->id,
            'status' => $input['status'],
        ]]);
    }

    public function keys(Request $request): JsonResponse
    {
        $limit = $this->limit($request);
        $status = strtoupper(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('q', ''));

        $keys = ApiKey::query()
            ->with(['user:id,name,email', 'modelAliases:id,public_alias,display_name'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('label', 'like', '%'.$search.'%')
                    ->orWhere('last_four', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
            }))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ApiKey $key): array => $this->keyResource($key));

        return response()->json(['data' => $keys]);
    }

    public function modelAliases(): JsonResponse
    {
        $aliases = ModelAlias::query()
            ->published()
            ->orderBy('display_name')
            ->orderBy('public_alias')
            ->get(['id', 'public_alias', 'display_name'])
            ->map(fn (ModelAlias $alias): array => [
                'id' => (int) $alias->id,
                'public_alias' => (string) $alias->public_alias,
                'display_name' => (string) $alias->display_name,
            ])
            ->values();

        return response()->json(['data' => $aliases]);
    }

    public function storeKey(Request $request, ApiKeySecretService $secrets, AuditService $audit): JsonResponse
    {
        $input = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'label' => ['required', 'string', 'max:100'],
            'allowed_model_alias_ids' => ['required', 'array', 'min:1', 'max:100'],
            'allowed_model_alias_ids.*' => ['integer', 'distinct', Rule::exists('model_aliases', 'id')],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'requests_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'tokens_per_minute' => ['nullable', 'integer', 'min:1'],
            'concurrency_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_request_bytes' => ['nullable', 'integer', 'min:1024'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $aliasIds = collect($input['allowed_model_alias_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $published = ModelAlias::query()->published()->whereIn('id', $aliasIds)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values();
        if ($published->all() !== $aliasIds->sort()->values()->all()) {
            throw ValidationException::withMessages(['allowed_model_alias_ids' => ['Every selected model must be currently publishable.']]);
        }

        $customer = User::query()->findOrFail((int) $input['user_id']);
        $attributes = collect($input)->only([
            'label', 'expires_at', 'requests_per_minute', 'tokens_per_minute', 'concurrency_limit', 'max_request_bytes', 'max_output_tokens',
        ])->all();
        $attributes['label'] = trim((string) $attributes['label']);

        $created = DB::transaction(function () use ($request, $customer, $attributes, $aliasIds, $secrets, $audit, $input): array {
            $created = $secrets->create($customer, $attributes, $aliasIds->all());
            $audit->record($request->user(), 'api_key.admin_issued', 'api_key', $created['key']->id, trim($input['reason']), [
                'customer_user_id' => $customer->id,
                'label' => $created['key']->label,
                'allowed_model_alias_ids' => $aliasIds->all(),
                'expires_at' => $created['key']->expires_at?->toAtomString(),
            ]);
            return $created;
        });

        CustomerStateChanged::dispatch((int) $customer->id, 'api_key.created', ['api_key_id' => $created['key']->id, 'status' => 'ACTIVE']);

        return response()->json(['data' => [
            'key' => $this->keyResource($created['key']->loadMissing('user', 'modelAliases')),
            // Plaintext is deliberately returned once and is never persisted.
            'secret' => $created['secret'],
        ]], 201);
    }

    public function updateKeyStatus(Request $request, ApiKey $apiKey, AuditService $audit): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'DISABLED', 'REVOKED'])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        if ($apiKey->status === 'REVOKED' && $input['status'] !== 'REVOKED') {
            return response()->json(['message' => 'A revoked API key cannot be reactivated.', 'code' => 'api_key_revoked'], 409);
        }

        $before = $apiKey->status;
        $changes = ['status' => $input['status']];
        if ($input['status'] === 'REVOKED') $changes['revoked_at'] = now();
        $apiKey->forceFill($changes)->save();
        $audit->record($request->user(), 'api_key.admin_status_changed', 'api_key', $apiKey->id, trim($input['reason']), [
            'customer_user_id' => $apiKey->user_id,
            'before' => $before,
            'after' => $input['status'],
        ]);
        CustomerStateChanged::dispatch((int) $apiKey->user_id, 'api_key.status_changed', ['api_key_id' => $apiKey->id, 'status' => $input['status']]);

        return response()->json(['data' => $this->keyResource($apiKey->fresh(['user', 'modelAliases']))]);
    }

    public function entitlements(Request $request): JsonResponse
    {
        $limit = $this->limit($request);
        $status = strtoupper(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('q', ''));

        $lots = EntitlementLot::query()
            ->with('user:id,name,email')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('package_name', 'like', '%'.$search.'%')
                    ->orWhere('source_type', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
            }))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (EntitlementLot $lot): array => [
                'id' => (string) $lot->id,
                'user' => $lot->user ? ['id' => (string) $lot->user->id, 'name' => $lot->user->name, 'email' => $lot->user->email] : null,
                'source_type' => $lot->source_type,
                'source_id' => $lot->source_id,
                'package_name' => $lot->package_name,
                'billing_mode' => $lot->billing_mode,
                'original_units' => (string) $lot->original_units,
                'remaining_units' => (string) $lot->remaining_units,
                'reserved_units' => (string) $lot->reserved_units,
                'unit_label' => $lot->unit_label,
                'currency' => $lot->currency,
                'currency_exponent' => $lot->currency_exponent,
                'allowed_model_aliases' => $lot->allowed_model_aliases ?? [],
                'status' => $lot->status,
                'activated_at' => $lot->activated_at?->toAtomString(),
                'expires_at' => $lot->expires_at?->toAtomString(),
                'created_at' => $lot->created_at?->toAtomString(),
            ]);

        return response()->json(['data' => $lots]);
    }

    public function expireEntitlement(Request $request, EntitlementLot $entitlementLot, AuditService $audit): JsonResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if ((int) $entitlementLot->reserved_units > 0) {
            return response()->json([
                'message' => 'This entitlement has active reserved usage. Reconcile or release those requests before expiring it.',
                'code' => 'entitlement_has_active_reservations',
            ], 409);
        }

        if ($entitlementLot->status !== 'EXPIRED') {
            $entitlementLot->forceFill(['status' => 'EXPIRED', 'expires_at' => now()])->save();
            $audit->record($request->user(), 'entitlement.admin_expired', 'entitlement_lot', $entitlementLot->id, trim($input['reason']), [
                'customer_user_id' => $entitlementLot->user_id,
                'remaining_units' => (string) $entitlementLot->remaining_units,
                'billing_mode' => $entitlementLot->billing_mode,
            ]);
            CustomerStateChanged::dispatch((int) $entitlementLot->user_id, 'entitlement.expired', ['entitlement_lot_id' => $entitlementLot->id]);
        }

        return response()->json(['data' => ['id' => (string) $entitlementLot->id, 'status' => 'EXPIRED', 'expires_at' => $entitlementLot->fresh()->expires_at?->toAtomString()]]);
    }

    public function usage(Request $request): JsonResponse
    {
        $limit = $this->limit($request);
        $state = strtoupper(trim((string) $request->query('state', '')));
        $search = trim((string) $request->query('q', ''));

        $rows = ApiRequestLog::query()
            ->with(['user:id,name,email', 'apiKey:id,prefix,last_four,label', 'usage', 'reservation.providerConnectionRevision.provider'])
            ->when($state !== '', fn ($query) => $query->where('state', $state))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('public_model', 'like', '%'.$search.'%')
                    ->orWhere('endpoint', 'like', '%'.$search.'%')
                    ->orWhere('id', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
            }))
            ->latest('started_at')
            ->limit($limit)
            ->get()
            ->map(function (ApiRequestLog $log): array {
                $usage = $log->usage;
                $reservation = $log->reservation;
                $snapshot = is_array($reservation?->billing_snapshot) ? $reservation->billing_snapshot : [];
                $revision = $reservation?->providerConnectionRevision;
                $finishedAt = $log->finished_at ?? $reservation?->reconciliation_requested_at;
                $durationMs = $log->duration_ms;
                if ($durationMs === null && $finishedAt !== null && $log->started_at !== null) {
                    $durationMs = max(0, $log->started_at->diffInMilliseconds($finishedAt));
                }
                return [
                    'request_id' => (string) $log->id,
                    'user' => $log->user ? ['id' => (string) $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email] : null,
                    'api_key' => $log->apiKey ? ['id' => (string) $log->apiKey->id, 'label' => $log->apiKey->label, 'masked' => $log->apiKey->prefix.'...'.$log->apiKey->last_four] : null,
                    'state' => $log->state,
                    'endpoint' => $log->endpoint,
                    'public_model' => $log->public_model,
                    'internal_model' => $snapshot['internal_model_id'] ?? null,
                    'provider' => $revision?->provider?->name,
                    'route_version' => $snapshot['route_version'] ?? $revision?->route_version,
                    'estimated_units' => $log->estimated_units === null ? null : (string) $log->estimated_units,
                    'input_tokens' => $usage === null ? null : (string) $usage->input_tokens,
                    'output_tokens' => $usage === null ? null : (string) $usage->output_tokens,
                    'cache_read_tokens' => $usage === null ? null : (string) $usage->cache_read_tokens,
                    'cache_write_tokens' => $usage === null ? null : (string) $usage->cache_write_tokens,
                    'reasoning_tokens' => $usage === null ? null : (string) $usage->reasoning_tokens,
                    'metered_units' => $usage === null ? null : (string) $usage->metered_units,
                    'credit_charge_minor' => $usage?->credit_charge_minor === null ? null : (string) $usage->credit_charge_minor,
                    'currency' => $usage?->currency,
                    'currency_exponent' => $usage?->currency_exponent,
                    'duration_ms' => $durationMs,
                    'error_code' => $log->error_code,
                    'started_at' => $log->started_at?->toAtomString(),
                    'finished_at' => $finishedAt?->toAtomString(),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    private function keyResource(ApiKey $key): array
    {
        return [
            'id' => (string) $key->id,
            'user' => $key->user ? ['id' => (string) $key->user->id, 'name' => $key->user->name, 'email' => $key->user->email] : null,
            'label' => $key->label,
            'masked_key' => $key->prefix.'...'.$key->last_four,
            'status' => $key->expires_at?->isPast() && $key->status === 'ACTIVE' ? 'EXPIRED' : $key->status,
            'stored_status' => $key->status,
            'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values(),
            'requests_per_minute' => $key->requests_per_minute,
            'tokens_per_minute' => $key->tokens_per_minute,
            'concurrency_limit' => $key->concurrency_limit,
            'max_request_bytes' => $key->max_request_bytes,
            'max_output_tokens' => $key->max_output_tokens,
            'last_used_at' => $key->last_used_at?->toAtomString(),
            'expires_at' => $key->expires_at?->toAtomString(),
            'revoked_at' => $key->revoked_at?->toAtomString(),
            'created_at' => $key->created_at?->toAtomString(),
        ];
    }

    private function limit(Request $request): int
    {
        return min(200, max(1, (int) $request->query('limit', 100)));
    }
}
