<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Enums\AccountStatus;
use App\Events\CustomerStateChanged;
use App\Exceptions\InferenceAccessException;
use App\Exceptions\InferenceIdempotencyException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\CreditLedger;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use App\Models\UsageRecord;
use App\Services\ApiKeySecretService;
use App\Services\InferenceBillingService;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GatewayBillingController extends Controller
{
    public function inspect(Request $request, ApiKeySecretService $secrets): JsonResponse
    {
        $input = $request->validate(['customer_key' => ['required', 'string', 'max:128']]);
        $key = $this->activeKey($input['customer_key'], $secrets);
        $key->load(['modelAliases' => fn ($query) => $query->published()->orderBy('public_alias')]);
        $lots = EntitlementLot::query()->where('user_id', $key->user_id)->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get();

        return response()->json(['data' => [
            'key_id' => $key->id,
            'status' => 'ACTIVE',
            'expires_at' => $key->expires_at?->toAtomString(),
            'allowed_models' => $key->modelAliases->map(fn (ModelAlias $alias): array => [
                'id' => $alias->public_alias,
                'display_name' => $alias->display_name,
                'capabilities' => $alias->capabilities,
                'limits' => $alias->limits,
            ])->values(),
            'limits' => $this->limits($key),
            'balances' => [
                'token_quota_remaining' => (string) $lots->where('billing_mode', 'TOKEN_QUOTA')->sum(fn (EntitlementLot $lot): int => max(0, $lot->remaining_units - $lot->reserved_units)),
                'credit_remaining' => (string) $lots->where('billing_mode', 'CREDIT_BALANCE')->sum(fn (EntitlementLot $lot): int => max(0, $lot->remaining_units - $lot->reserved_units)),
                'version' => (int) CreditLedger::query()->where('user_id', $key->user_id)->max('id'),
            ],
            'service_status' => 'operational',
        ]]);
    }

    public function preflight(Request $request, ApiKeySecretService $secrets, InferenceBillingService $billing): JsonResponse
    {
        $input = $request->validate([
            'customer_key' => ['required', 'string', 'max:128'],
            'public_model' => ['required', 'string', 'max:100'],
            'estimated_input_tokens' => ['required', 'integer', 'min:0'],
            'requested_max_output_tokens' => ['required', 'integer', 'min:0'],
            'request_bytes' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'string', 'max:191'],
            'request_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'endpoint' => ['required', 'string', 'in:/v1/messages,/v1/messages/count_tokens,/v1/responses,/v1/chat/completions'],
        ]);
        $key = $this->activeKey($input['customer_key'], $secrets);
        if ($key->max_request_bytes !== null && (int) $input['request_bytes'] > (int) $key->max_request_bytes) {
            return $this->error('request_too_large', 'The request exceeds the API key size limit.', 413);
        }
        $alias = $key->modelAliases->firstWhere('public_alias', $input['public_model']);
        if (! $alias || ! ModelAlias::query()->published()->whereKey($alias->id)->exists()) {
            return $this->error('model_not_allowed', 'The model is not allowed for this key.', 403);
        }
        if (! $this->supports($alias, $input['endpoint'])) {
            return $this->error('model_unavailable', 'The model does not support this inference protocol.', 400);
        }
        $result = DB::transaction(function () use ($billing, $key, $alias, $input): array {
            $result = $billing->preflight(
                $key->user,
                $key,
                $alias->loadMissing('pricing'),
                (int) $input['estimated_input_tokens'],
                $input['endpoint'] === '/v1/messages/count_tokens' ? 0 : (int) $input['requested_max_output_tokens'],
                $input['request_id'],
                $input['request_fingerprint'],
            );
            $reservation = $result['reservation'];
            ApiRequestLog::query()->firstOrCreate(['reservation_id' => $reservation->id], [
                'user_id' => $key->user_id,
                'api_key_id' => $key->id,
                'public_model' => $alias->public_alias,
                'endpoint' => $input['endpoint'],
                'state' => 'RESERVED',
                'estimated_units' => $reservation->reserved_units,
                'started_at' => now(),
            ]);

            return $result;
        });
        $reservation = $result['reservation'];
        $key->forceFill(['last_used_at' => now()])->save();
        CustomerStateChanged::dispatch((int) $key->user_id, 'api_request.reserved', [
            'reservation_id' => $reservation->id,
            'public_model' => $alias->public_alias,
            'state' => 'reserved',
            'estimated_units' => (string) $reservation->reserved_units,
        ]);

        $routeRevision = ProviderConnectionRevision::query()
            ->whereKey($result['route_revision_id'])
            ->where('lifecycle_status', ProviderConnectionRevision::STATUS_READY)
            ->firstOrFail();

        return response()->json(['data' => [
            'reservation_id' => $reservation->id,
            'public_model' => $alias->public_alias,
            'internal_model' => $result['internal_model_id'],
            'reserved_units' => (string) $reservation->reserved_units,
            'billing_mode' => $result['billing_mode'],
            'max_output_tokens' => $result['hard_max_output_tokens'],
            'correlation_id' => $input['request_id'],
            'route_revision_id' => $result['route_revision_id'],
            'route_version' => $result['route_version'],
            // Private routing material is returned only to the authenticated
            // gateway sidecar. It is never exposed by customer/browser APIs.
            'upstream_origin' => rtrim((string) $routeRevision->origin, '/'),
            'upstream_credential' => (string) $routeRevision->credential,
            'upstream_timeout_ms' => (int) $routeRevision->timeout_ms,
        ]]);
    }

    public function settle(Request $request, Reservation $reservation, InferenceBillingService $billing): JsonResponse
    {
        $input = $request->validate($this->usageRules());
        $usage = $this->usage($input);
        $settled = DB::transaction(function () use ($reservation, $usage, $input, $billing): Reservation {
            $settled = $billing->settle($reservation, $usage);
            $log = ApiRequestLog::query()->where('reservation_id', $settled->id)->lockForUpdate()->firstOrFail();
            $creditCharge = $settled->billing_mode === 'CREDIT_BALANCE' ? (int) $settled->settled_units : null;
            $upstreamCost = $billing->upstreamCost($settled->billing_snapshot, $usage);
            $record = [
                'user_id' => $settled->user_id,
                'api_key_id' => $settled->api_key_id,
                'public_model' => $settled->public_model_alias,
                'provider_family' => $settled->billing_snapshot['provider_family'] ?? null,
                'endpoint' => $log->endpoint,
                ...$usage,
                'total_tokens' => array_sum($usage),
                'metered_units' => $settled->settled_units,
                'credit_charge_minor' => $creditCharge,
                'upstream_cost_minor' => $upstreamCost,
                'currency' => $creditCharge === null && $upstreamCost === null ? null : $settled->billing_snapshot['currency'],
                'currency_exponent' => $creditCharge === null && $upstreamCost === null ? null : $settled->billing_snapshot['currency_exponent'],
                'settled_at' => now(),
            ];
            $existing = UsageRecord::query()->where('reservation_id', $settled->id)->first();
            if ($existing && $this->usageConflict($existing, $record)) {
                throw new InferenceIdempotencyException('Usage was already recorded with different values.');
            }
            UsageRecord::query()->firstOrCreate(['reservation_id' => $settled->id], $record);
            $log->update(['state' => 'SETTLED', 'estimated_units' => null, 'duration_ms' => $input['duration_ms'] ?? null, 'finished_at' => now(), 'error_code' => null]);

            return $settled;
        });
        CustomerStateChanged::dispatch((int) $settled->user_id, 'usage.settled', ['reservation_id' => $settled->id, 'public_model' => $settled->public_model_alias, 'metered_units' => (string) $settled->settled_units]);

        return response()->json(['data' => ['reservation_id' => $settled->id, 'status' => $settled->status, 'settled_units' => (string) $settled->settled_units]]);
    }

    public function release(Reservation $reservation, ReservationService $reservations): JsonResponse
    {
        $released = $reservations->release($reservation);
        ApiRequestLog::query()->where('reservation_id', $released->id)->where('state', 'RESERVED')->update(['state' => 'RELEASED', 'estimated_units' => null, 'finished_at' => now()]);
        CustomerStateChanged::dispatch((int) $released->user_id, 'api_request.failed', ['reservation_id' => $released->id, 'public_model' => $released->public_model_alias, 'state' => 'released']);

        return response()->json(['data' => ['reservation_id' => $released->id, 'status' => $released->status, 'settled_units' => '0']]);
    }

    public function reconcile(Request $request, Reservation $reservation, ReservationService $reservations): JsonResponse
    {
        $input = $request->validate(['reason' => ['required', 'in:upstream_timeout,upstream_disconnect,client_disconnect,usage_unavailable,settlement_failed']]);
        $pending = $reservations->markForReconciliation($reservation, $input['reason']);
        ApiRequestLog::query()->where('reservation_id', $pending->id)->where('state', 'RESERVED')->update(['state' => 'RECONCILING', 'error_code' => 'billing_settlement_pending']);
        CustomerStateChanged::dispatch((int) $pending->user_id, 'api_request.failed', ['reservation_id' => $pending->id, 'public_model' => $pending->public_model_alias, 'state' => 'reconciling', 'error_code' => 'billing_settlement_pending']);

        return response()->json(['data' => ['reservation_id' => $pending->id, 'status' => $pending->status]], 202);
    }

    private function activeKey(string $customerKey, ApiKeySecretService $secrets): ApiKey
    {
        $key = ApiKey::query()->with(['user', 'modelAliases'])->where('lookup_digest', $secrets->digest($customerKey))->first();
        if (! $key) {
            throw new InferenceAccessException('invalid_api_key', 'The API key is invalid.', 401);
        }
        $status = $key->expires_at?->isPast() ? 'EXPIRED' : $key->status;
        if ($status !== 'ACTIVE') {
            throw new InferenceAccessException('api_key_'.strtolower($status), 'The API key is not active.', 403);
        }
        if ($key->user->status !== AccountStatus::Active) {
            throw new InferenceAccessException('account_suspended', 'The account is not active.', 403);
        }

        return $key;
    }

    /** @return array<string, array<int, string>> */
    private function usageRules(): array
    {
        return [
            'input_tokens' => ['required', 'integer', 'min:0'],
            'output_tokens' => ['required', 'integer', 'min:0'],
            'cache_read_tokens' => ['sometimes', 'integer', 'min:0'],
            'cache_write_tokens' => ['sometimes', 'integer', 'min:0'],
            'reasoning_tokens' => ['sometimes', 'integer', 'min:0'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, int> */
    private function usage(array $input): array
    {
        return [
            'input_tokens' => (int) $input['input_tokens'],
            'output_tokens' => (int) $input['output_tokens'],
            'cache_read_tokens' => (int) ($input['cache_read_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($input['cache_write_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($input['reasoning_tokens'] ?? 0),
        ];
    }

    private function usageConflict(UsageRecord $existing, array $record): bool
    {
        foreach (['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'metered_units'] as $field) {
            if ((int) $existing->{$field} !== (int) $record[$field]) {
                return true;
            }
        }

        return false;
    }

    private function supports(ModelAlias $alias, string $endpoint): bool
    {
        $capability = match ($endpoint) {
            '/v1/messages', '/v1/messages/count_tokens' => 'messages_api',
            '/v1/responses' => 'responses_api',
            '/v1/chat/completions' => 'chat_completions_api',
        };

        return (bool) ($alias->capabilities[$capability] ?? false);
    }

    private function limits(ApiKey $key): array
    {
        return [
            'requests_per_minute' => $key->requests_per_minute,
            'tokens_per_minute' => $key->tokens_per_minute,
            'concurrency' => $key->concurrency_limit,
            'max_request_bytes' => $key->max_request_bytes,
            'max_output_tokens' => $key->max_output_tokens,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
