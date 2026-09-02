<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\ProviderConnectionRevision;
use App\Models\ProviderRouteHealth;
use App\Models\Reservation;
use App\Services\AuditService;
use App\Services\ModelRoutePoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModelRoutePoolController extends Controller
{
    public function index(): JsonResponse
    {
        $aliases = ModelAlias::query()
            ->with('model.provider')
            ->orderBy('public_alias')
            ->get();

        $pools = ModelRoutePool::query()
            ->with('entries')
            ->whereIn('model_alias_id', $aliases->pluck('id'))
            ->get()
            ->keyBy('model_alias_id');

        return response()->json(['data' => $aliases->map(function (ModelAlias $alias) use ($pools): array {
            /** @var ModelRoutePool|null $pool */
            $pool = $pools->get($alias->id);

            return [
                'id' => (string) $alias->id,
                'public_alias' => $alias->public_alias,
                'display_name' => $alias->display_name,
                'status' => $alias->status,
                'enabled' => (bool) $alias->enabled,
                'customer_visible' => (bool) $alias->customer_visible,
                'primary_provider' => $alias->model?->provider?->name,
                'route_pool' => [
                    'configured' => $pool !== null,
                    'enabled' => (bool) ($pool?->enabled ?? false),
                    'route_count' => $pool?->entries->where('enabled', true)->count() ?? 0,
                    'max_concurrency' => $pool?->max_concurrency,
                ],
            ];
        })->values()]);
    }

    public function show(ModelAlias $modelAlias): JsonResponse
    {
        $pool = ModelRoutePool::query()
            ->with(['entries.model.provider', 'entries.revision.provider'])
            ->where('model_alias_id', $modelAlias->id)
            ->first();

        $candidates = $this->candidates();
        $revisionIds = collect($candidates)->pluck('revision_id')->unique()->values();

        $active = Reservation::query()
            ->where('status', 'ACTIVE')
            ->whereIn('provider_connection_revision_id', $revisionIds)
            ->selectRaw('provider_connection_revision_id, COUNT(*) AS active_count')
            ->groupBy('provider_connection_revision_id')
            ->pluck('active_count', 'provider_connection_revision_id');

        $health = ProviderRouteHealth::query()
            ->whereIn('provider_connection_revision_id', $revisionIds)
            ->get()
            ->keyBy(fn (ProviderRouteHealth $row): string => (string) $row->provider_connection_revision_id);

        $entryActive = Reservation::query()
            ->where('status', 'ACTIVE')
            ->whereIn('model_route_pool_entry_id', $pool?->entries->pluck('id') ?? collect())
            ->selectRaw('model_route_pool_entry_id, COUNT(*) AS active_count')
            ->groupBy('model_route_pool_entry_id')
            ->pluck('active_count', 'model_route_pool_entry_id');

        return response()->json(['data' => [
            'model' => [
                'id' => (string) $modelAlias->id,
                'public_alias' => $modelAlias->public_alias,
                'display_name' => $modelAlias->display_name,
            ],
            'pool' => [
                'configured' => $pool !== null,
                'enabled' => (bool) ($pool?->enabled ?? false),
                'strategy' => (string) ($pool?->strategy ?? ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS),
                'max_concurrency' => $pool?->max_concurrency,
                'max_failover_attempts' => (int) ($pool?->max_failover_attempts ?? 2),
                'circuit_failure_threshold' => (int) ($pool?->circuit_failure_threshold ?? 3),
                'circuit_cooldown_seconds' => (int) ($pool?->circuit_cooldown_seconds ?? 30),
                'entries' => $pool?->entries->map(function (ModelRoutePoolEntry $entry) use ($active, $entryActive, $health): array {
                    /** @var ProviderRouteHealth|null $routeHealth */
                    $routeHealth = $health->get((string) $entry->provider_connection_revision_id);

                    return [
                        'id' => (string) $entry->id,
                        'ai_model_id' => (string) $entry->ai_model_id,
                        'revision_id' => (string) $entry->provider_connection_revision_id,
                        'enabled' => (bool) $entry->enabled,
                        'weight' => (int) $entry->weight,
                        'max_concurrency' => $entry->max_concurrency,
                        'priority' => (int) $entry->priority,
                        'provider_name' => $entry->model?->provider?->name,
                        'private_model' => $entry->model?->display_name ?: $entry->model?->internal_model_id,
                        'internal_model_id' => $entry->model?->internal_model_id,
                        'route_version' => $entry->revision?->route_version,
                        'connection_type' => $entry->revision?->connection_type,
                        'active_connections' => (int) ($active[(string) $entry->provider_connection_revision_id] ?? 0),
                        'active_entry_connections' => (int) ($entryActive[(string) $entry->id] ?? 0),
                        'health' => $this->healthResource($routeHealth),
                    ];
                })->values() ?? [],
            ],
            'candidates' => collect($candidates)->map(function (array $candidate) use ($active, $health): array {
                /** @var ProviderRouteHealth|null $routeHealth */
                $routeHealth = $health->get((string) $candidate['revision_id']);

                return [
                    ...$candidate,
                    'active_connections' => (int) ($active[(string) $candidate['revision_id']] ?? 0),
                    'health' => $this->healthResource($routeHealth),
                ];
            })->values(),
            'active_model_connections' => Reservation::query()
                ->where('status', 'ACTIVE')
                ->where('public_model_alias', $modelAlias->public_alias)
                ->count(),
        ]]);
    }

    public function update(
        Request $request,
        ModelAlias $modelAlias,
        AuditService $audit,
    ): JsonResponse {
        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'strategy' => ['required', Rule::in([
                ModelRoutePool::STRATEGY_WEIGHTED_LEAST_CONNECTIONS,
            ])],
            'max_concurrency' => ['nullable', 'integer', 'between:1,100000'],
            'max_failover_attempts' => ['required', 'integer', 'between:0,5'],
            'circuit_failure_threshold' => ['required', 'integer', 'between:1,20'],
            'circuit_cooldown_seconds' => ['required', 'integer', 'between:5,900'],
            'entries' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'entries.*.revision_id' => ['required', 'string', 'exists:provider_connection_revisions,id'],
            'entries.*.enabled' => ['required', 'boolean'],
            'entries.*.weight' => ['required', 'integer', 'between:1,1000'],
            'entries.*.max_concurrency' => ['nullable', 'integer', 'between:1,100000'],
            'entries.*.priority' => ['required', 'integer', 'between:0,10000'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $pairs = collect($input['entries'])
            ->map(fn (array $entry): string => $entry['ai_model_id'].'|'.$entry['revision_id']);

        if ($pairs->unique()->count() !== $pairs->count()) {
            throw ValidationException::withMessages([
                'entries' => ['The same private model / route revision pair cannot be added twice.'],
            ]);
        }

        $models = AiModel::query()
            ->with('provider')
            ->whereIn('id', collect($input['entries'])->pluck('ai_model_id'))
            ->get()
            ->keyBy('id');

        $revisions = ProviderConnectionRevision::query()
            ->with('provider')
            ->whereIn('id', collect($input['entries'])->pluck('revision_id'))
            ->get()
            ->keyBy(fn (ProviderConnectionRevision $revision): string => (string) $revision->id);

        foreach ($input['entries'] as $index => $entry) {
            /** @var AiModel|null $model */
            $model = $models->get((int) $entry['ai_model_id']);
            /** @var ProviderConnectionRevision|null $revision */
            $revision = $revisions->get((string) $entry['revision_id']);

            if (! $model || ! $revision
                || (string) $model->provider_id !== (string) $revision->provider_id) {
                throw ValidationException::withMessages([
                    "entries.{$index}.revision_id" => ['The route revision must belong to the selected private model provider.'],
                ]);
            }

            if ((bool) $entry['enabled']
                && (! $model->enabled
                    || $model->commercial_resale_verified_at === null
                    || ! $model->provider?->enabled
                    || ! $revision->isRouteReady()
                    || $revision->last_probe_status !== 'SUCCESS')) {
                throw ValidationException::withMessages([
                    "entries.{$index}" => ['Enabled routes require an enabled resale-verified private model and a successfully probed READY revision.'],
                ]);
            }
        }

        if ((bool) $input['enabled']
            && collect($input['entries'])->where('enabled', true)->isEmpty()) {
            throw ValidationException::withMessages([
                'entries' => ['Enable at least one healthy route before enabling this pool.'],
            ]);
        }

        $before = ModelRoutePool::query()
            ->with('entries')
            ->where('model_alias_id', $modelAlias->id)
            ->first();

        DB::transaction(function () use ($modelAlias, $input): void {
            $pool = ModelRoutePool::query()
                ->where('model_alias_id', $modelAlias->id)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'enabled' => (bool) $input['enabled'],
                'strategy' => $input['strategy'],
                'max_concurrency' => $input['max_concurrency'] ?? null,
                'max_failover_attempts' => (int) $input['max_failover_attempts'],
                'circuit_failure_threshold' => (int) $input['circuit_failure_threshold'],
                'circuit_cooldown_seconds' => (int) $input['circuit_cooldown_seconds'],
            ];

            if ($pool) {
                $pool->forceFill($attributes)->saveOrFail();
            } else {
                $pool = ModelRoutePool::query()->create([
                    'model_alias_id' => $modelAlias->id,
                    ...$attributes,
                ]);
            }

            $keep = [];
            foreach ($input['entries'] as $entry) {
                $row = ModelRoutePoolEntry::query()->updateOrCreate(
                    [
                        'model_route_pool_id' => $pool->id,
                        'ai_model_id' => (int) $entry['ai_model_id'],
                        'provider_connection_revision_id' => $entry['revision_id'],
                    ],
                    [
                        'enabled' => (bool) $entry['enabled'],
                        'weight' => (int) $entry['weight'],
                        'max_concurrency' => $entry['max_concurrency'] ?? null,
                        'priority' => (int) $entry['priority'],
                    ],
                );
                $keep[] = $row->id;
            }

            $removeIds = ModelRoutePoolEntry::query()
                ->where('model_route_pool_id', $pool->id)
                ->whereNotIn('id', $keep)
                ->lockForUpdate()
                ->pluck('id');

            if ($removeIds->isNotEmpty()
                && Reservation::query()
                    ->where('status', 'ACTIVE')
                    ->whereIn('model_route_pool_entry_id', $removeIds)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'entries' => ['A route with active requests cannot be removed. Disable it, wait for active requests to finish, then remove it.'],
                ]);
            }

            if ($removeIds->isNotEmpty()) {
                ModelRoutePoolEntry::query()->whereIn('id', $removeIds)->delete();
            }
        });

        $after = ModelRoutePool::query()
            ->with('entries')
            ->where('model_alias_id', $modelAlias->id)
            ->firstOrFail();

        $audit->record(
            $request->user(),
            'model_route_pool.updated',
            'model_alias',
            $modelAlias->id,
            trim((string) $input['reason']),
            [
                'public_alias' => $modelAlias->public_alias,
                'before' => $this->auditSnapshot($before),
                'after' => $this->auditSnapshot($after),
            ],
        );

        return $this->show($modelAlias);
    }

    public function resetCircuit(
        Request $request,
        ModelAlias $modelAlias,
        ProviderConnectionRevision $revision,
        ModelRoutePoolService $routes,
        AuditService $audit,
    ): JsonResponse {
        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $belongsToPool = ModelRoutePoolEntry::query()
            ->whereHas('pool', fn ($query) => $query->where('model_alias_id', $modelAlias->id))
            ->where('provider_connection_revision_id', $revision->id)
            ->exists();

        if (! $belongsToPool) {
            abort(404);
        }

        $health = $routes->resetCircuit((string) $revision->id);

        $audit->record(
            $request->user(),
            'model_route_pool.circuit_reset',
            'provider_connection_revision',
            $revision->id,
            trim((string) $request->string('reason')),
            ['public_alias' => $modelAlias->public_alias],
        );

        return response()->json(['data' => [
            'success' => true,
            'health' => $this->healthResource($health),
        ]]);
    }

    /** @return array<int,array<string,mixed>> */
    private function candidates(): array
    {
        $models = AiModel::query()
            ->with([
                'provider.connectionRevisions' => fn ($query) => $query
                    ->where('lifecycle_status', ProviderConnectionRevision::STATUS_READY)
                    ->where('last_probe_status', 'SUCCESS')
                    ->orderByDesc('route_version'),
            ])
            ->where('enabled', true)
            ->whereNotNull('commercial_resale_verified_at')
            ->whereHas('provider', fn ($query) => $query->where('enabled', true))
            ->orderBy('provider_id')
            ->orderBy('internal_model_id')
            ->get();

        $rows = [];
        foreach ($models as $model) {
            foreach ($model->provider?->connectionRevisions ?? [] as $revision) {
                $rows[] = [
                    'candidate_key' => $model->id.':'.$revision->id,
                    'ai_model_id' => (string) $model->id,
                    'revision_id' => (string) $revision->id,
                    'provider_id' => (string) $model->provider_id,
                    'provider_name' => $model->provider?->name,
                    'private_model' => $model->display_name ?: $model->internal_model_id,
                    'internal_model_id' => $model->internal_model_id,
                    'route_version' => (int) $revision->route_version,
                    'connection_type' => $revision->connection_type,
                    'timeout_ms' => (int) $revision->timeout_ms,
                    'masked_credential' => $revision->maskedCredential(),
                ];
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function healthResource(?ProviderRouteHealth $health): array
    {
        return [
            'status' => $health?->circuitIsOpen() ? 'CIRCUIT_OPEN' : 'READY',
            'consecutive_failures' => (int) ($health?->consecutive_failures ?? 0),
            'circuit_open_until' => $health?->circuit_open_until?->toAtomString(),
            'last_failure_at' => $health?->last_failure_at?->toAtomString(),
            'last_success_at' => $health?->last_success_at?->toAtomString(),
            'last_error_code' => $health?->last_error_code,
        ];
    }

    /** @return array<string,mixed>|null */
    private function auditSnapshot(?ModelRoutePool $pool): ?array
    {
        if (! $pool) {
            return null;
        }

        return [
            'enabled' => (bool) $pool->enabled,
            'strategy' => $pool->strategy,
            'max_concurrency' => $pool->max_concurrency,
            'max_failover_attempts' => (int) $pool->max_failover_attempts,
            'circuit_failure_threshold' => (int) $pool->circuit_failure_threshold,
            'circuit_cooldown_seconds' => (int) $pool->circuit_cooldown_seconds,
            'entries' => $pool->entries->map(fn (ModelRoutePoolEntry $entry): array => [
                'ai_model_id' => (int) $entry->ai_model_id,
                'revision_id' => (string) $entry->provider_connection_revision_id,
                'enabled' => (bool) $entry->enabled,
                'weight' => (int) $entry->weight,
                'max_concurrency' => $entry->max_concurrency,
                'priority' => (int) $entry->priority,
            ])->values()->all(),
        ];
    }
}
