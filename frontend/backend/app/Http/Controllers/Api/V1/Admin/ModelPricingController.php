<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModelPricingController extends Controller
{
    public function index(): JsonResponse
    {
        $aliases = ModelAlias::query()
            ->with(['pricing', 'model.provider.activeConnectionRevision'])
            ->orderBy('public_alias')
            ->get();

        return response()->json(['data' => $aliases->map(fn (ModelAlias $alias): array => $this->resource($alias, $alias->pricing))]);
    }

    public function update(Request $request, ModelAlias $modelAlias, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'exponent' => ['required', 'integer', 'between:0,6'],
            'input_per_million_minor' => ['required', 'integer', 'min:0'],
            'output_per_million_minor' => ['required', 'integer', 'min:0'],
            'cache_read_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'cache_write_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'reasoning_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'upstream_input_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'upstream_output_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'upstream_cache_read_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'upstream_cache_write_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'upstream_reasoning_per_million_minor' => ['nullable', 'integer', 'min:0'],
            'upstream_cost_verified_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $reason = $data['reason'];
        unset($data['reason']);

        $pricing = DB::transaction(function () use ($request, $modelAlias, $data, $reason, $audit) {
            $before = $modelAlias->pricing?->only(array_keys($data));
            $pricing = $modelAlias->pricing()->updateOrCreate([], $data);
            $audit->record($request->user(), 'model_pricing.updated', 'model_alias', $modelAlias->id, $reason, ['before' => $before, 'after' => $pricing->only(array_keys($data))]);

            return $pricing;
        });

        return response()->json(['data' => $this->resource($modelAlias->loadMissing('model.provider.activeConnectionRevision'), $pricing)]);
    }

    /**
     * Verify upstream cost for a model alias pricing.
     */
    public function verifyUpstreamCost(Request $request, ModelAlias $modelAlias, AuditService $audit): JsonResponse
    {
        $pricing = $modelAlias->pricing;

        if (! $pricing) {
            return response()->json([
                'message' => 'No pricing record exists for this model alias.',
                'code' => 'pricing_not_found',
            ], 404);
        }

        if ($pricing->upstream_input_per_million_minor === null && $pricing->upstream_output_per_million_minor === null) {
            return response()->json([
                'message' => 'Upstream costs have not been set for this model alias.',
                'code' => 'upstream_cost_not_set',
            ], 422);
        }

        $pricing->update([
            'upstream_cost_verified_at' => now(),
            'upstream_cost_verified_by' => $request->user()->id,
        ]);

        $audit->record(
            $request->user(),
            'model_pricing.upstream_cost_verified',
            'model_alias',
            $modelAlias->id,
            'Verified upstream cost for pricing.',
            [
                'upstream_input_per_million_minor' => $pricing->upstream_input_per_million_minor,
                'upstream_output_per_million_minor' => $pricing->upstream_output_per_million_minor,
            ]
        );

        return response()->json([
            'data' => [
                'success' => true,
                'verified_at' => $pricing->upstream_cost_verified_at?->toAtomString(),
            ],
        ]);
    }

    private function resource(ModelAlias $alias, $pricing): array
    {
        $alias->loadMissing('model.provider.activeConnectionRevision');
        $model = $alias->model;
        $provider = $model?->provider;
        $blockers = [];

        if (! $alias->enabled) {
            $blockers[] = 'Alias disabled';
        }
        if (! $alias->customer_visible) {
            $blockers[] = 'Alias hidden';
        }
        if (! in_array($alias->status, ['active', 'beta'], true)) {
            $blockers[] = 'Alias lifecycle not publishable';
        }
        if (! $model || ! $model->enabled) {
            $blockers[] = 'Private model unavailable';
        } elseif ($model->commercial_resale_verified_at === null) {
            $blockers[] = 'Resale not verified';
        }
        if (! $provider || ! $provider->enabled) {
            $blockers[] = 'Provider disabled';
        } elseif (! $provider->activeConnectionRevision || ! $provider->activeConnectionRevision->isRouteReady()) {
            $blockers[] = 'No active READY connection';
        }

        return [
            'id' => (string) $alias->id,
            'public_alias' => $alias->public_alias,
            'display_name' => $alias->display_name,
            'status' => $alias->status,
            'enabled' => (bool) $alias->enabled,
            'customer_visible' => (bool) $alias->customer_visible,
            'publication_ready' => $blockers === [],
            'publication_blockers' => $blockers,
            'currency' => $pricing?->currency,
            'exponent' => $pricing === null ? null : (int) $pricing->exponent,
            'sell' => $pricing === null ? null : ['input_per_million_minor' => (string) $pricing->input_per_million_minor, 'output_per_million_minor' => (string) $pricing->output_per_million_minor, 'cache_read_per_million_minor' => $pricing->cache_read_per_million_minor === null ? null : (string) $pricing->cache_read_per_million_minor, 'cache_write_per_million_minor' => $pricing->cache_write_per_million_minor === null ? null : (string) $pricing->cache_write_per_million_minor, 'reasoning_per_million_minor' => $pricing->reasoning_per_million_minor === null ? null : (string) $pricing->reasoning_per_million_minor],
            'upstream_cost' => $pricing === null ? null : ['input_per_million_minor' => $pricing->upstream_input_per_million_minor === null ? null : (string) $pricing->upstream_input_per_million_minor, 'output_per_million_minor' => $pricing->upstream_output_per_million_minor === null ? null : (string) $pricing->upstream_output_per_million_minor, 'cache_read_per_million_minor' => $pricing->upstream_cache_read_per_million_minor === null ? null : (string) $pricing->upstream_cache_read_per_million_minor, 'cache_write_per_million_minor' => $pricing->upstream_cache_write_per_million_minor === null ? null : (string) $pricing->upstream_cache_write_per_million_minor, 'reasoning_per_million_minor' => $pricing->upstream_reasoning_per_million_minor === null ? null : (string) $pricing->upstream_reasoning_per_million_minor, 'verified_at' => $pricing->upstream_cost_verified_at?->toAtomString()],
        ];
    }
}
