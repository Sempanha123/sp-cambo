<?php

namespace App\Services;

use App\Exceptions\InferenceIdempotencyException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\ProviderConnectionRevision;
use App\Models\PlaygroundCredential;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InferenceBillingService
{
    private const WEIGHT_SCALE = 1_000_000;

    private const BILLING_BPS_SCALE = 10_000;

    // R43 provider-independent local prompt-cache discount. A matching prompt
    // prefix consumes 25% of normal Token quota, preserving a meaningful customer
    // saving while keeping the service margin sustainable. The gateway decides cache hits
    // only from hashes of the customer request received at SP Cambo.
    private const LOCAL_CACHE_READ_BPS = 2_500;

    public function __construct(private readonly ReservationService $reservations) {}

    /** Published SP Cambo local smart-reuse rate; provider cache metadata is never consulted. */
    public static function localCacheReadBps(): int
    {
        return self::LOCAL_CACHE_READ_BPS;
    }

    public static function localCacheBilledTokens(int $cacheReadTokens): int
    {
        $tokens = max(0, $cacheReadTokens);
        if ($tokens === 0) {
            return 0;
        }

        return intdiv(($tokens * self::LOCAL_CACHE_READ_BPS) + self::BILLING_BPS_SCALE - 1, self::BILLING_BPS_SCALE);
    }

    public static function localCacheSavedTokens(int $cacheReadTokens): int
    {
        $tokens = max(0, $cacheReadTokens);

        return max(0, $tokens - self::localCacheBilledTokens($tokens));
    }

    /**
     * @return array{reservation: Reservation, billing_mode: string, hard_max_output_tokens: int, route_revision_id: string|null, route_version: int|null, internal_model_id: string|null}
     */
    public function preflight(
        User $user,
        ApiKey $apiKey,
        ModelAlias $alias,
        int $estimatedInputTokens,
        int $estimatedCacheReadTokens,
        int $requestedMaxOutputTokens,
        string $requestId,
        string $requestFingerprint,
        ?string $playgroundFundingScope = null,
    ): array {
        $hardMaxOutput = $this->hardMaxOutput($apiKey, $alias);
        $boundedOutput = min($requestedMaxOutputTokens, $hardMaxOutput);
        $existing = Reservation::query()->where('idempotency_key', $requestId)->first();
        if ($existing) {
            if ((int) $existing->user_id !== (int) $user->id
                || $existing->api_key_id !== $apiKey->id
                || $existing->public_model_alias !== $alias->public_alias
                || ($existing->billing_snapshot['request_fingerprint'] ?? null) !== $requestFingerprint
                || ($playgroundFundingScope !== null && ($existing->billing_snapshot['playground_funding_scope'] ?? null) !== $playgroundFundingScope)) {
                throw new InferenceIdempotencyException;
            }

            return [
                'reservation' => $existing->load('allocations'),
                'billing_mode' => $existing->billing_mode,
                'hard_max_output_tokens' => $hardMaxOutput,
                'route_revision_id' => $existing->provider_connection_revision_id,
                'route_version' => $existing->billing_snapshot['route_version'] ?? null,
                'internal_model_id' => $existing->billing_snapshot['internal_model_id'] ?? null,
            ];
        }

        return DB::transaction(function () use ($user, $apiKey, $alias, $estimatedInputTokens, $estimatedCacheReadTokens, $boundedOutput, $hardMaxOutput, $requestId, $requestFingerprint, $playgroundFundingScope): array {
            $model = $alias->model()->with('provider.activeConnectionRevision')->firstOrFail();
            $provider = $model->provider;
            $revision = $provider?->activeConnectionRevision;
            if (! $provider || ! $provider->enabled || ! $revision || ! $revision->isRouteReady()) {
                throw new InvalidArgumentException('The selected model route is not ready.');
            }

            $isPlaygroundKey = PlaygroundCredential::query()
                ->where('user_id', $user->id)
                ->where('api_key_id', $apiKey->id)
                ->exists();

            $playgroundScope = $isPlaygroundKey
                ? ($playgroundFundingScope === 'BALANCE' ? 'BALANCE' : 'DAILY')
                : null;

            $lotsQuery = EntitlementLot::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereJsonContains('allowed_model_aliases', $alias->public_alias);

            if ($isPlaygroundKey) {
                if ($playgroundScope === 'BALANCE') {
                    $lotsQuery->where('source_type', '!=', 'PLAYGROUND_DAILY')
                        ->where(function ($access): void {
                            $access->whereNull('access_scope')
                                ->orWhere('access_scope', 'ACCOUNT')
                                ->orWhere('access_scope', 'PLAYGROUND');
                        });
                } else {
                    $lotsQuery->where('source_type', 'PLAYGROUND_DAILY');
                }
            } else {
                // A forged Playground header must never grant a customer API key
                // access to the daily pool, Playground purchases, or another
                // customer's dedicated-key lot.
                $lotsQuery->where('source_type', '!=', 'PLAYGROUND_DAILY')
                    ->where(function ($access) use ($apiKey): void {
                        $access->whereNull('access_scope')
                            ->orWhere('access_scope', 'ACCOUNT')
                            ->orWhere(function ($dedicated) use ($apiKey): void {
                                $dedicated->where('access_scope', 'API_KEY')
                                    ->where('bound_api_key_id', $apiKey->id);
                            });
                    });
            }

            $lots = $lotsQuery
                ->orderByRaw('expires_at IS NULL')
                ->orderBy('expires_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $buckets = $isPlaygroundKey && $playgroundScope === 'BALANCE'
                ? [
                    ['TOKEN_QUOTA', 'REDEEM_CODE'],
                    ['CREDIT_BALANCE', 'REDEEM_CODE'],
                    ['TOKEN_QUOTA', 'NON_REDEEM'],
                    ['CREDIT_BALANCE', 'NON_REDEEM'],
                ]
                : [
                    ['TOKEN_QUOTA', 'ANY'],
                    ['CREDIT_BALANCE', 'ANY'],
                ];

            foreach ($buckets as [$billingMode, $sourceBucket]) {
                /** @var Collection<int, EntitlementLot> $matching */
                $matching = $lots->where('billing_mode', $billingMode);
                if ($sourceBucket === 'REDEEM_CODE') {
                    $matching = $matching->where('source_type', 'REDEEM_CODE');
                } elseif ($sourceBucket === 'NON_REDEEM') {
                    $matching = $matching->reject(fn (EntitlementLot $lot): bool => $lot->source_type === 'REDEEM_CODE');
                }
                if ($matching->isEmpty()) {
                    continue;
                }

                foreach ($this->groups($matching) as $group) {
                    $snapshot = $this->snapshot($billingMode, $alias, $group->firstOrFail());
                    $snapshot['request_fingerprint'] = $requestFingerprint;
                    $snapshot['route_revision_id'] = (string) $revision->id;
                    $snapshot['route_version'] = (int) $revision->route_version;
                    $snapshot['internal_model_id'] = (string) $model->internal_model_id;
                    $snapshot['estimated_input_tokens'] = $estimatedInputTokens;
                    $snapshot['estimated_cache_read_tokens'] = $estimatedCacheReadTokens;
                    $snapshot['requested_max_output_tokens'] = $boundedOutput;
                    $snapshot['funding_source_type'] = (string) $group->firstOrFail()->source_type;
                    if ($playgroundScope !== null) {
                        $snapshot['playground_funding_scope'] = $playgroundScope;
                    }
                    $reserveUnits = $this->reservationUnits($snapshot, $estimatedInputTokens, $estimatedCacheReadTokens, $boundedOutput);
                    $available = $group->sum(fn (EntitlementLot $lot): int => max(0, $lot->remaining_units - $lot->reserved_units));
                    if ($available < $reserveUnits) {
                        continue;
                    }

                    $reservation = $this->reservations->reserve(
                        $user,
                        $alias->public_alias,
                        $billingMode,
                        $reserveUnits,
                        $requestId,
                        $apiKey->id,
                        $group->pluck('id')->all(),
                        $snapshot,
                        (string) $revision->id,
                        $playgroundScope,
                    );

                    return [
                        'reservation' => $reservation,
                        'billing_mode' => $billingMode,
                        'hard_max_output_tokens' => $hardMaxOutput,
                        'route_revision_id' => (string) $revision->id,
                        'route_version' => (int) $revision->route_version,
                        'internal_model_id' => (string) $model->internal_model_id,
                    ];
                }
            }

            throw new InsufficientBalanceException(
                $lots->where('billing_mode', 'CREDIT_BALANCE')->isNotEmpty() ? 'CREDIT_BALANCE' : 'TOKEN_QUOTA'
            );
        });
    }

    /** @param array<string, int> $usage */
    public function settle(Reservation $reservation, array $usage): Reservation
    {
        $snapshot = $reservation->billing_snapshot;
        if (! is_array($snapshot)) {
            throw new InvalidArgumentException('Reservation billing snapshot is missing.');
        }

        $actualUnits = $this->actualUnits($snapshot, $usage);

        // The reservation is the maximum customer-visible hold for this request.
        // A local heuristic must never create an over-reservation debt or a
        // reconciliation hold. Any estimator drift is capped at the reserved max.
        return $this->reservations->settle(
            $reservation,
            min($actualUnits, (int) $reservation->reserved_units),
        );
    }

    /** @param array<string, mixed> $snapshot */
    public function reservationUnits(array $snapshot, int $estimatedInputTokens, int $estimatedCacheReadTokens, int $requestedMaxOutputTokens): int
    {
        if ($estimatedInputTokens < 0 || $estimatedCacheReadTokens < 0 || $requestedMaxOutputTokens < 0) {
            throw new InvalidArgumentException('Token estimates are invalid.');
        }

        $maximumUsage = [
            // The gateway input estimate includes a cache safety allowance. For
            // output, reserve each token at the more expensive of ordinary or
            // reasoning pricing/weight because those are mutually exclusive
            // partitions after protocol normalization.
            'input_tokens' => $estimatedInputTokens,
            'output_tokens' => $requestedMaxOutputTokens,
            'cache_read_tokens' => $estimatedCacheReadTokens,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
        ];

        // R42 local cache-aware quota: uncached input and output spend 1:1. A
        // repeated prompt prefix detected by SP Cambo's own hash-only cache spends
        // the published local-cache fraction. No OmniRoute/provider cache signal
        // participates in this calculation.
        if (($snapshot['billing_mode'] ?? null) === 'TOKEN_QUOTA') {
            $cacheUnits = $this->localCacheUnits($snapshot, $estimatedCacheReadTokens);
            return max(1, $this->checkedAdd(
                $this->checkedAdd($estimatedInputTokens, $cacheUnits),
                $requestedMaxOutputTokens,
            ));
        }

        // Money/wallet credit remains price-based, but still uses only SP Cambo's
        // local input/output meter. Reservation uses the highest configured class
        // price as a conservative hold; settlement returns the unused amount.
        $snapshot['input_billing_multiplier_bps'] = self::BILLING_BPS_SCALE;
        $snapshot['output_billing_multiplier_bps'] = self::BILLING_BPS_SCALE;
        $billableUsage = $this->billableUsage($snapshot, $maximumUsage);

        if (($snapshot['billing_mode'] ?? null) === 'CREDIT_BALANCE') {
            $snapshot['input_per_million_minor'] = max(
                (int) ($snapshot['input_per_million_minor'] ?? 0),
                (int) ($snapshot['cache_read_per_million_minor'] ?? 0),
                (int) ($snapshot['cache_write_per_million_minor'] ?? 0),
            );
            $snapshot['output_per_million_minor'] = max(
                (int) ($snapshot['output_per_million_minor'] ?? 0),
                (int) ($snapshot['reasoning_per_million_minor'] ?? 0),
            );

            return max(1, $this->creditUnits($snapshot, $billableUsage));
        }

        throw new InvalidArgumentException('Unsupported billing mode.');
    }

    /** @param array<string, mixed> $snapshot @param array<string, int> $usage */
    public function actualUnits(array $snapshot, array $usage): int
    {
        // Token packages and dollar-denominated quota Credits use only the
        // SP Cambo local meter. Uncached input/output are 1:1; a locally detected
        // cache hit receives the configured cache-read discount.
        if (($snapshot['billing_mode'] ?? null) === 'TOKEN_QUOTA') {
            $input = max(0, (int) ($usage['input_tokens'] ?? 0));
            $cached = $this->localCacheUnits($snapshot, max(0, (int) ($usage['cache_read_tokens'] ?? 0)));
            $output = max(0, (int) ($usage['output_tokens'] ?? 0));
            return $this->checkedAdd($this->checkedAdd($input, $cached), $output);
        }

        // Wallet/money credit is priced from the same local input/output counts.
        // Cache/reasoning provider counters are deliberately ignored because the
        // public gateway never trusts OmniRoute/provider usage for settlement.
        $localUsage = [
            'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
            'cache_read_tokens' => max(0, (int) ($usage['cache_read_tokens'] ?? 0)),
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
        ];
        foreach (['input', 'output', 'cache_read', 'cache_write', 'reasoning'] as $class) {
            $snapshot[$class.'_billing_multiplier_bps'] = self::BILLING_BPS_SCALE;
        }
        $billableUsage = $this->billableUsage($snapshot, $localUsage);

        if (($snapshot['billing_mode'] ?? null) === 'CREDIT_BALANCE') {
            return $this->creditUnits($snapshot, $billableUsage);
        }

        throw new InvalidArgumentException('Unsupported billing mode.');
    }

    private function hardMaxOutput(ApiKey $apiKey, ModelAlias $alias): int
    {
        $limits = is_array($alias->limits) ? $alias->limits : [];
        $capabilities = is_array($alias->capabilities) ? $alias->capabilities : [];
        $values = array_filter([
            $apiKey->max_output_tokens,
            $limits['max_output_tokens'] ?? null,
            $capabilities['max_output_tokens'] ?? null,
        ], fn ($value): bool => is_int($value) && $value > 0);

        return $values === [] ? 4096 : min($values);
    }

    /** @return array<int, Collection<int, EntitlementLot>> */
    private function groups(Collection $lots): array
    {
        return array_values($lots->groupBy(fn (EntitlementLot $lot): string => $lot->billing_snapshot_hash
            ?: hash('sha256', json_encode($lot->billing_snapshot ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))->all());
    }

    /** @return array<string, mixed> */
    private function snapshot(string $billingMode, ModelAlias $alias, EntitlementLot $lot): array
    {
        $lotSnapshot = is_array($lot->billing_snapshot) ? $lot->billing_snapshot : [];
        $rules = is_array($lotSnapshot['billing_rules'] ?? null) ? $lotSnapshot['billing_rules'] : [];
        $limits = is_array($alias->limits) ? $alias->limits : [];
        $ruleMultipliers = is_array($rules['billing_multipliers_bps'] ?? null) ? $rules['billing_multipliers_bps'] : [];
        $aliasMultipliers = is_array($limits['billing_multipliers_bps'] ?? null) ? $limits['billing_multipliers_bps'] : [];
        $multipliers = [];
        foreach (['input', 'output', 'cache_read', 'cache_write', 'reasoning'] as $class) {
            $value = (int) ($ruleMultipliers[$class] ?? $aliasMultipliers[$class] ?? self::BILLING_BPS_SCALE);
            // Never turn SP-local metered usage into fewer billable units.
            // A package/model may add a small, published SP billing multiplier.
            $multipliers[$class] = max(self::BILLING_BPS_SCALE, min(50_000, $value));
        }
        $pricing = $alias->pricing;
        $upstreamVerified = $pricing?->upstream_cost_verified_at !== null;

        if ($billingMode === 'CREDIT_BALANCE') {
            if (! $pricing) {
                throw new InvalidArgumentException('Credit pricing is not configured for this model.');
            }

            return [
                'billing_mode' => $billingMode,
                'provider_family' => $alias->model()->value('family'),
                'currency' => $lot->currency ?? $pricing->currency,
                'currency_exponent' => (int) ($lot->currency_exponent ?? $pricing->exponent),
                'pricing_exponent' => (int) $pricing->exponent,
                'input_billing_multiplier_bps' => $multipliers['input'],
                'output_billing_multiplier_bps' => $multipliers['output'],
                'cache_read_billing_multiplier_bps' => $multipliers['cache_read'],
                'cache_write_billing_multiplier_bps' => $multipliers['cache_write'],
                'reasoning_billing_multiplier_bps' => $multipliers['reasoning'],
                'minimum_request_units' => max(0, (int) ($rules['minimum_request_units'] ?? $limits['minimum_request_units'] ?? 0)),
                'metering_method' => (string) ($rules['metering_method'] ?? $limits['metering_method'] ?? 'SP_LOCAL_ESTIMATE_V2'),
                'sp_credit_billable_units' => max(1, (int) ($rules['sp_credit_billable_units'] ?? $limits['sp_credit_billable_units'] ?? 100_000)),
                'local_cache_read_billing_bps' => max(0, min(self::BILLING_BPS_SCALE, (int) ($rules['local_cache_read_billing_bps'] ?? $limits['local_cache_read_billing_bps'] ?? self::LOCAL_CACHE_READ_BPS))),
                'input_per_million_minor' => (int) $pricing->input_per_million_minor,
                'output_per_million_minor' => (int) $pricing->output_per_million_minor,
                'cache_read_per_million_minor' => (int) ($pricing->cache_read_per_million_minor ?? $pricing->input_per_million_minor),
                'cache_write_per_million_minor' => (int) ($pricing->cache_write_per_million_minor ?? $pricing->input_per_million_minor),
                'reasoning_per_million_minor' => (int) ($pricing->reasoning_per_million_minor ?? $pricing->output_per_million_minor),
                'upstream_input_per_million_minor' => ! $upstreamVerified || $pricing->upstream_input_per_million_minor === null ? null : (int) $pricing->upstream_input_per_million_minor,
                'upstream_output_per_million_minor' => ! $upstreamVerified || $pricing->upstream_output_per_million_minor === null ? null : (int) $pricing->upstream_output_per_million_minor,
                'upstream_cache_read_per_million_minor' => ! $upstreamVerified || $pricing->upstream_cache_read_per_million_minor === null ? null : (int) $pricing->upstream_cache_read_per_million_minor,
                'upstream_cache_write_per_million_minor' => ! $upstreamVerified || $pricing->upstream_cache_write_per_million_minor === null ? null : (int) $pricing->upstream_cache_write_per_million_minor,
                'upstream_reasoning_per_million_minor' => ! $upstreamVerified || $pricing->upstream_reasoning_per_million_minor === null ? null : (int) $pricing->upstream_reasoning_per_million_minor,
            ];
        }

        // TOKEN_QUOTA customers are charged SP-local weighted quota units.
        // Any configured cost fields are SP Cambo's private reference-cost
        // snapshot only. Runtime settlement never reads OmniRoute/provider usage
        // metadata and never asks the provider to calculate customer billing.
        return [
            'billing_mode' => $billingMode,
            'provider_family' => $alias->model()->value('family'),
            'currency' => $pricing?->currency,
            'currency_exponent' => $pricing === null ? null : (int) $pricing->exponent,
            'pricing_exponent' => $pricing === null ? null : (int) $pricing->exponent,
            'input_billing_multiplier_bps' => $multipliers['input'],
            'output_billing_multiplier_bps' => $multipliers['output'],
            'cache_read_billing_multiplier_bps' => $multipliers['cache_read'],
            'cache_write_billing_multiplier_bps' => $multipliers['cache_write'],
            'reasoning_billing_multiplier_bps' => $multipliers['reasoning'],
            'minimum_request_units' => max(0, (int) ($rules['minimum_request_units'] ?? $limits['minimum_request_units'] ?? 0)),
            'metering_method' => (string) ($rules['metering_method'] ?? $limits['metering_method'] ?? 'SP_LOCAL_ESTIMATE_V2'),
            'sp_credit_billable_units' => max(1, (int) ($rules['sp_credit_billable_units'] ?? $limits['sp_credit_billable_units'] ?? 100_000)),
            'local_cache_read_billing_bps' => max(0, min(self::BILLING_BPS_SCALE, (int) ($rules['local_cache_read_billing_bps'] ?? $limits['local_cache_read_billing_bps'] ?? self::LOCAL_CACHE_READ_BPS))),
            'upstream_input_per_million_minor' => ! $upstreamVerified || $pricing?->upstream_input_per_million_minor === null ? null : (int) $pricing->upstream_input_per_million_minor,
            'upstream_output_per_million_minor' => ! $upstreamVerified || $pricing?->upstream_output_per_million_minor === null ? null : (int) $pricing->upstream_output_per_million_minor,
            'upstream_cache_read_per_million_minor' => ! $upstreamVerified || $pricing?->upstream_cache_read_per_million_minor === null ? null : (int) $pricing->upstream_cache_read_per_million_minor,
            'upstream_cache_write_per_million_minor' => ! $upstreamVerified || $pricing?->upstream_cache_write_per_million_minor === null ? null : (int) $pricing->upstream_cache_write_per_million_minor,
            'upstream_reasoning_per_million_minor' => ! $upstreamVerified || $pricing?->upstream_reasoning_per_million_minor === null ? null : (int) $pricing->upstream_reasoning_per_million_minor,
            'weight_scale' => self::WEIGHT_SCALE,
            'input_weight_microunits' => (int) ($rules['input_weight_microunits'] ?? self::WEIGHT_SCALE),
            'output_weight_microunits' => (int) ($rules['output_weight_microunits'] ?? self::WEIGHT_SCALE),
            'cache_read_weight_microunits' => (int) ($rules['cache_read_weight_microunits'] ?? self::WEIGHT_SCALE),
            'cache_write_weight_microunits' => (int) ($rules['cache_write_weight_microunits'] ?? self::WEIGHT_SCALE),
            'reasoning_weight_microunits' => (int) ($rules['reasoning_weight_microunits'] ?? self::WEIGHT_SCALE),
        ];
    }

    /**
     * Convert SP Cambo locally-metered request/response units into customer
     * billable usage. The gateway deliberately ignores OmniRoute/provider token
     * counters for customer settlement. The multiplier is snapshotted at
     * preflight so later catalogue edits never rewrite a request.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, int> $usage
     * @return array<string, int>
     */
    private function billableUsage(array $snapshot, array $usage): array
    {
        $map = [
            'input_tokens' => 'input_billing_multiplier_bps',
            'output_tokens' => 'output_billing_multiplier_bps',
            'cache_read_tokens' => 'cache_read_billing_multiplier_bps',
            'cache_write_tokens' => 'cache_write_billing_multiplier_bps',
            'reasoning_tokens' => 'reasoning_billing_multiplier_bps',
        ];

        $billable = $usage;
        foreach ($map as $usageKey => $multiplierKey) {
            $raw = max(0, (int) ($usage[$usageKey] ?? 0));
            if ($raw === 0) {
                $billable[$usageKey] = 0;
                continue;
            }

            $bps = max(self::BILLING_BPS_SCALE, (int) ($snapshot[$multiplierKey] ?? self::BILLING_BPS_SCALE));
            $scaled = $this->checkedMultiply($raw, $bps);
            $billable[$usageKey] = intdiv(
                $this->checkedAdd($scaled, self::BILLING_BPS_SCALE - 1),
                self::BILLING_BPS_SCALE,
            );
        }

        return $billable;
    }

    /** @param array<string, mixed> $snapshot @param array<string, int> $usage */
    private function weightedUnits(array $snapshot, array $usage): int
    {
        $categories = [
            'input_tokens' => 'input_weight_microunits',
            'output_tokens' => 'output_weight_microunits',
            'cache_read_tokens' => 'cache_read_weight_microunits',
            'cache_write_tokens' => 'cache_write_weight_microunits',
            'reasoning_tokens' => 'reasoning_weight_microunits',
        ];
        $weighted = 0;
        foreach ($categories as $usageKey => $weightKey) {
            $weighted = $this->checkedAdd($weighted, $this->checkedMultiply($usage[$usageKey] ?? 0, (int) ($snapshot[$weightKey] ?? self::WEIGHT_SCALE)));
        }

        return intdiv($this->checkedAdd($weighted, self::WEIGHT_SCALE - 1), self::WEIGHT_SCALE);
    }

    /** @param array<string, mixed> $snapshot */
    private function localCacheUnits(array $snapshot, int $cacheReadTokens): int
    {
        // R43 final smart-reuse policy is snapshotted into each entitlement lot.
        // New catalogue purchases use 25%, while an already-purchased lot keeps
        // the reuse rate promised when it was created. Provider/OmniRoute cache
        // metadata still never participates in this calculation.
        $tokens = max(0, $cacheReadTokens);
        if ($tokens === 0) {
            return 0;
        }

        $bps = max(0, min(
            self::BILLING_BPS_SCALE,
            (int) ($snapshot['local_cache_read_billing_bps'] ?? self::LOCAL_CACHE_READ_BPS),
        ));

        return intdiv(($tokens * $bps) + self::BILLING_BPS_SCALE - 1, self::BILLING_BPS_SCALE);
    }

    /** @param array<string, mixed> $snapshot @param array<string, int> $usage */
    public function upstreamCost(array $snapshot, array $usage): ?int
    {
        $prices = [
            'input_tokens' => 'upstream_input_per_million_minor',
            'output_tokens' => 'upstream_output_per_million_minor',
            'cache_read_tokens' => 'upstream_cache_read_per_million_minor',
            'cache_write_tokens' => 'upstream_cache_write_per_million_minor',
            'reasoning_tokens' => 'upstream_reasoning_per_million_minor',
        ];
        // A missing optional price only makes cost unknown when that usage class
        // was actually consumed. This lets Gemini keep cache-storage pricing out
        // of the per-request token meter without making every normal request
        // uncostable.
        foreach ($prices as $usageKey => $priceKey) {
            if (($usage[$usageKey] ?? 0) > 0
                && (! array_key_exists($priceKey, $snapshot) || $snapshot[$priceKey] === null)) {
                return null;
            }
        }

        return $this->pricedUnits($snapshot, $usage, $prices);
    }

    private function creditUnits(array $snapshot, array $usage): int
    {
        return $this->pricedUnits($snapshot, $usage, [
            'input_tokens' => 'input_per_million_minor',
            'output_tokens' => 'output_per_million_minor',
            'cache_read_tokens' => 'cache_read_per_million_minor',
            'cache_write_tokens' => 'cache_write_per_million_minor',
            'reasoning_tokens' => 'reasoning_per_million_minor',
        ]);
    }

    private function pricedUnits(array $snapshot, array $usage, array $categories): int
    {
        $rateNumerator = 0;
        foreach ($categories as $usageKey => $priceKey) {
            $rateNumerator = $this->checkedAdd(
                $rateNumerator,
                $this->checkedMultiply($usage[$usageKey] ?? 0, (int) ($snapshot[$priceKey] ?? 0))
            );
        }

        if ($rateNumerator === 0) {
            return 0;
        }

        // Model prices can use finer precision than a customer's wallet. R23
        // stores official model rates at exponent 3 so Gemini's $0.075 / 1M
        // cached-context cost is exact, while ordinary USD credit lots remain
        // exponent 2. Convert only after multiplying by real token usage, then
        // conservatively round up to the settlement currency unit.
        $rateExponent = (int) ($snapshot['pricing_exponent'] ?? $snapshot['currency_exponent'] ?? 2);
        $settlementExponent = (int) ($snapshot['currency_exponent'] ?? $rateExponent);
        $delta = $rateExponent - $settlementExponent;

        if ($delta >= 0) {
            $denominator = $this->checkedMultiply(1_000_000, $this->powerOfTen($delta));

            return intdiv($this->checkedAdd($rateNumerator, $denominator - 1), $denominator);
        }

        $scaled = $this->checkedMultiply($rateNumerator, $this->powerOfTen(-$delta));

        return intdiv($this->checkedAdd($scaled, 999_999), 1_000_000);
    }

    private function powerOfTen(int $exponent): int
    {
        if ($exponent < 0 || $exponent > 6) {
            throw new InvalidArgumentException('Currency exponent conversion is outside the supported range.');
        }

        $value = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $value *= 10;
        }

        return $value;
    }

    private function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw new InvalidArgumentException('Billing calculation exceeds the supported integer range.');
        }

        return $left * $right;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new InvalidArgumentException('Billing calculation exceeds the supported integer range.');
        }

        return $left + $right;
    }
}
