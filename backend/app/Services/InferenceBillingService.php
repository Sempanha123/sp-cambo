<?php

namespace App\Services;

use App\Exceptions\InferenceIdempotencyException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\ApiKey;
use App\Models\EntitlementLot;
use App\Models\ModelAlias;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InferenceBillingService
{
    private const WEIGHT_SCALE = 1_000_000;

    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * @return array{reservation: Reservation, billing_mode: string, hard_max_output_tokens: int, route_revision_id: string|null, route_version: int|null, internal_model_id: string|null}
     */
    public function preflight(
        User $user,
        ApiKey $apiKey,
        ModelAlias $alias,
        int $estimatedInputTokens,
        int $requestedMaxOutputTokens,
        string $requestId,
        string $requestFingerprint,
    ): array {
        $hardMaxOutput = $this->hardMaxOutput($apiKey, $alias);
        $boundedOutput = min($requestedMaxOutputTokens, $hardMaxOutput);
        $existing = Reservation::query()->where('idempotency_key', $requestId)->first();
        if ($existing) {
            if ((int) $existing->user_id !== (int) $user->id
                || $existing->api_key_id !== $apiKey->id
                || $existing->public_model_alias !== $alias->public_alias
                || ($existing->billing_snapshot['request_fingerprint'] ?? null) !== $requestFingerprint) {
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

        return DB::transaction(function () use ($user, $apiKey, $alias, $estimatedInputTokens, $boundedOutput, $hardMaxOutput, $requestId, $requestFingerprint): array {
            $model = $alias->model()->with('provider.activeConnectionRevision')->firstOrFail();
            $provider = $model->provider;
            $revision = $provider?->activeConnectionRevision;
            if (! $provider || ! $provider->enabled || ! $revision || ! $revision->isRouteReady()) {
                throw new InvalidArgumentException('The selected model route is not ready.');
            }

            $lots = EntitlementLot::query()
                ->where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereJsonContains('allowed_model_aliases', $alias->public_alias)
                ->orderByRaw('expires_at IS NULL')
                ->orderBy('expires_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            foreach (['TOKEN_QUOTA', 'CREDIT_BALANCE'] as $billingMode) {
                /** @var Collection<int, EntitlementLot> $matching */
                $matching = $lots->where('billing_mode', $billingMode);
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
                    $snapshot['requested_max_output_tokens'] = $boundedOutput;
                    $reserveUnits = $this->reservationUnits($snapshot, $estimatedInputTokens, $boundedOutput);
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

        return $this->reservations->settle($reservation, $this->actualUnits($snapshot, $usage));
    }

    /** @param array<string, mixed> $snapshot */
    public function reservationUnits(array $snapshot, int $estimatedInputTokens, int $requestedMaxOutputTokens): int
    {
        if ($estimatedInputTokens < 0 || $requestedMaxOutputTokens < 0) {
            throw new InvalidArgumentException('Token estimates are invalid.');
        }

        $maximumUsage = [
            // The gateway input estimate includes a cache safety allowance. For
            // output, reserve each token at the more expensive of ordinary or
            // reasoning pricing/weight because those are mutually exclusive
            // partitions after protocol normalization.
            'input_tokens' => $estimatedInputTokens,
            'output_tokens' => $requestedMaxOutputTokens,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
        ];

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

            return max(1, $this->creditUnits($snapshot, $maximumUsage));
        }

        $snapshot['input_weight_microunits'] = max(
            (int) ($snapshot['input_weight_microunits'] ?? self::WEIGHT_SCALE),
            (int) ($snapshot['cache_read_weight_microunits'] ?? self::WEIGHT_SCALE),
            (int) ($snapshot['cache_write_weight_microunits'] ?? self::WEIGHT_SCALE),
        );
        $snapshot['output_weight_microunits'] = max(
            (int) ($snapshot['output_weight_microunits'] ?? self::WEIGHT_SCALE),
            (int) ($snapshot['reasoning_weight_microunits'] ?? self::WEIGHT_SCALE),
        );

        return max(1, $this->weightedUnits($snapshot, $maximumUsage));
    }

    /** @param array<string, mixed> $snapshot @param array<string, int> $usage */
    public function actualUnits(array $snapshot, array $usage): int
    {
        return ($snapshot['billing_mode'] ?? null) === 'CREDIT_BALANCE'
            ? $this->creditUnits($snapshot, $usage)
            : $this->weightedUnits($snapshot, $usage);
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
        $pricing = $alias->pricing;

        if ($billingMode === 'CREDIT_BALANCE') {
            if (! $pricing) {
                throw new InvalidArgumentException('Credit pricing is not configured for this model.');
            }

            return [
                'billing_mode' => $billingMode,
                'provider_family' => $alias->model()->value('family'),
                'currency' => $lot->currency ?? $pricing->currency,
                'currency_exponent' => (int) ($lot->currency_exponent ?? $pricing->exponent),
                'input_per_million_minor' => (int) $pricing->input_per_million_minor,
                'output_per_million_minor' => (int) $pricing->output_per_million_minor,
                'cache_read_per_million_minor' => (int) ($pricing->cache_read_per_million_minor ?? $pricing->input_per_million_minor),
                'cache_write_per_million_minor' => (int) ($pricing->cache_write_per_million_minor ?? $pricing->input_per_million_minor),
                'reasoning_per_million_minor' => (int) ($pricing->reasoning_per_million_minor ?? $pricing->output_per_million_minor),
                'upstream_input_per_million_minor' => $pricing->upstream_input_per_million_minor === null ? null : (int) $pricing->upstream_input_per_million_minor,
                'upstream_output_per_million_minor' => $pricing->upstream_output_per_million_minor === null ? null : (int) $pricing->upstream_output_per_million_minor,
                'upstream_cache_read_per_million_minor' => $pricing->upstream_cache_read_per_million_minor === null ? null : (int) $pricing->upstream_cache_read_per_million_minor,
                'upstream_cache_write_per_million_minor' => $pricing->upstream_cache_write_per_million_minor === null ? null : (int) $pricing->upstream_cache_write_per_million_minor,
                'upstream_reasoning_per_million_minor' => $pricing->upstream_reasoning_per_million_minor === null ? null : (int) $pricing->upstream_reasoning_per_million_minor,
            ];
        }

        return [
            'billing_mode' => $billingMode,
            'weight_scale' => self::WEIGHT_SCALE,
            'input_weight_microunits' => (int) ($rules['input_weight_microunits'] ?? self::WEIGHT_SCALE),
            'output_weight_microunits' => (int) ($rules['output_weight_microunits'] ?? self::WEIGHT_SCALE),
            'cache_read_weight_microunits' => (int) ($rules['cache_read_weight_microunits'] ?? self::WEIGHT_SCALE),
            'cache_write_weight_microunits' => (int) ($rules['cache_write_weight_microunits'] ?? self::WEIGHT_SCALE),
            'reasoning_weight_microunits' => (int) ($rules['reasoning_weight_microunits'] ?? self::WEIGHT_SCALE),
        ];
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
        if (collect($prices)->contains(fn (string $priceKey): bool => ! array_key_exists($priceKey, $snapshot) || $snapshot[$priceKey] === null)) {
            return null;
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
        $chargeMicrominor = 0;
        foreach ($categories as $usageKey => $priceKey) {
            $chargeMicrominor = $this->checkedAdd($chargeMicrominor, $this->checkedMultiply($usage[$usageKey] ?? 0, (int) ($snapshot[$priceKey] ?? 0)));
        }

        return $chargeMicrominor === 0 ? 0 : intdiv($this->checkedAdd($chargeMicrominor, 999_999), 1_000_000);
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
