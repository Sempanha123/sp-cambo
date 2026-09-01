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

        $entries = ModelRoutePoolEntry::query()
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

        // A connection revision can be shared by several public aliases. Lock
        // all candidate revisions in deterministic order so concurrent selectors
        // cannot each observe the same free slot and oversubscribe OmniRoute.
        $this->lockRevisions($revisionIds->all());

        // Load route state only after acquiring the revision locks. A provider
        // probe or lifecycle update that completed while we waited is therefore
        // reflected in this selection.
        $entries->load([
            'model.provider',
            'revision.provider',
        ]);

        // Use a locking read so MySQL does not serve a stale REPEATABLE READ
        // snapshot after the selector waited for another request to commit.
        $activeQuery = Reservation::query()
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($alias, $revisionIds): void {
                $query->where('public_model_alias', $alias->public_alias)
                    ->orWhereIn('provider_connection_revision_id', $revisionIds);
            })
            ->orderBy('id')
            ->lockForUpdate();

        if ($ignoreReservationId !== null) {
            $activeQuery->whereKeyNot($ignoreReservationId);
        }

        $activeReservations = $activeQuery->get([
            'id',
            'public_model_alias',
            'provider_connection_revision_id',
        ]);

        $globalActive = $activeReservations
            ->where('public_model_alias', $alias->public_alias)
            ->count();

        if ($pool->max_concurrency !== null
            && $globalActive >= (int) $pool->max_concurrency) {
            throw new InferenceAccessException(
                'model_concurrency_limit_exceeded',
                'The selected model is currently at capacity. Retry shortly.',
                429,
            );
        }

        $routeActive = $activeReservations
            ->filter(fn (Reservation $active): bool => $revisionIds->contains(
                (string) $active->provider_connection_revision_id
            ))
            ->groupBy(fn (Reservation $active): string => (string) $active->provider_connection_revision_id)
            ->map(fn (Collection $rows): int => $rows->count());

        $health = ProviderRouteHealth::query()
            ->whereIn('provider_connection_revision_id', $revisionIds)
            ->lockForUpdate()
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
        $result = DB::transaction(function () use ($reservation, $failureCode, $upstreamStatus): array {
            // Resolve the pool first, then lock pool -> revisions -> reservation.
            // Every selection path uses this order, preventing cross-alias
            // deadlocks when several aliases share one OmniRoute revision.
            $current = Reservation::query()->findOrFail($reservation->id);
            $alias = ModelAlias::query()
                ->where('public_alias', $current->public_model_alias)
                ->firstOrFail();

            $pool = ModelRoutePool::query()
                ->where('model_alias_id', $alias->id)
                ->lockForUpdate()
                ->first();

            if (! $pool || ! $pool->enabled) {
                throw new InferenceAccessException(
                    'route_failover_unavailable',
                    'No alternate route is configured for this request.',
                    409,
                );
            }

            $this->lockPoolRevisions(
                $pool,
                $current->provider_connection_revision_id === null
                    ? null
                    : (string) $current->provider_connection_revision_id,
            );

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

            if ($locked->model_route_pool_entry_id === null
                || $locked->provider_connection_revision_id === null) {
                throw new InferenceAccessException(
                    'route_failover_unavailable',
                    'No alternate route is configured for this request.',
                    409,
                );
            }

            $primaryModel = $alias->model()->with('provider')->firstOrFail();
            $snapshot = is_array($locked->billing_snapshot)
                ? $locked->billing_snapshot
                : [];

            $history = is_array($snapshot['route_history'] ?? null)
                ? array_values($snapshot['route_history'])
                : [];

            if ($history === []) {
                $history[] = [
                    'entry_id' => (int) $locked->model_route_pool_entry_id,
                    'revision_id' => (string) $locked->provider_connection_revision_id,
                    'selected_at' => $locked->created_at?->toAtomString() ?? now()->toAtomString(),
                ];
            }

            $lastRoute = count($history) - 1;
            $history[$lastRoute]['failed_at'] = now()->toAtomString();
            $history[$lastRoute]['failure_code'] = mb_substr($failureCode, 0, 100);
            $history[$lastRoute]['upstream_status'] = $upstreamStatus;
            $snapshot['route_history'] = $history;
            $locked->forceFill(['billing_snapshot' => $snapshot])->saveOrFail();

            $this->recordFailure(
                $pool,
                (string) $locked->provider_connection_revision_id,
                $failureCode,
            );

            $failoversUsed = max(0, count($history) - 1);
            if ($failoversUsed >= (int) $pool->max_failover_attempts) {
                // Return the error from inside the transaction so the circuit
                // failure and route-history audit commit before it is thrown.
                return ['error' => new InferenceAccessException(
                    'route_failover_exhausted',
                    'No more automatic route retries are available for this request.',
                    409,
                )];
            }

            $excludeEntryIds = collect($history)
                ->pluck('entry_id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->push((int) $locked->model_route_pool_entry_id)
                ->unique()
                ->values()
                ->all();

            try {
                $next = $this->select(
                    $alias,
                    $primaryModel,
                    $excludeEntryIds,
                    (string) $locked->id,
                );
            } catch (InferenceAccessException) {
                return ['error' => new InferenceAccessException(
                    'route_failover_unavailable',
                    'No alternate pooled route is available.',
                    409,
                )];
            }

            if (! $next['entry'] || ! $next['pool']) {
                return ['error' => new InferenceAccessException(
                    'route_failover_unavailable',
                    'No alternate pooled route is available.',
                    409,
                )];
            }

            $history[] = [
                'entry_id' => (int) $next['entry']->id,
                'revision_id' => (string) $next['revision']->id,
                'provider_id' => (int) $next['model']->provider_id,
                'ai_model_id' => (int) $next['model']->id,
                'internal_model_id' => (string) $next['model']->internal_model_id,
                'selected_at' => now()->toAtomString(),
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

            return ['route' => $next];
        });

        if (($result['error'] ?? null) instanceof InferenceAccessException) {
            throw $result['error'];
        }

        return $result['route'];
    }

    public function markReservationRouteFailed(
        Reservation $reservation,
        string $failureCode,
        ?int $upstreamStatus = null,
    ): void
    {
        if ($reservation->provider_connection_revision_id === null
            || $reservation->model_route_pool_entry_id === null) {
            return;
        }

        DB::transaction(function () use ($reservation, $failureCode, $upstreamStatus): void {
            $current = Reservation::query()->findOrFail($reservation->id);
            $alias = ModelAlias::query()
                ->where('public_alias', $current->public_model_alias)
                ->first();

            if (! $alias) {
                return;
            }

            $pool = ModelRoutePool::query()
                ->where('model_alias_id', $alias->id)
                ->lockForUpdate()
                ->first();

            if (! $pool) {
                return;
            }

            $this->lockPoolRevisions(
                $pool,
                $current->provider_connection_revision_id === null
                    ? null
                    : (string) $current->provider_connection_revision_id,
            );

            $locked = Reservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($locked->provider_connection_revision_id === null
                || $locked->model_route_pool_entry_id === null) {
                return;
            }

            $snapshot = is_array($locked->billing_snapshot)
                ? $locked->billing_snapshot
                : [];
            $history = is_array($snapshot['route_history'] ?? null)
                ? array_values($snapshot['route_history'])
                : [];

            if ($history !== []) {
                $lastRoute = count($history) - 1;
                $history[$lastRoute]['failed_at'] = now()->toAtomString();
                $history[$lastRoute]['failure_code'] = mb_substr($failureCode, 0, 100);
                $history[$lastRoute]['upstream_status'] = $upstreamStatus;
                $snapshot['route_history'] = $history;
                $locked->forceFill(['billing_snapshot' => $snapshot])->saveOrFail();
            }

            $this->recordFailure(
                $pool,
                (string) $locked->provider_connection_revision_id,
                $failureCode,
            );
        });
    }

    public function markReservationRouteHealthy(Reservation $reservation): void
    {
        if ($reservation->provider_connection_revision_id === null
            || $reservation->model_route_pool_entry_id === null) {
            return;
        }

        DB::transaction(function () use ($reservation): void {
            $expectedRevisionId = (string) $reservation->provider_connection_revision_id;
            $this->lockRevisions([$expectedRevisionId]);

            $locked = Reservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            // A delayed success callback for a route that was already replaced
            // must never clear the circuit state of the new/current route.
            if ((string) $locked->provider_connection_revision_id !== $expectedRevisionId
                || $locked->model_route_pool_entry_id === null) {
                return;
            }

            $health = ProviderRouteHealth::query()
                ->where('provider_connection_revision_id', $expectedRevisionId)
                ->lockForUpdate()
                ->first();

            if (! $health) {
                ProviderRouteHealth::query()->create([
                    'provider_connection_revision_id' => $expectedRevisionId,
                    'consecutive_failures' => 0,
                    'last_success_at' => now(),
                ]);
            } else {
                // Avoid a database write on every successful request when the
                // route is already healthy. Refresh at most once/minute.
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
            }

            $snapshot = is_array($locked->billing_snapshot)
                ? $locked->billing_snapshot
                : [];
            $history = is_array($snapshot['route_history'] ?? null)
                ? array_values($snapshot['route_history'])
                : [];

            if ($history !== []) {
                $lastRoute = count($history) - 1;
                if (($history[$lastRoute]['revision_id'] ?? null) === $expectedRevisionId
                    && ! isset($history[$lastRoute]['succeeded_at'])) {
                    $history[$lastRoute]['succeeded_at'] = now()->toAtomString();
                    $snapshot['route_history'] = $history;
                    $locked->forceFill(['billing_snapshot' => $snapshot])->saveOrFail();
                }
            }
        });
    }

    public function resetCircuit(string $revisionId): ProviderRouteHealth
    {
        return DB::transaction(function () use ($revisionId): ProviderRouteHealth {
            ProviderConnectionRevision::query()
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->firstOrFail();

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

    private function lockPoolRevisions(
        ModelRoutePool $pool,
        ?string $currentRevisionId = null,
    ): void {
        $revisionIds = ModelRoutePoolEntry::query()
            ->where('model_route_pool_id', $pool->id)
            ->pluck('provider_connection_revision_id')
            ->map(fn ($id): string => (string) $id);

        if ($currentRevisionId !== null) {
            $revisionIds->push($currentRevisionId);
        }

        $this->lockRevisions($revisionIds->unique()->values()->all());
    }

    /** @param array<int,string> $revisionIds */
    private function lockRevisions(array $revisionIds): void
    {
        $revisionIds = array_values(array_unique(array_filter(
            $revisionIds,
            fn (string $id): bool => $id !== '',
        )));

        if ($revisionIds === []) {
            return;
        }

        sort($revisionIds, SORT_STRING);

        ProviderConnectionRevision::query()
            ->whereIn('id', $revisionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
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
