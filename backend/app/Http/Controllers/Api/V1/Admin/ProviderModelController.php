<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Provider;
use App\Services\AuditService;
use App\Services\ProviderEndpointService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProviderModelController extends Controller
{
    public function __construct(private readonly ProviderEndpointService $endpoints) {}

    public function index(string $provider): JsonResponse
    {
        $providerModel = Provider::query()->findOrFail($provider);

        $models = $providerModel->models()
            ->withCount('aliases')
            ->orderBy('display_name')
            ->orderBy('internal_model_id')
            ->get();

        return response()->json([
            'data' => $models->map(fn (AiModel $model): array => $this->resource($model)),
        ]);
    }

    /**
     * Discover model ids directly from the provider's ACTIVE READY connection.
     * Secrets remain server-side; only public upstream model ids are returned.
     */
    public function discover(string $provider): JsonResponse
    {
        $providerModel = Provider::query()->with('activeConnectionRevision')->findOrFail($provider);
        $models = $this->discoverUpstreamModels($providerModel);
        $registered = $providerModel->models()->pluck('id', 'internal_model_id');

        return response()->json([
            'data' => collect($models)->map(fn (string $modelId): array => [
                'internal_model_id' => $modelId,
                'display_name' => $this->displayNameFrom($modelId),
                'registered_model_id' => isset($registered[$modelId]) ? (string) $registered[$modelId] : null,
                'already_registered' => isset($registered[$modelId]),
            ])->values(),
        ]);
    }

    /**
     * Import selected model ids discovered from the active provider connection.
     * Imported models are deliberately NOT resale-verified automatically.
     */
    public function import(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $providerModel = Provider::query()->with('activeConnectionRevision')->findOrFail($provider);
        $data = $request->validate([
            'model_ids' => ['required', 'array', 'min:1', 'max:250'],
            'model_ids.*' => ['required', 'string', 'max:191', 'distinct'],
        ]);

        $discovered = $this->discoverUpstreamModels($providerModel);
        $allowed = array_fill_keys($discovered, true);
        $requested = array_values(array_unique(array_map(static fn ($value): string => trim((string) $value), $data['model_ids'])));
        $unknown = array_values(array_filter($requested, static fn (string $id): bool => ! isset($allowed[$id])));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'model_ids' => ['Some selected models are no longer advertised by the active provider: '.implode(', ', array_slice($unknown, 0, 5))],
            ]);
        }

        $created = [];
        $existing = [];

        DB::transaction(function () use ($request, $providerModel, $requested, $audit, &$created, &$existing): void {
            foreach ($requested as $modelId) {
                $model = $providerModel->models()->where('internal_model_id', $modelId)->first();
                if ($model) {
                    $existing[] = $modelId;
                    continue;
                }

                $displayName = $this->displayNameFrom($modelId);
                $model = $providerModel->models()->create([
                    'internal_model_id' => $modelId,
                    'display_name' => $displayName,
                    'family' => $this->familyFrom($modelId),
                    'family_label' => $displayName,
                    'capabilities' => [
                        'streaming' => true,
                        'tools' => false,
                        'vision' => false,
                        'reasoning' => false,
                        'context_tokens' => 200000,
                        'max_output_tokens' => 64000,
                    ],
                    'limits' => [
                        'requests_per_minute' => null,
                        'tokens_per_minute' => null,
                        'concurrency' => null,
                    ],
                    'commercial_resale_verified_at' => null,
                    'enabled' => true,
                ]);

                $created[] = $modelId;
            }

            $audit->record(
                $request->user(),
                'provider_models.imported',
                'provider',
                $providerModel->id,
                'Imported private models discovered from the active provider connection.',
                ['created' => $created, 'already_registered' => $existing]
            );
        });

        $models = $providerModel->models()->withCount('aliases')->orderBy('display_name')->orderBy('internal_model_id')->get();

        return response()->json([
            'data' => [
                'created' => $created,
                'already_registered' => $existing,
                'models' => $models->map(fn (AiModel $model): array => $this->resource($model))->values(),
            ],
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
                'commercial_resale_verified_at' => ($data['commercial_resale_verified'] ?? false) ? now() : null,
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
                    'commercial_resale_verified' => $model->commercial_resale_verified_at !== null,
                ]
            );

            return $model;
        });

        return response()->json(['data' => $this->resource($model->loadCount('aliases'))], 201);
    }

    public function update(Request $request, string $provider, string $model, AuditService $audit): JsonResponse
    {
        $providerModel = Provider::query()->findOrFail($provider);
        $aiModel = $providerModel->models()->whereKey($model)->firstOrFail();
        $data = $request->validate($this->rules($providerModel, $aiModel));

        $updated = DB::transaction(function () use ($request, $providerModel, $aiModel, $data, $audit): AiModel {
            $before = $this->resource($aiModel->loadCount('aliases'));
            $resaleVerified = array_key_exists('commercial_resale_verified', $data)
                ? (bool) $data['commercial_resale_verified']
                : $aiModel->commercial_resale_verified_at !== null;

            $aiModel->update([
                'internal_model_id' => trim($data['internal_model_id']),
                'display_name' => trim($data['display_name']),
                'family' => $this->familyFrom($data['internal_model_id']),
                'family_label' => trim($data['display_name']),
                'capabilities' => $this->capabilities($data['capabilities']),
                'limits' => $this->limits($data['limits']),
                'commercial_resale_verified_at' => $resaleVerified
                    ? ($aiModel->commercial_resale_verified_at ?? now())
                    : null,
            ]);

            $fresh = $aiModel->fresh()->loadCount('aliases');

            $audit->record(
                $request->user(),
                'provider_model.updated',
                'ai_model',
                $aiModel->id,
                'Updated a provider private model.',
                [
                    'provider_id' => $providerModel->id,
                    'before' => $before,
                    'after' => $this->resource($fresh),
                ]
            );

            return $fresh;
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
            $snapshot = $this->resource($aiModel->loadCount('aliases'));

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

    private function discoverUpstreamModels(Provider $provider): array
    {
        $revision = $provider->activeConnectionRevision;
        if (! $revision) {
            throw new HttpResponseException(response()->json([
                'message' => 'Set a READY connection revision active before discovering provider models.',
                'code' => 'active_provider_connection_required',
            ], 409));
        }

        if (! $revision->isRouteReady()) {
            throw new HttpResponseException(response()->json([
                'message' => 'The active provider connection is not READY.',
                'code' => 'active_provider_connection_not_ready',
            ], 409));
        }

        $response = null;
        foreach ($this->endpoints->modelCatalogUrls($revision) as $url) {
            try {
                $response = Http::timeout(max(1, $revision->timeout_ms / 1000))
                    ->acceptJson()
                    ->withToken($revision->credential)
                    ->get($url);
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            if ($response->successful()) {
                break;
            }
        }

        if (! $response instanceof Response || ! $response->successful()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Could not read the provider model catalog from the active connection.',
                'code' => 'provider_model_discovery_failed',
                'upstream_status' => $response?->status(),
            ], 502));
        }

        $payload = $response->json();
        $rows = is_array($payload) && array_is_list($payload)
            ? $payload
            : (is_array($payload['data'] ?? null)
                ? $payload['data']
                : (is_array($payload['models'] ?? null) ? $payload['models'] : []));

        $ids = [];
        foreach ($rows as $row) {
            $id = is_string($row) ? $row : (is_array($row) ? ($row['id'] ?? $row['name'] ?? null) : null);
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        $ids = array_values(array_unique($ids));
        natcasesort($ids);

        return array_values($ids);
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
            'commercial_resale_verified' => ['sometimes', 'boolean'],
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

    private function displayNameFrom(string $internalModelId): string
    {
        $leaf = (string) preg_replace('/^.*\//', '', trim($internalModelId));
        $label = preg_replace('/[-_]+/', ' ', $leaf) ?: $leaf;

        return substr(ucwords(trim($label)), 0, 150);
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
            'commercial_resale_verified' => $model->commercial_resale_verified_at !== null,
            'commercial_resale_verified_at' => $model->commercial_resale_verified_at?->toAtomString(),
            'alias_count' => (int) ($model->aliases_count ?? $model->aliases()->count()),
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
