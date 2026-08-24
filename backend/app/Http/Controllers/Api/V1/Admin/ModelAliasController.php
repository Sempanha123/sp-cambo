<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ModelAliasController extends Controller
{
    /**
     * List all model aliases.
     */
    public function index(): JsonResponse
    {
        $aliases = ModelAlias::query()
            ->with(['model.provider', 'pricing'])
            ->orderBy('public_alias')
            ->get();

        return response()->json(['data' => $aliases->map(fn (ModelAlias $alias) => $this->resource($alias))]);
    }

    /**
     * Create a new model alias.
     */
    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'ai_model_id' => ['required', 'string', 'exists:ai_models,id'],
            'public_alias' => ['required', 'string', 'max:100', 'unique:model_aliases,public_alias'],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string'],
            'limits' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'deprecated', 'beta'])],
            'enabled' => ['boolean'],
            'customer_visible' => ['boolean'],
        ]);

        $alias = DB::transaction(function () use ($data, $audit): ModelAlias {
            $aiModel = AiModel::query()->findOrFail($data['ai_model_id']);

            $alias = ModelAlias::query()->create([
                'ai_model_id' => $aiModel->id,
                'public_alias' => $data['public_alias'],
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'capabilities' => $data['capabilities'] ?? [],
                'limits' => $data['limits'] ?? [],
                'status' => $data['status'] ?? 'active',
                'enabled' => $data['enabled'] ?? false,
                'customer_visible' => $data['customer_visible'] ?? false,
            ]);

            $audit->record(
                $request->user(),
                'model_alias.created',
                'model_alias',
                $alias->id,
                'Created new model alias.',
                ['public_alias' => $alias->public_alias, 'ai_model_id' => $aiModel->id]
            );

            return $alias;
        });

        return response()->json(['data' => $this->resource($alias->load('model.provider', 'pricing'))], 201);
    }

    /**
     * Show a single model alias.
     */
    public function show(Request $request, ModelAlias $modelAlias): JsonResponse
    {
        return response()->json(['data' => $this->resource($modelAlias->load('model.provider', 'pricing'))]);
    }

    /**
     * Update a model alias.
     */
    public function update(Request $request, ModelAlias $modelAlias, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'ai_model_id' => ['sometimes', 'string', 'exists:ai_models,id'],
            'public_alias' => ['sometimes', 'string', 'max:100', 'unique:model_aliases,public_alias,'.$modelAlias->id],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string'],
            'limits' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'deprecated', 'beta'])],
            'enabled' => ['boolean'],
            'customer_visible' => ['boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $reason = $data['reason'];
        unset($data['reason']);

        $alias = DB::transaction(function () use ($modelAlias, $data, $reason, $audit): ModelAlias {
            $before = $modelAlias->only([
                'ai_model_id', 'public_alias', 'display_name', 'description',
                'capabilities', 'limits', 'status', 'enabled', 'customer_visible',
            ]);

            if (isset($data['ai_model_id'])) {
                $aiModel = AiModel::query()->findOrFail($data['ai_model_id']);
                $data['ai_model_id'] = $aiModel->id;
            }

            $modelAlias->update($data);

            $audit->record(
                $request->user(),
                'model_alias.updated',
                'model_alias',
                $modelAlias->id,
                $reason,
                ['before' => $before, 'after' => $modelAlias->only(array_keys($data))]
            );

            return $modelAlias->fresh('model.provider', 'pricing');
        });

        return response()->json(['data' => $this->resource($alias)]);
    }

    /**
     * Delete a model alias.
     */
    public function destroy(Request $request, ModelAlias $modelAlias, AuditService $audit): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $aliasData = $this->resource($modelAlias);

        DB::transaction(function () use ($modelAlias, $request, $audit) {
            $audit->record(
                $request->user(),
                'model_alias.deleted',
                'model_alias',
                $modelAlias->id,
                $request->input('reason'),
                ['alias' => $aliasData]
            );

            $modelAlias->delete();
        });

        return response()->json(['data' => ['success' => true]]);
    }

    /**
     * Transform a model alias for API response.
     */
    private function resource(ModelAlias $alias): array
    {
        return [
            'id' => (string) $alias->id,
            'ai_model_id' => (string) $alias->ai_model_id,
            'public_alias' => $alias->public_alias,
            'display_name' => $alias->display_name,
            'description' => $alias->description,
            'capabilities' => $alias->capabilities,
            'limits' => $alias->limits,
            'status' => $alias->status,
            'enabled' => (bool) $alias->enabled,
            'customer_visible' => (bool) $alias->customer_visible,
            'model' => $alias->model ? [
                'id' => (string) $alias->model->id,
                'internal_model_id' => $alias->model->internal_model_id,
                'family' => $alias->model->family,
                'family_label' => $alias->model->family_label,
                'provider' => $alias->model->provider ? [
                    'id' => (string) $alias->model->provider->id,
                    'name' => $alias->model->provider->name,
                    'slug' => $alias->model->provider->slug,
                ] : null,
            ] : null,
            'pricing' => $alias->pricing ? [
                'currency' => $alias->pricing->currency,
                'exponent' => (int) $alias->pricing->exponent,
                'input_per_million_minor' => (string) $alias->pricing->input_per_million_minor,
                'output_per_million_minor' => (string) $alias->pricing->output_per_million_minor,
                'upstream_input_per_million_minor' => $alias->pricing->upstream_input_per_million_minor === null ? null : (string) $alias->pricing->upstream_input_per_million_minor,
                'upstream_output_per_million_minor' => $alias->pricing->upstream_output_per_million_minor === null ? null : (string) $alias->pricing->upstream_output_per_million_minor,
                'upstream_cost_verified_at' => $alias->pricing->upstream_cost_verified_at?->toAtomString(),
            ] : null,
            'created_at' => $alias->created_at->toAtomString(),
            'updated_at' => $alias->updated_at->toAtomString(),
        ];
    }
}
