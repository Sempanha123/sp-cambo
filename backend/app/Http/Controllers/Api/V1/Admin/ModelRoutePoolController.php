<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Models\ModelRoutePool;
use App\Models\ModelRoutePoolEntry;
use App\Models\ProviderConnectionRevision;
use App\Models\Reservation;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModelRoutePoolController extends Controller
{
    public function show(ModelAlias $modelAlias): JsonResponse
    {
        $modelAlias->loadMissing('model.provider');
        $provider = $modelAlias->model?->provider;

        if (! $provider) {
            abort(404);
        }

        $pool = ModelRoutePool::query()
            ->with('entries.revision')
            ->where('model_alias_id', $modelAlias->id)
            ->first();

        $available = $provider->connectionRevisions()
            ->orderByDesc('route_version')
            ->get();

        $activeCounts = Reservation::query()
            ->where('status', 'ACTIVE')
            ->whereIn('provider_connection_revision_id', $available->pluck('id'))
            ->selectRaw('provider_connection_revision_id, COUNT(*) AS active_count')
            ->groupBy('provider_connection_revision_id')
            ->pluck('active_count', 'provider_connection_revision_id');

        return response()->json(['data' => [
            'model' => [
                'id' => (string) $modelAlias->id,
                'public_alias' => $modelAlias->public_alias,
                'display_name' => $modelAlias->display_name,
                'provider_id' => (string) $provider->id,
                'provider_name' => $provider->name,
            ],
            'pool' => [
                'exists' => $pool !== null,
                'enabled' => (bool) ($pool?->enabled ?? false),
                'strategy' => (string) ($pool?->strategy ?? ModelRoutePool::STRATEGY_LEAST_CONNECTIONS),
                'max_concurrency' => $pool?->max_concurrency,
                'entries' => $pool?->entries->map(
                    fn (ModelRoutePoolEntry $entry): array => $this->entryResource(
                        $entry,
                        (int) ($activeCounts[(string) $entry->provider_connection_revision_id] ?? 0),
                    )
                )->values() ?? [],
            ],
            'available_revisions' => $available->map(fn (ProviderConnectionRevision $revision): array => [
                'id' => (string) $revision->id,
                'route_version' => (int) $revision->route_version,
                'connection_type' => $revision->connection_type,
                'lifecycle_status' => $revision->lifecycle_status,
                'last_probe_status' => $revision->last_probe_status,
                'timeout_ms' => (int) $revision->timeout_ms,
                'masked_credential' => $revision->maskedCredential(),
                'active_connections' => (int) ($activeCounts[(string) $revision->id] ?? 0),
                'is_legacy_active' => (string) $provider->active_connection_revision_id === (string) $revision->id,
            ])->values(),
        ]]);
    }

    public function update(
        Request $request,
        ModelAlias $modelAlias,
        AuditService $audit,
    ): JsonResponse {
        $modelAlias->loadMissing('model.provider');
        $provider = $modelAlias->model?->provider;

        if (! $provider) {
            abort(404);
        }

        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'strategy' => ['required', Rule::in([ModelRoutePool::STRATEGY_LEAST_CONNECTIONS])],
            'max_concurrency' => ['nullable', 'integer', 'between:1,10000'],
            'entries' => ['required', 'array', 'min:1', 'max:20'],
            'entries.*.revision_id' => ['required', 'string', 'distinct', 'exists:provider_connection_revisions,id'],
            'entries.*.enabled' => ['required', 'boolean'],
            'entries.*.weight' => ['required', 'integer', 'between:1,1000'],
            'entries.*.max_concurrency' => ['nullable', 'integer', 'between:1,10000'],
            'entries.*.priority' => ['required', 'integer', 'between:0,10000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $revisionIds = collect($input['entries'])->pluck('revision_id')->values();
        $revisions = ProviderConnectionRevision::query()
            ->whereIn('id', $revisionIds)
            ->get()
            ->keyBy(fn (ProviderConnectionRevision $revision): string => (string) $revision->id);

        foreach ($input['entries'] as $index => $entry) {
            /** @var ProviderConnectionRevision|null $revision */
            $revision = $revisions->get((string) $entry['revision_id']);

            if (! $revision || (string) $revision->provider_id !== (string) $provider->id) {
                throw ValidationException::withMessages([
                    "entries.{$index}.revision_id" => ['Every route must belong to the model provider.'],
                ]);
            }

            if ((bool) $entry['enabled']
                && ($revision->lifecycle_status !== ProviderConnectionRevision::STATUS_READY
                    || $revision->last_probe_status !== 'SUCCESS')) {
                throw ValidationException::withMessages([
                    "entries.{$index}.revision_id" => ['Only successfully probed READY revisions can be enabled in a live route pool.'],
                ]);
            }
        }

        if ((bool) $input['enabled']
            && collect($input['entries'])->where('enabled', true)->isEmpty()) {
            throw ValidationException::withMessages([
                'entries' => ['Enable at least one READY route before enabling the pool.'],
            ]);
        }

        $before = ModelRoutePool::query()
            ->with('entries')
            ->where('model_alias_id', $modelAlias->id)
            ->first();

        $pool = DB::transaction(function () use ($modelAlias, $input): ModelRoutePool {
            $pool = ModelRoutePool::query()->updateOrCreate(
                ['model_alias_id' => $modelAlias->id],
                [
                    'enabled' => (bool) $input['enabled'],
                    'strategy' => $input['strategy'],
                    'max_concurrency' => $input['max_concurrency'] ?? null,
                ],
            );

            $keep = [];
            foreach ($input['entries'] as $entry) {
                $row = ModelRoutePoolEntry::query()->updateOrCreate(
                    [
                        'model_route_pool_id' => $pool->id,
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

            ModelRoutePoolEntry::query()
                ->where('model_route_pool_id', $pool->id)
                ->whereNotIn('id', $keep)
                ->delete();

            return $pool->fresh('entries.revision');
        });

        $audit->record(
            $request->user(),
            'model_route_pool.updated',
            'model_alias',
            $modelAlias->id,
            trim((string) $input['reason']),
            [
                'public_alias' => $modelAlias->public_alias,
                'before' => $before ? [
                    'enabled' => (bool) $before->enabled,
                    'strategy' => $before->strategy,
                    'max_concurrency' => $before->max_concurrency,
                    'entries' => $before->entries->map(fn (ModelRoutePoolEntry $entry): array => [
                        'revision_id' => (string) $entry->provider_connection_revision_id,
                        'enabled' => (bool) $entry->enabled,
                        'weight' => (int) $entry->weight,
                        'max_concurrency' => $entry->max_concurrency,
                        'priority' => (int) $entry->priority,
                    ])->values()->all(),
                ] : null,
                'after' => [
                    'enabled' => (bool) $pool->enabled,
                    'strategy' => $pool->strategy,
                    'max_concurrency' => $pool->max_concurrency,
                    'entries' => $pool->entries->map(fn (ModelRoutePoolEntry $entry): array => [
                        'revision_id' => (string) $entry->provider_connection_revision_id,
                        'enabled' => (bool) $entry->enabled,
                        'weight' => (int) $entry->weight,
                        'max_concurrency' => $entry->max_concurrency,
                        'priority' => (int) $entry->priority,
                    ])->values()->all(),
                ],
            ],
        );

        return $this->show($modelAlias);
    }

    /** @return array<string,mixed> */
    private function entryResource(ModelRoutePoolEntry $entry, int $active): array
    {
        return [
            'id' => (string) $entry->id,
            'revision_id' => (string) $entry->provider_connection_revision_id,
            'enabled' => (bool) $entry->enabled,
            'weight' => (int) $entry->weight,
            'max_concurrency' => $entry->max_concurrency,
            'priority' => (int) $entry->priority,
            'active_connections' => $active,
            'revision' => $entry->revision ? [
                'route_version' => (int) $entry->revision->route_version,
                'connection_type' => $entry->revision->connection_type,
                'lifecycle_status' => $entry->revision->lifecycle_status,
                'last_probe_status' => $entry->revision->last_probe_status,
            ] : null,
        ];
    }
}
