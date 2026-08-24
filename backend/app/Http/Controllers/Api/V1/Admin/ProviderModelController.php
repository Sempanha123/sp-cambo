<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Provider;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProviderModelController extends Controller
{
    public function index(string $provider): JsonResponse
    {
        $providerModel = Provider::query()->findOrFail($provider);

        $models = $providerModel->models()
            ->orderBy('display_name')
            ->orderBy('internal_model_id')
            ->get();

        return response()->json([
            'data' => $models->map(fn (AiModel $model): array => $this->resource($model)),
        ]);
    }

    public function store(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $providerModel = Provider::query()->findOrFail($provider);
        $data = $request->validate($this->rules($providerModel));

        $model = DB::transaction(function () use ($request, $providerModel, $data, $audit): AiModel {
            $model = $providerModel->models()->create([
                'internal_model_id' => trim($data['internal_model_id']),
                'display_name' => trim($data['display_name']),
                'family' => $this->familyFrom($data['internal_model_id']),
                'family_label' => trim($data['display_name']),
                'capabilities' => $this->capabilities($data['capabilities']),
                'limits' => $this->limits($data['limits']),
                'enabled' => true,
            ]);

            $audit->record(
                $request->user(),
                'provider_model.created',
                'ai_model',
                $model->id,
                'Created a provider private model.',
                [
                    'provider_id' => $providerModel->id,
                    'internal_model_id' => $model->internal_model_id,
                ]
            );

            return $model;
        });

        return response()->json(['data' => $this->resource($model)], 201);
    }

    public function update(Request $request, string $provider, string $model, AuditService $audit): JsonResponse
    {
        $providerModel = Provider::query()->findOrFail($provider);
        $aiModel = $providerModel->models()->whereKey($model)->firstOrFail();
        $data = $request->validate($this->rules($providerModel, $aiModel));

        $updated = DB::transaction(function () use ($request, $providerModel, $aiModel, $data, $audit): AiModel {
            $before = $aiModel->only(['internal_model_id', 'display_name', 'family', 'family_label', 'capabilities', 'limits']);

            $aiModel->update([
                'internal_model_id' => trim($data['internal_model_id']),
                'display_name' => trim($data['display_name']),
                'family' => $this->familyFrom($data['internal_model_id']),
                'family_label' => trim($data['display_name']),
                'capabilities' => $this->capabilities($data['capabilities']),
                'limits' => $this->limits($data['limits']),
            ]);

            $audit->record(
                $request->user(),
                'provider_model.updated',
                'ai_model',
                $aiModel->id,
                'Updated a provider private model.',
                [
                    'provider_id' => $providerModel->id,
                    'before' => $before,
                    'after' => $aiModel->fresh()->only(['internal_model_id', 'display_name', 'family', 'family_label', 'capabilities', 'limits']),
                ]
            );

            return $aiModel->fresh();
        });

        return response()->json(['data' => $this->resource($updated)]);
    }

    public function destroy(Request $request, string $provider, string $model, AuditService $audit): JsonResponse
    {
        $providerModel = Provider::query()->findOrFail($provider);
        $aiModel = $providerModel->models()->whereKey($model)->firstOrFail();

        if ($aiModel->aliases()->exists()) {
            return response()->json([
                'message' => 'This private model still has public model aliases. Remove or remap those aliases before deleting the private model.',
                'code' => 'provider_model_in_use',
            ], 409);
        }

        DB::transaction(function () use ($request, $providerModel, $aiModel, $audit): void {
            $snapshot = $this->resource($aiModel);

            $audit->record(
                $request->user(),
                'provider_model.deleted',
                'ai_model',
                $aiModel->id,
                'Deleted a provider private model.',
                ['provider_id' => $providerModel->id, 'model' => $snapshot]
            );

            $aiModel->delete();
        });

        return response()->json(['data' => ['success' => true]]);
    }

    private function rules(Provider $provider, ?AiModel $model = null): array
    {
        return [
            'internal_model_id' => [
                'required',
                'string',
                'max:191',
                Rule::unique('ai_models', 'internal_model_id')
                    ->where(fn ($query) => $query->where('provider_id', $provider->id))
                    ->ignore($model?->id),
            ],
            'display_name' => ['required', 'string', 'max:150'],
            'capabilities' => ['required', 'array'],
            'capabilities.streaming' => ['required', 'boolean'],
            'capabilities.tools' => ['required', 'boolean'],
            'capabilities.vision' => ['required', 'boolean'],
            'capabilities.reasoning' => ['required', 'boolean'],
            'capabilities.context_tokens' => ['required', 'integer', 'min:1', 'max:10000000'],
            'capabilities.max_output_tokens' => ['required', 'integer', 'min:1', 'max:1000000'],
            'limits' => ['required', 'array'],
            'limits.requests_per_minute' => ['nullable', 'integer', 'min:1'],
            'limits.tokens_per_minute' => ['nullable', 'integer', 'min:1'],
            'limits.concurrency' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function capabilities(array $capabilities): array
    {
        return [
            'streaming' => (bool) $capabilities['streaming'],
            'tools' => (bool) $capabilities['tools'],
            'vision' => (bool) $capabilities['vision'],
            'reasoning' => (bool) $capabilities['reasoning'],
            'context_tokens' => (int) $capabilities['context_tokens'],
            'max_output_tokens' => (int) $capabilities['max_output_tokens'],
        ];
    }

    private function limits(array $limits): array
    {
        return [
            'requests_per_minute' => isset($limits['requests_per_minute']) ? (int) $limits['requests_per_minute'] : null,
            'tokens_per_minute' => isset($limits['tokens_per_minute']) ? (int) $limits['tokens_per_minute'] : null,
            'concurrency' => isset($limits['concurrency']) ? (int) $limits['concurrency'] : null,
        ];
    }

    private function familyFrom(string $internalModelId): string
    {
        $leaf = strtolower((string) preg_replace('/^.*\//', '', trim($internalModelId)));
        $family = preg_replace('/[-_.]?\d.*$/', '', $leaf) ?: $leaf;
        $family = preg_replace('/[^a-z0-9_-]+/', '-', $family) ?: 'model';

        return substr(trim($family, '-_'), 0, 100) ?: 'model';
    }

    private function resource(AiModel $model): array
    {
        $capabilities = is_array($model->capabilities) ? $model->capabilities : [];
        $limits = is_array($model->limits) ? $model->limits : [];

        return [
            'id' => (string) $model->id,
            'provider_id' => (string) $model->provider_id,
            'internal_model_id' => $model->internal_model_id,
            'display_name' => $model->display_name ?: $model->family_label,
            'capabilities' => [
                'streaming' => (bool) ($capabilities['streaming'] ?? false),
                'tools' => (bool) ($capabilities['tools'] ?? false),
                'vision' => (bool) ($capabilities['vision'] ?? false),
                'reasoning' => (bool) ($capabilities['reasoning'] ?? false),
                'context_tokens' => (int) ($capabilities['context_tokens'] ?? 200000),
                'max_output_tokens' => (int) ($capabilities['max_output_tokens'] ?? 64000),
            ],
            'limits' => [
                'requests_per_minute' => isset($limits['requests_per_minute']) ? (int) $limits['requests_per_minute'] : null,
                'tokens_per_minute' => isset($limits['tokens_per_minute']) ? (int) $limits['tokens_per_minute'] : null,
                'concurrency' => isset($limits['concurrency']) ? (int) $limits['concurrency'] : null,
            ],
            'created_at' => $model->created_at->toAtomString(),
            'updated_at' => $model->updated_at->toAtomString(),
        ];
    }
}
