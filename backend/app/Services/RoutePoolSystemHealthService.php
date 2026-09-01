<?php

namespace App\Services;

use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\ProviderConnectionRevision;
use App\Models\ProviderRouteHealth;
use App\Models\Reservation;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RoutePoolSystemHealthService extends SystemHealthService
{
    public function measure(): array
    {
        $health = parent::measure();

        if (! Schema::hasTable('model_route_pools')
            || ! Schema::hasTable('model_route_pool_entries')) {
            return $health;
        }

        try {
            $enabledPools = ModelRoutePool::query()
                ->where('enabled', true)
                ->count();

            if ($enabledPools === 0) {
                return $health;
            }

            $entries = ModelRoutePoolEntry::query()
                ->with(['model.provider', 'revision.provider'])
                ->where('enabled', true)
                ->whereHas('pool', fn ($query) => $query->where('enabled', true))
                ->get();

            $revisionIds = $entries
                ->pluck('provider_connection_revision_id')
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values();

            $routeHealth = ProviderRouteHealth::query()
                ->whereIn('provider_connection_revision_id', $revisionIds)
                ->get()
                ->keyBy(fn (ProviderRouteHealth $row): string => (string) $row->provider_connection_revision_id);

            $healthy = $entries->filter(function (ModelRoutePoolEntry $entry) use ($routeHealth): bool {
                $model = $entry->model;
                $provider = $model?->provider;
                $revision = $entry->revision;
                $circuit = $routeHealth->get((string) $entry->provider_connection_revision_id);

                return $model !== null
                    && $provider !== null
                    && $revision !== null
                    && $model->enabled
                    && $model->commercial_resale_verified_at !== null
                    && $provider->enabled
                    && $revision->lifecycle_status === ProviderConnectionRevision::STATUS_READY
                    && $revision->last_probe_status === 'SUCCESS'
                    && ! ($circuit?->circuitIsOpen() ?? false);
            })->count();

            $openCircuits = $routeHealth
                ->filter(fn (ProviderRouteHealth $row): bool => $row->circuitIsOpen())
                ->count();

            $active = Reservation::query()
                ->where('status', 'ACTIVE')
                ->whereNotNull('model_route_pool_entry_id')
                ->count();

            $total = $entries->count();
            $routingStatus = $total === 0 || $healthy === 0
                ? 'outage'
                : ($healthy < $total ? 'degraded' : 'operational');

            $replacement = [
                'key' => 'omniroute',
                'label' => 'Inference routing',
                'status' => $routingStatus,
                'detail' => "{$healthy}/{$total} pooled route(s) healthy · {$active} active request(s)"
                    .($openCircuits > 0 ? " · {$openCircuits} circuit(s) open" : ''),
                'healthy_routes' => $healthy,
                'total_routes' => $total,
                'active_requests' => $active,
                'open_circuits' => $openCircuits,
                'enabled_pools' => $enabledPools,
            ];

            $components = collect($health['components'])
                ->reject(fn (array $component): bool => ($component['key'] ?? null) === 'omniroute')
                ->push($replacement)
                ->values()
                ->all();

            $health['components'] = $components;
            $health['overall'] = $this->routingOverall($components);

            return $health;
        } catch (Throwable) {
            return $health;
        }
    }

    /** @param array<int,array<string,mixed>> $components */
    private function routingOverall(array $components): string
    {
        foreach (['outage', 'degraded', 'maintenance'] as $status) {
            foreach ($components as $component) {
                if (($component['status'] ?? 'outage') === $status) {
                    return $status === 'maintenance' ? 'degraded' : $status;
                }
            }
        }

        return 'operational';
    }
}
