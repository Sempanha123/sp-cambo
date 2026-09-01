<?php

namespace App\Services;

use App\Exceptions\InferenceAccessException;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use Illuminate\Support\Collection;

class ModelRoutePoolService
{
    /**
     * Select one READY provider revision for this public model.
     *
     * Selection happens inside the caller's billing transaction. Route-pool rows
     * are locked until the reservation is created, so simultaneous preflights
     * cannot all observe the same stale active-count snapshot.
     */
    public function select(ModelAlias $alias, Provider $provider): ProviderConnectionRevision
    {
        $pool = ModelRoutePool::query()
            ->where('model_alias_id', $alias->id)
            ->lockForUpdate()
            ->first();

        // Backward compatibility: existing installations keep using the provider's
        // single active revision until an Admin explicitly enables a route pool.
        if (! $pool || ! $pool->enabled) {
            return $this->activeRevision($provider);
        }

        if ($pool->strategy !== ModelRoutePool::STRATEGY_LEAST_CONNECTIONS) {
            throw new InferenceAccessException(
                'model_route_strategy_invalid',
                'The selected model route pool is not configured correctly.',
                503,
            );
        }

        $entries = ModelRoutePoolEntry::query()
            ->with('revision')
            ->where('model_route_pool_id', $pool->id)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($entries->isEmpty()) {
            throw new InferenceAccessException(
                'model_route_unavailable',
                'No healthy route is enabled for the selected model.',
                503,
            );
        }

        $entries = $entries->filter(function (ModelRoutePoolEntry $entry) use ($provider): bool {
            $revision = $entry->revision;

            return $revision !== null
                && (string) $revision->provider_id === (string) $provider->id
                && $revision->lifecycle_status === ProviderConnectionRevision::STATUS_READY
                && $revision->last_probe_status === 'SUCCESS';
        })->values();

        if ($entries->isEmpty()) {
            throw new InferenceAccessException(
                'model_route_unavailable',
                'No READY route is available for the selected model.',
                503,
            );
        }

        $revisionIds = $entries
            ->pluck('provider_connection_revision_id')
            ->map(fn ($id): string => (string) $id)
            ->values();

        $activeCounts = Reservation::query()
            ->where('status', 'ACTIVE')
            ->whereIn('provider_connection_revision_id', $revisionIds)
            ->selectRaw('provider_connection_revision_id, COUNT(*) AS active_count')
            ->groupBy('provider_connection_revision_id')
            ->pluck('active_count', 'provider_connection_revision_id');

        $modelActive = $activeCounts->sum(fn ($count): int => (int) $count);
        if ($pool->max_concurrency !== null && $modelActive >= (int) $pool->max_concurrency) {
            throw new InferenceAccessException(
                'model_concurrency_limit_exceeded',
                'The selected model is at its current concurrency limit. Retry shortly.',
                429,
            );
        }

        /** @var Collection<int,array{entry:ModelRoutePoolEntry,active:int,score:float}> $eligible */
        $eligible = $entries->map(function (ModelRoutePoolEntry $entry) use ($activeCounts): ?array {
            $active = (int) ($activeCounts[(string) $entry->provider_connection_revision_id] ?? 0);
            $cap = $entry->max_concurrency === null ? null : (int) $entry->max_concurrency;

            if ($cap !== null && $active >= $cap) {
                return null;
            }

            // Weighted least-connections:
            // - fewer active requests wins;
            // - larger weight allows a stronger route to receive proportionally
            //   more work without exposing a different model name to customers.
            $weight = max(1, (int) $entry->weight);
            $score = ($active + 1) / $weight;

            return [
                'entry' => $entry,
                'active' => $active,
                'score' => $score,
            ];
        })->filter()->values();

        if ($eligible->isEmpty()) {
            throw new InferenceAccessException(
                'model_route_capacity_exceeded',
                'All routes for the selected model are busy. Retry shortly.',
                429,
            );
        }

        $selected = $eligible
            ->sort(function (array $left, array $right): int {
                $score = $left['score'] <=> $right['score'];
                if ($score !== 0) {
                    return $score;
                }

                $priority = (int) $left['entry']->priority <=> (int) $right['entry']->priority;
                if ($priority !== 0) {
                    return $priority;
                }

                // Spread exact ties deterministically across route versions instead
                // of depending on database row order.
                return (int) $right['entry']->revision->route_version
                    <=> (int) $left['entry']->revision->route_version;
            })
            ->first();

        /** @var ProviderConnectionRevision $revision */
        $revision = $selected['entry']->revision;

        return $revision;
    }

    private function activeRevision(Provider $provider): ProviderConnectionRevision
    {
        $revision = $provider->activeConnectionRevision()->first();

        if (! $provider->enabled || ! $revision || ! $revision->isRouteReady()) {
            throw new InferenceAccessException(
                'model_route_unavailable',
                'The selected model route is not ready.',
                503,
            );
        }

        return $revision;
    }
}
