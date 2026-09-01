<?php

namespace App\Services;

use App\Exceptions\InferenceAccessException;
use App\Models\AiModel;
use App\Models\ApiRequestLog;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\ProviderConnectionRevision;
use App\Models\ProviderRouteHealth;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ModelRoutePoolService
{
    /**
     * Select one private route for a public model.
     *
     * Supports any number of routes and any number of providers. Each entry binds
     * a verified private AiModel to a READY connection revision from that same
     * provider, while the customer keeps using only ModelAlias::public_alias.
     *
     * @param array<int,int> $excludeEntryIds
     * @return array{
     *   entry: ModelRoutePoolEntry|null,
     *   revision: ProviderConnectionRevision,
     *   model: AiModel,
     *   pool: ModelRoutePool|null
     * }
     */
    public function select(
        ModelAlias $alias,
        AiModel $primaryModel,
        array $excludeEntryIds = [],
        ?string $ignoreReservationId = null,
    ): array {
        $pool = ModelRoutePool::query()
            ->where('model_alias_id', $alias->id)
            ->lockForUpdate()
            ->first();

        if (! $pool || ! $pool->enabled) {
            $primaryModel->loadMissing('provider.activeConnectionRevision');
            $provider = $primaryModel->provider;
            $revision = $provider?->activeConnectionRevision;

            if (! $provider
                || ! $provider->enabled
                || ! $primaryModel->enabled
                || $primaryModel->commercial_resale_verified_at === null
                || ! $revision
                || ! $revision->isRouteReady()
                || $revision->last_probe_status !== 'SUCCESS') {
                throw new InferenceAccessException(
                    'model_route_unavailable',
                    'The selected model route is not ready.',
                    503,
                );
            }

            return [
                'entry' => null,
                'revision' => $revision,
                'model' => $primaryModel,
                'pool' => null,
            ];
        }

        if ($pool->strategy !== ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS) {
            throw new InferenceAccessException(
                'model_route_strategy_invalid',
                'The model route pool is not configured correctly.',
                503,
            );
        }

        $globalQuery = Reservation::query()
            ->where('status', 'ACTIVE')
            ->where('public_model_alias', $alias->public_alias);

        if ($ignoreReservationId !== null) {
            $globalQuery->whereKeyNot($ignoreReservationId);
        }

        $globalActive = $globalQuery->count();

        if ($pool->max_concurrency !== null
            && $globalActive >= (int) $pool->max_concurrency) {
            throw new InferenceAccessException(
                'model_concurrency_limit_exceeded',
                'The selected model is currently at capacity. Retry shortly.',
                429,
            );
        }

        $entries = ModelRoutePoolEntry::query()
            ->with([
                'model.provider',
                'revision.provider',
            ])
            ->where('model_route_pool_id', $pool->id)
            ->where('enabled', true)
            ->when(
                $excludeEntryIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludeEntryIds)
            )
            ->orderBy('priority')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($entries->isEmpty()) {
            throw new InferenceAccessException(
                'model_route_unavailable',
                'No route is currently available for the selected model.',
                503,
            );
        }

        $revisionIds = $entries
            ->pluck('provider_connection_revision_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        $activeQuery = Reservation::query()
            ->where('status', 'ACTIVE')
            ->whereIn('provider_connection_revision_id', $revisionIds);

        if ($ignoreReservationId !== null) {
            $activeQuery->whereKeyNot($ignoreReservationId);
        }

        $routeActive = $activeQuery
            ->selectRaw('provider_connection_revision_id, COUNT(*) AS active_count')
            ->groupBy('provider_connection_revision_id')
            ->pluck('active_count', 'provider_connection_revision_id');

        $health = ProviderRouteHealth::query()
            ->whereIn('provider_connection_revision_id', $revisionIds)
            ->get()
            ->keyBy(fn (ProviderRouteHealth $row): string => (string) $row->provider_connection_revision_id);

        $eligible = $entries
            ->map(function (ModelRoutePoolEntry $entry) use ($routeActive, $health): ?array {
                $model = $entry->model;
                $revision = $entry->revision;
                $provider = $model?->provider;

                if (! $model
                    || ! $provider
                    || ! $revision
                    || ! $model->enabled
                    || $model->commercial_resale_verified_at === null
                    || ! $provider->enabled
                    || (string) $revision->provider_id !== (string) $model->provider_id
                    || ! $revision->isRouteReady()
                    || $revision->last_probe_status !== 'SUCCESS') {
                    return null;
                }

                /** @var ProviderRouteHealth|null $routeHealth */
                $routeHealth = $health->get((string) $revision->id);
                if ($routeHealth?->circuitIsOpen()) {
                    return null;
                }

                $active = (int) ($routeActive[(string) $revision->id] ?? 0);
                $capacity = $entry->max_concurrency === null
                    ? null
                    : (int) $entry->max_concurrency;

                if ($capacity !== null && $active >= $capacity) {
                    return null;
                }

                $weight = max(1, (int) $entry->weight);

                return [
                    'entry' => $entry,
                    'active' => $active,
                    // Weighted least-connections. A route with weight 200 can
                    // accept roughly twice the load of an equally healthy route
                    // at weight 100 before their scores equalize.
                    'score' => ($active + 1) / $weight,
                ];
            })
            ->filter()
            ->values();

        if ($eligible->isEmpty()) {
            throw new InferenceAccessException(
                'model_route_capacity_exceeded',
                'All routes for the selected model are busy or temporarily unavailable. Retry shortly.',
                429,
            );
        }

        $selected = $eligible
            ->sort(function (array $left, array $right): int {
                $score = $left['score'] <=> $right['score'];
                if ($score !== 0) {
                    return $score;
                }

                $active = $left['active'] <=> $right['active'];
                if ($active !== 0) {
                    return $active;
                }

                $priority = (int) $left['entry']->priority <=> (int) $right['entry']->priority;
                if ($priority !== 0) {
                    return $priority;
                }

                return (int) $left['entry']->id <=> (int) $right['entry']->id;
            })
            ->first();

        /** @var ModelRoutePoolEntry $entry */
        $entry = $selected['entry'];

        return [
            'entry' => $entry,
            'revision' => $entry->revision,
            'model' => $entry->model,
            'pool' => $pool,
        ];
    }

    /**
     * Mark the current route failed and atomically move an ACTIVE reservation to
     * another healthy route. This is valid only before public streaming begins.
     *
     * @return array{
     *   entry: ModelRoutePoolEntry,
     *   revision: ProviderConnectionRevision,
     *   model: AiModel,
     *   pool: ModelRoutePool
     * }
     */
    public function failover(
        Reservation $reservation,
        string $failureCode,
        ?int $upstreamStatus = null,
    ): array {
        return DB::transaction(function () use ($reservation, $failureCode, $upstreamStatus): array {
            $locked = Reservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($locked->status !== 'ACTIVE') {
                throw new InferenceAccessException(
                    'route_failover_not_allowed',
                    'This inference request can no longer change routes.',
                    409,
                );
            }

            $log = ApiRequestLog::query()
                ->where('reservation_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($log && $log->state === 'STREAMING') {
                throw new InferenceAccessException(
                    'route_failover_not_allowed',
                    'Route failover is not allowed after streaming begins.',
                    409,
                );
            }

            $alias = ModelAlias::query()
                ->where('public_alias', $locked->public_model_alias)
                ->firstOrFail();

            $primaryModel = $alias->model()->with('provider')->firstOrFail();

            $pool = ModelRoutePool::query()
                ->where('model_alias_id', $alias->id)
                ->lockForUpdate()
                ->first();

            if (! $pool || ! $pool->enabled || $locked->model_route_pool_entry_id === null) {
                throw new InferenceAccessException(
                    'route_failover_unavailable',
                    'No alternate route is configured for this request.',
                    409,
                );
            }

            $snapshot = is_array($locked->billing_snapshot)
                ? $locked->billing_snapshot
                : [];

            $history = is_array($snapshot['route_history'] ?? null)
                ? $snapshot['route_history']
                : [];

            if ($history === []) {
                $history[] = [
                    'entry_id' => (int) $locked->model_route_pool_entry_id,
                    'revision_id' => (string) $locked->provider_connection_revision_id,
                    'selected_at' => $locked->created_at?->toAtomString() ?? now()->toAtomString(),
                ];
            }

            $failoversUsed = max(0, count($history) - 1);
            if ($failoversUsed >= (int) $pool->max_failover_attempts) {
                $this->recordFailure(
                    $pool,
                    (string) $locked->provider_connection_revision_id,
                    $failureCode,
                );

                throw new InferenceAccessException(
                    'route_failover_exhausted',
                    'No more automatic route retries are available for this request.',
                    409,
                );
            }

            $this->recordFailure(
                $pool,
                (string) $locked->provider_connection_revision_id,
                $failureCode,
            );

            $excludeEntryIds = collect($history)
                ->pluck('entry_id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->push((int) $locked->model_route_pool_entry_id)
                ->unique()
                ->values()
                ->all();

            $next = $this->select(
                $alias,
                $primaryModel,
                $excludeEntryIds,
                (string) $locked->id,
            );

            if (! $next['entry'] || ! $next['pool']) {
                throw new InferenceAccessException(
                    'route_failover_unavailable',
                    'No alternate pooled route is available.',
                    409,
                );
            }

            $history[] = [
                'entry_id' => (int) $next['entry']->id,
                'revision_id' => (string) $next['revision']->id,
                'provider_id' => (int) $next['model']->provider_id,
                'ai_model_id' => (int) $next['model']->id,
                'internal_model_id' => (string) $next['model']->internal_model_id,
                'selected_at' => now()->toAtomString(),
                'previous_failure_code' => mb_substr($failureCode, 0, 100),
                'previous_upstream_status' => $upstreamStatus,
            ];

            $snapshot['route_revision_id'] = (string) $next['revision']->id;
            $snapshot['route_version'] = (int) $next['revision']->route_version;
            $snapshot['route_pool_entry_id'] = (int) $next['entry']->id;
            $snapshot['internal_model_id'] = (string) $next['model']->internal_model_id;
            $snapshot['route_history'] = $history;

            $locked->forceFill([
                'provider_connection_revision_id' => $next['revision']->id,
                'model_route_pool_entry_id' => $next['entry']->id,
                'billing_snapshot' => $snapshot,
            ])->saveOrFail();

            if ($log && $log->state === 'RESERVED') {
                $log->forceFill(['state' => 'CONNECTING'])->save();
            }

            return $next;
        });
    }

    public function markReservationRouteHealthy(Reservation $reservation): void
    {
        if ($reservation->provider_connection_revision_id === null) {
            return;
        }

        DB::transaction(function () use ($reservation): void {
            $health = ProviderRouteHealth::query()
                ->where('provider_connection_revision_id', $reservation->provider_connection_revision_id)
                ->lockForUpdate()
                ->first();

            if (! $health) {
                ProviderRouteHealth::query()->create([
                    'provider_connection_revision_id' => $reservation->provider_connection_revision_id,
                    'consecutive_failures' => 0,
                    'last_success_at' => now(),
                ]);

                return;
            }

            // Avoid a database write on every successful request when the route
            // is already healthy. Refresh last_success_at at most once/minute.
            $needsRefresh = $health->consecutive_failures > 0
                || $health->circuit_open_until !== null
                || $health->last_error_code !== null
                || $health->last_success_at === null
                || $health->last_success_at->lt(now()->subMinute());

            if ($needsRefresh) {
                $health->forceFill([
                    'consecutive_failures' => 0,
                    'circuit_open_until' => null,
                    'last_error_code' => null,
                    'last_success_at' => now(),
                ])->save();
            }
        });
    }

    public function resetCircuit(string $revisionId): ProviderRouteHealth
    {
        return DB::transaction(function () use ($revisionId): ProviderRouteHealth {
            ProviderConnectionRevision::query()->whereKey($revisionId)->firstOrFail();

            $health = ProviderRouteHealth::query()
                ->where('provider_connection_revision_id', $revisionId)
                ->lockForUpdate()
                ->first();

            if (! $health) {
                return ProviderRouteHealth::query()->create([
                    'provider_connection_revision_id' => $revisionId,
                    'consecutive_failures' => 0,
                    'last_success_at' => now(),
                ]);
            }

            $health->forceFill([
                'consecutive_failures' => 0,
                'circuit_open_until' => null,
                'last_error_code' => null,
                'last_success_at' => now(),
            ])->save();

            return $health->fresh();
        });
    }

    private function recordFailure(
        ModelRoutePool $pool,
        string $revisionId,
        string $failureCode,
    ): void {
        $health = ProviderRouteHealth::query()
            ->where('provider_connection_revision_id', $revisionId)
            ->lockForUpdate()
            ->first();

        if (! $health) {
            $health = ProviderRouteHealth::query()->create([
                'provider_connection_revision_id' => $revisionId,
                'consecutive_failures' => 0,
            ]);
        }

        $failures = (int) $health->consecutive_failures + 1;
        $threshold = max(1, (int) $pool->circuit_failure_threshold);

        $health->forceFill([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            'last_error_code' => mb_substr($failureCode, 0, 100),
            'circuit_open_until' => $failures >= $threshold
                ? now()->addSeconds(max(5, (int) $pool->circuit_cooldown_seconds))
                : $health->circuit_open_until,
        ])->save();
    }
}
