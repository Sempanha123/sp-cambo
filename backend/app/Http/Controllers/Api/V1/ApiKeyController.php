<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CustomerStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\PlaygroundCredential;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\AuditService;
use App\Support\AccessAllocationSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->user($request)->apiKeys()
            ->whereNotIn('api_keys.id', PlaygroundCredential::query()->select('api_key_id'))
            ->with('modelAliases')
            ->latest()
            ->get()
            ->map(fn (ApiKey $key) => $this->summary($key))
            ->values()]);
    }

    public function store(Request $request, ApiKeySecretService $secrets): JsonResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:100'], 'allowed_model_aliases' => ['sometimes', 'array', 'max:100'], 'allowed_model_aliases.*' => ['string', 'distinct', Rule::exists('model_aliases', 'public_alias')], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $user = $this->user($request);
        $ids = $this->aliasIds($user, $validated['allowed_model_aliases'] ?? []);
        $created = DB::transaction(fn () => $secrets->create($user, ['label' => trim($validated['label']), 'expires_at' => $validated['expires_at'] ?? null], $ids));
        CustomerStateChanged::dispatch((int) $request->user()->id, 'api_key.created', ['api_key_id' => $created['key']->id, 'status' => 'ACTIVE']);

        return response()->json(['data' => ['key' => $this->summary($created['key']), 'secret' => $created['secret']]], 201);
    }

    public function reveal(Request $request, ApiKey $apiKey, ApiKeySecretService $secrets, AuditService $audit): JsonResponse
    {
        $key = $this->owned($request, $apiKey);

        if ($key->status === 'REVOKED') {
            return response()->json([
                'message' => 'A revoked API key cannot be revealed.',
                'code' => 'api_key_revoked',
            ], 409);
        }

        if ($this->isSystemPlaygroundKey($key)) {
            return response()->json([
                'message' => 'System Playground credentials cannot be revealed.',
                'code' => 'api_key_not_revealable',
            ], 409);
        }

        $secret = $secrets->reveal($key);
        if ($secret === null) {
            return response()->json([
                'message' => 'This older API key was created before secure re-copy support. Rotate it once to make the new secret re-copyable.',
                'code' => 'api_key_secret_unavailable',
            ], 409);
        }

        $audit->record(
            $request->user(),
            'api_key.secret_revealed',
            'api_key',
            $key->id,
            'Customer re-copied their own API key secret.'
        );

        return response()->json(['data' => [
            'key' => $this->summary($key->fresh('modelAliases')),
            'secret' => $secret,
        ]]);
    }

    public function rotate(Request $request, ApiKey $apiKey, ApiKeySecretService $secrets): JsonResponse
    {
        $key = $this->owned($request, $apiKey);
        if ($this->isSystemPlaygroundKey($key)) {
            return response()->json(['message' => 'System Playground credentials are managed internally.', 'code' => 'api_key_system_managed'], 409);
        }
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
        if ($this->isSystemPlaygroundKey($key)) {
            return response()->json(['message' => 'System Playground credentials are managed internally.', 'code' => 'api_key_system_managed'], 409);
        }
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
        if ($this->isSystemPlaygroundKey($key)) {
            return response()->json(['message' => 'System Playground credentials are managed internally.', 'code' => 'api_key_system_managed'], 409);
        }
        $key->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        CustomerStateChanged::dispatch((int) $key->user_id, 'api_key.revoked', ['api_key_id' => $key->id]);

        return response()->json(['data' => ['success' => true, 'revoked_at' => $key->fresh()->revoked_at?->toAtomString()]]);
    }

    public function check(Request $request, ApiKeySecretService $secrets): JsonResponse
    {
        $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:255']]);

        $digest = $secrets->digest((string) $request->input('api_key'));
        $key = ApiKey::query()->with(['modelAliases', 'user'])->where('lookup_digest', $digest)->first();

        if (! $key) {
            return response()->json(['message' => 'Invalid API key'], 404);
        }

        Log::channel('security')->info('API key check', ['key_id' => $key->id, 'ip' => $request->ip()]);

        // A public key check proves possession of the secret, but it must still
        // report only the entitlement pool this credential can actually spend.
        // Playground daily lots are strictly isolated from normal customer keys.
        $isPlaygroundKey = PlaygroundCredential::query()
            ->where('user_id', $key->user_id)
            ->where('api_key_id', $key->id)
            ->exists();

        $eligibleLots = $this->eligibleLotsForKey($key, $isPlaygroundKey);

        $tokenLots = $eligibleLots->where('billing_mode', 'TOKEN_QUOTA');
        $creditLots = $eligibleLots->where('billing_mode', 'CREDIT_BALANCE');

        $quotaRemaining = $tokenLots->isEmpty()
            ? null
            : (string) $tokenLots->sum(fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units));
        $creditBalances = $this->moneyGroups(
            $creditLots->map(fn (EntitlementLot $lot): array => [
                'minor' => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units),
                'currency' => $lot->currency ?? 'USD',
                'exponent' => (int) ($lot->currency_exponent ?? 6),
            ])->all()
        );

        $usageTotals = UsageRecord::query()
            ->where('user_id', $key->user_id)
            ->where('api_key_id', $key->id)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->first();

        $spendRows = UsageRecord::query()
            ->where('user_id', $key->user_id)
            ->where('api_key_id', $key->id)
            ->whereNotNull('credit_charge_minor')
            ->selectRaw("COALESCE(currency, 'USD') as currency, COALESCE(currency_exponent, 2) as exponent, SUM(credit_charge_minor) as minor")
            ->groupBy('currency', 'currency_exponent')
            ->get()
            ->map(fn ($row): array => [
                'minor' => (int) $row->minor,
                'currency' => (string) $row->currency,
                'exponent' => (int) $row->exponent,
            ])
            ->all();
        $spend = $this->moneyGroups($spendRows);

        $recentRequests = ApiRequestLog::query()
            ->where('user_id', $key->user_id)
            ->where('api_key_id', $key->id)
            ->with(['usage', 'reservation.providerConnectionRevision.provider'])
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(function (ApiRequestLog $log): array {
                $usage = $log->usage;
                $reservation = $log->reservation;
                $snapshot = is_array($reservation?->billing_snapshot) ? $reservation->billing_snapshot : [];
                $revision = $reservation?->providerConnectionRevision;
                $provider = $revision?->provider;
                $charge = $usage?->credit_charge_minor === null ? null : [
                    'minor' => (string) $usage->credit_charge_minor,
                    'currency' => (string) ($usage->currency ?? 'USD'),
                    'exponent' => (int) ($usage->currency_exponent ?? 2),
                ];
                $finishedAt = $log->finished_at ?? $reservation?->reconciliation_requested_at;
                $durationMs = $log->duration_ms;
                if ($durationMs === null && $finishedAt !== null && $log->started_at !== null) {
                    $durationMs = max(0, $log->started_at->diffInMilliseconds($finishedAt));
                }

                return [
                    'request_id' => $log->id,
                    'time' => $log->started_at->toAtomString(),
                    'finished_at' => $finishedAt?->toAtomString(),
                    'endpoint' => $log->endpoint,
                    'model' => $log->public_model,
                    'internal_model' => $snapshot['internal_model_id'] ?? null,
                    'provider' => $provider?->name,
                    'provider_slug' => $provider?->slug,
                    'route_version' => $snapshot['route_version'] ?? $revision?->route_version,
                    'state' => strtolower($log->state),
                    'status' => match ($log->state) {
                        'SETTLED' => 'success',
                        'FAILED', 'RELEASED' => 'error',
                        default => 'pending',
                    },
                    'duration_ms' => $durationMs,
                    'input_tokens' => $usage === null ? null : (string) $usage->input_tokens,
                    'output_tokens' => $usage === null ? null : (string) $usage->output_tokens,
                    'total_tokens' => $usage === null ? null : (string) $usage->total_tokens,
                    'reserved_units' => $usage === null && $log->estimated_units !== null ? (string) $log->estimated_units : null,
                    'charge' => $charge,
                    'error_code' => $log->error_code,
                ];
            })
            ->values();
        $activeRequests = $recentRequests->whereIn('state', ['reserved', 'connecting', 'streaming'])->count();

        $status = $key->expires_at?->isPast() ? 'EXPIRED' : $key->status;
        $packages = $eligibleLots->pluck('package_name')->filter()->unique()->values();

        return response()->json(['data' => [
            'valid' => $status === 'ACTIVE',
            'masked_key' => $key->prefix.'...'.$key->last_four,
            'status' => $status,
            'package' => $packages->isEmpty() ? null : $packages->implode(', '),
            'allowed_models' => $key->modelAliases->pluck('public_alias')->values(),
            'created_at' => $key->created_at->toAtomString(),
            'expires_at' => $key->expires_at?->toAtomString(),
            'last_used' => $key->last_used_at?->toAtomString(),
            'tokens_used' => [
                'input' => (string) ($usageTotals?->input_tokens ?? 0),
                'output' => (string) ($usageTotals?->output_tokens ?? 0),
                'total' => (string) ($usageTotals?->total_tokens ?? 0),
            ],
            // Money is never converted through float arithmetic. If historical
            // records contain more than one currency/scale, the single-value
            // compatibility field is null and the exact grouped values remain.
            'total_spend' => count($spend) === 1 ? $spend[0] : null,
            'total_spend_by_currency' => $spend,
            'quota_remaining' => $quotaRemaining,
            'credit_remaining' => count($creditBalances) === 1 ? $creditBalances[0] : null,
            'credit_balances' => $creditBalances,
            'recent_requests' => $recentRequests,
            'active_requests' => $activeRequests,
            'server_time' => now()->toAtomString(),
        ]]);
    }

    private function eligibleLotsForKey(ApiKey $key, ?bool $isPlaygroundKey = null)
    {
        $key->loadMissing('modelAliases');
        $allowedAliases = array_fill_keys(
            $key->modelAliases->pluck('public_alias')->filter(static fn ($value): bool => is_string($value))->values()->all(),
            true
        );
        if ($allowedAliases === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $isPlaygroundKey ??= PlaygroundCredential::query()
            ->where('user_id', $key->user_id)
            ->where('api_key_id', $key->id)
            ->exists();

        // Customer-facing details should not depend on JSON_CONTAINS or a complex
        // access-scope OR predicate merely to render a page. Pull this user's active
        // non-expired lots with indexed predicates, then perform the final scope and
        // model matching in PHP. ReservationService remains the authoritative atomic
        // enforcement path for actual spend.
        $rows = EntitlementLot::query()
            ->where('user_id', $key->user_id)
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'billing_mode', 'original_units', 'remaining_units', 'reserved_units', 'unit_label', 'currency', 'currency_exponent', 'package_name', 'source_type', 'allowed_model_aliases', 'activated_at', 'expires_at', 'access_scope', 'bound_api_key_id', 'fulfillment_claim_id']);

        $filtered = $rows->filter(function (EntitlementLot $lot) use ($key, $isPlaygroundKey, $allowedAliases): bool {
            $scope = strtoupper((string) ($lot->access_scope ?: 'ACCOUNT'));

            if ($isPlaygroundKey) {
                if ($lot->source_type !== 'PLAYGROUND_DAILY' || $scope !== 'PLAYGROUND') {
                    return false;
                }
            } else {
                if ($lot->source_type === 'PLAYGROUND_DAILY') {
                    return false;
                }
                $legacy = $scope === 'ACCOUNT';
                $dedicated = $scope === 'API_KEY' && (string) $lot->bound_api_key_id === (string) $key->id;
                if (! $legacy && ! $dedicated) {
                    return false;
                }
            }

            foreach (is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [] as $alias) {
                if (is_string($alias) && isset($allowedAliases[$alias])) {
                    return true;
                }
            }

            return false;
        })->values();

        return new \Illuminate\Database\Eloquent\Collection($filtered->all());
    }

    /** @param array<int, array{minor:int,currency:string,exponent:int}> $rows */
    private function moneyGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $currency = strtoupper(trim($row['currency']));
            $exponent = max(0, min(6, (int) $row['exponent']));
            $group = $currency.':'.$exponent;
            $groups[$group] ??= ['minor' => 0, 'currency' => $currency, 'exponent' => $exponent];
            $groups[$group]['minor'] += max(0, (int) $row['minor']);
        }

        return collect($groups)
            ->sortBy(fn (array $amount): string => $amount['currency'].':'.str_pad((string) $amount['exponent'], 2, '0', STR_PAD_LEFT))
            ->map(fn (array $amount): array => [
                'minor' => (string) $amount['minor'],
                'currency' => $amount['currency'],
                'exponent' => $amount['exponent'],
            ])
            ->values()
            ->all();
    }

    public function show(Request $request, ApiKey $apiKey): JsonResponse
    {
        if (! AccessAllocationSchema::ready()) {
            return response()->json(AccessAllocationSchema::errorPayload(), 503);
        }

        // The key identity/scope card must remain available even if one legacy
        // entitlement row cannot be summarized. This keeps navigation usable and
        // isolates historical funding-data defects from the credential itself.
        try {
            $key = $this->owned($request, $apiKey)->load('modelAliases');
            if ($this->isSystemPlaygroundKey($key)) {
                abort(404);
            }
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = 'keydiag_'.Str::lower(Str::random(12));
            Log::error('API key identity load failed.', [
                'diagnostic_id' => $requestId,
                'user_id' => (int) $request->user()->id,
                'api_key_id' => (string) $apiKey->id,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 1000),
            ]);

            return response()->json([
                'message' => "API key details could not be loaded. Diagnostic reference: {$requestId}",
                'code' => 'api_key_details_load_failed',
                'request_id' => $requestId,
            ], 500);
        }

        $fundingStatus = 'ready';
        $fundingMessage = null;
        $fundingDiagnosticId = null;
        $lots = new \Illuminate\Database\Eloquent\Collection;
        $tokenRemaining = 0;
        $creditBalances = [];
        $funding = collect();

        try {
            $lots = $this->eligibleLotsForKey($key);
            $spendable = static fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units);
            $tokenRemaining = $lots->where('billing_mode', 'TOKEN_QUOTA')->sum($spendable);
            $creditBalances = $this->moneyGroups(
                $lots->where('billing_mode', 'CREDIT_BALANCE')->map(fn (EntitlementLot $lot): array => [
                    'minor' => $spendable($lot),
                    'currency' => $lot->currency ?? 'USD',
                    'exponent' => (int) ($lot->currency_exponent ?? 6),
                ])->all()
            );

            $funding = $lots->map(function (EntitlementLot $lot) use ($spendable, $key): array {
                $expiresAt = $lot->expires_at;
                return [
                    'id' => (string) $lot->id,
                    'package_name' => (string) ($lot->package_name ?: 'Purchased access'),
                    'source' => (string) $lot->source_type,
                    'access_scope' => (string) ($lot->access_scope ?? 'ACCOUNT'),
                    'dedicated_to_this_key' => ($lot->access_scope ?? 'ACCOUNT') === 'API_KEY' && (string) $lot->bound_api_key_id === (string) $key->id,
                    'billing_mode' => (string) $lot->billing_mode,
                    'original_units' => (string) $lot->original_units,
                    'remaining_units' => (string) $spendable($lot),
                    'reserved_units' => (string) $lot->reserved_units,
                    'unit_label' => (string) ($lot->unit_label ?: 'units'),
                    'currency' => $lot->currency,
                    'currency_exponent' => $lot->currency_exponent,
                    'allowed_model_aliases' => array_values(array_filter(
                        is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [],
                        static fn ($value): bool => is_string($value) && trim($value) !== ''
                    )),
                    'activated_at' => $lot->activated_at?->toAtomString(),
                    'expires_at' => $expiresAt?->toAtomString(),
                    'days_remaining' => $expiresAt === null ? null : max(0, (int) ceil(now()->diffInSeconds($expiresAt, false) / 86400)),
                ];
            })->values();
        } catch (Throwable $exception) {
            $fundingStatus = 'unavailable';
            $fundingMessage = 'The key is available, but SP Cambo could not summarize one or more historical balance records. Your accounting data was not changed.';
            $fundingDiagnosticId = 'keyfund_'.Str::lower(Str::random(12));
            Log::error('API key funding summary load failed.', [
                'diagnostic_id' => $fundingDiagnosticId,
                'user_id' => (int) $request->user()->id,
                'api_key_id' => (string) $key->id,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 1000),
            ]);
        }

        return response()->json(['data' => [
            'key' => $this->summary($key),
            'balance_source' => $fundingStatus !== 'ready' || $lots->isEmpty()
                ? 'no_spendable_balance'
                : ($lots->contains(fn (EntitlementLot $lot): bool => ($lot->access_scope ?? 'ACCOUNT') === 'API_KEY')
                    ? 'dedicated_and_legacy_entitlements'
                    : 'legacy_account_entitlements'),
            'token_quota_remaining' => (string) $tokenRemaining,
            'credit_balances' => $creditBalances,
            'funding' => $funding,
            'funding_status' => $fundingStatus,
            'funding_message' => $fundingMessage,
            'funding_diagnostic_id' => $fundingDiagnosticId,
            'server_time' => now()->toAtomString(),
        ]]);
    }

    public function status(Request $request, ApiKey $apiKey): JsonResponse
    {
        if (! AccessAllocationSchema::ready()) {
            return response()->json(AccessAllocationSchema::errorPayload(), 503);
        }

        $key = $this->owned($request, $apiKey)->load('modelAliases');
        $status = $key->expires_at?->isPast() ? 'EXPIRED' : $key->status;

        // Return 404 if key is revoked (don't leak existence).
        if ($key->status === 'REVOKED') {
            return response()->json(['message' => 'API key not found'], 404);
        }

        $eligibleLots = $this->eligibleLotsForKey($key);
        $tokenLots = $eligibleLots->where('billing_mode', 'TOKEN_QUOTA');
        $creditLots = $eligibleLots->where('billing_mode', 'CREDIT_BALANCE');
        $tokenRemaining = $tokenLots->isEmpty()
            ? null
            : (string) $tokenLots->sum(fn (EntitlementLot $lot): int => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units));
        $creditBalances = $this->moneyGroups(
            $creditLots->map(fn (EntitlementLot $lot): array => [
                'minor' => max(0, (int) $lot->remaining_units - (int) $lot->reserved_units),
                'currency' => $lot->currency ?? 'USD',
                'exponent' => (int) ($lot->currency_exponent ?? 6),
            ])->all()
        );

        return response()->json(['data' => [
            'valid' => $status === 'ACTIVE',
            'status' => $status,
            'expires_at' => $key->expires_at?->toAtomString(),
            'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values(),
            'token_quota_remaining' => $tokenRemaining,
            'credit_remaining' => count($creditBalances) === 1 ? $creditBalances[0] : null,
            'credit_balances' => $creditBalances,
            'limits' => $this->limits($key),
            'service_status' => 'operational',
        ]]);
    }

    private function aliasIds(User $user, array $aliases): array
    {
        if ($aliases === []) {
            $entitledAliases = EntitlementLot::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('source_type', '!=', 'PLAYGROUND_DAILY')
                ->where(function ($access): void {
                    $access->whereNull('access_scope')->orWhere('access_scope', 'ACCOUNT');
                })
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get(['allowed_model_aliases'])
                ->flatMap(fn (EntitlementLot $lot): array => is_array($lot->allowed_model_aliases) ? $lot->allowed_model_aliases : [])
                ->filter(static fn ($value): bool => is_string($value))
                ->unique()
                ->values()
                ->all();

            return $entitledAliases === []
                ? []
                : ModelAlias::query()->published()->whereIn('public_alias', $entitledAliases)->pluck('id')->all();
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

    private function isSystemPlaygroundKey(ApiKey $key): bool
    {
        return PlaygroundCredential::query()->where('api_key_id', $key->id)->exists();
    }

    private function summary(ApiKey $key): array
    {
        $status = $key->expires_at?->isPast() ? 'EXPIRED' : $key->status;

        return ['id' => $key->id, 'label' => $key->label, 'prefix' => $key->prefix, 'last_four' => $key->last_four, 'status' => $status, 'created_at' => $key->created_at->toAtomString(), 'last_used_at' => $key->last_used_at?->toAtomString(), 'expires_at' => $key->expires_at?->toAtomString(), 'allowed_model_aliases' => $key->modelAliases->pluck('public_alias')->values(), 'limits' => $this->limits($key), 'bound_entitlement_id' => null, 'secret_recopy_available' => is_string($key->secret_ciphertext) && $key->secret_ciphertext !== ''];
    }

    private function limits(ApiKey $key): array
    {
        return ['requests_per_minute' => $key->requests_per_minute, 'tokens_per_minute' => $key->tokens_per_minute, 'concurrency' => $key->concurrency_limit, 'max_request_bytes' => $key->max_request_bytes, 'max_output_tokens' => $key->max_output_tokens];
    }
}
