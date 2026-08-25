<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ModelAlias;
use App\Models\Provider;
use App\Services\AuditService;
use App\Services\TelegramAnnouncementService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProviderAliasController extends Controller
{
    public function index(string $provider): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $aliases = ModelAlias::query()
            ->with('model')
            ->whereHas('model', fn ($query) => $query->where('provider_id', $provider->id))
            ->orderBy('public_alias')
            ->get();

        return response()->json(['data' => $aliases->map(fn (ModelAlias $alias): array => $this->resource($alias, $provider))]);
    }

    public function store(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $data = $request->validate($this->rules($provider));
        $model = AiModel::query()->where('provider_id', $provider->id)->findOrFail($data['model_id']);

        $alias = DB::transaction(function () use ($request, $provider, $model, $data, $audit): ModelAlias {
            $alias = ModelAlias::query()->create([
                'ai_model_id' => $model->id,
                'public_alias' => strtolower(trim($data['public_alias'])),
                'display_name' => trim($data['display_name']),
                'description' => null,
                'capabilities' => $data['capabilities'],
                'limits' => $data['limits'],
                'status' => 'active',
                'enabled' => (bool) $data['enabled'],
                'customer_visible' => (bool) $data['customer_visible'],
            ]);

            $audit->record($request->user(), 'provider_alias.created', 'model_alias', $alias->id,
                'Created provider public alias.', ['provider_id' => $provider->id, 'model_id' => $model->id, 'public_alias' => $alias->public_alias]);

            return $alias;
        });

        return response()->json(['data' => $this->resource($alias->load('model'), $provider)], 201);
    }

    public function update(Request $request, string $provider, string $alias, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $modelAlias = $this->aliasForProvider($provider, $alias);
        $data = $request->validate($this->rules($provider, $modelAlias));
        $model = AiModel::query()->where('provider_id', $provider->id)->findOrFail($data['model_id']);
        $before = $this->resource($modelAlias->load('model'), $provider);

        $modelAlias->update([
            'ai_model_id' => $model->id,
            'public_alias' => strtolower(trim($data['public_alias'])),
            'display_name' => trim($data['display_name']),
            'capabilities' => $data['capabilities'],
            'limits' => $data['limits'],
            'enabled' => (bool) $data['enabled'],
            'customer_visible' => (bool) $data['customer_visible'],
        ]);

        $audit->record($request->user(), 'provider_alias.updated', 'model_alias', $modelAlias->id,
            'Updated provider public alias.', ['before' => $before, 'after' => $this->resource($modelAlias->fresh('model'), $provider)]);

        return response()->json(['data' => $this->resource($modelAlias->fresh('model'), $provider)]);
    }

    public function destroy(Request $request, string $provider, string $alias, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $modelAlias = $this->aliasForProvider($provider, $alias);

        $inPackages = DB::table('model_alias_package')->where('model_alias_id', $modelAlias->id)->exists();
        $onKeys = DB::table('api_key_model_alias')->where('model_alias_id', $modelAlias->id)->exists();
        if ($inPackages || $onKeys) {
            return response()->json([
                'message' => 'This alias is already assigned to a package or API key. Disable it instead of deleting it.',
                'code' => 'provider_alias_in_use',
            ], 409);
        }

        $snapshot = $this->resource($modelAlias->load('model'), $provider);
        $modelAlias->delete();
        $audit->record($request->user(), 'provider_alias.deleted', 'model_alias', (string) $alias,
            'Deleted unused provider public alias.', ['alias' => $snapshot]);

        return response()->json(['data' => ['success' => true]]);
    }

    /**
     * Publish an alias for sale in one explicit admin action. This repairs a
     * legacy READY-but-not-active provider, enables the catalog objects, and
     * records the admin's explicit commercial-resale confirmation.
     */
    public function publish(Request $request, string $provider, string $alias, AuditService $audit, TelegramAnnouncementService $announcements): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $modelAlias = $this->aliasForProvider($provider, $alias);
        $data = $request->validate([
            'confirm_commercial_resale' => ['required', 'accepted'],
        ]);

        $published = DB::transaction(function () use ($request, $provider, $modelAlias, $data, $audit): ModelAlias {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            $lockedAlias = ModelAlias::query()->whereKey($modelAlias->id)->lockForUpdate()->firstOrFail();
            $model = AiModel::query()
                ->where('provider_id', $lockedProvider->id)
                ->whereKey($lockedAlias->ai_model_id)
                ->lockForUpdate()
                ->firstOrFail();

            $active = $lockedProvider->activeConnectionRevision;
            if (! $active || ! $active->isRouteReady()) {
                $ready = $lockedProvider->connectionRevisions()
                    ->where('lifecycle_status', \App\Models\ProviderConnectionRevision::STATUS_READY)
                    ->where('last_probe_status', 'SUCCESS')
                    ->orderByDesc('route_version')
                    ->first();

                if (! $ready) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Probe a connection revision successfully before publishing this model for sale.',
                        'code' => 'provider_ready_connection_required',
                    ], 409));
                }

                $lockedProvider->forceFill(['active_connection_revision_id' => $ready->id])->saveOrFail();
                $active = $ready;
            }

            if (! (bool) $data['confirm_commercial_resale']) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Commercial resale must be explicitly confirmed before publishing this model.',
                    'code' => 'commercial_resale_confirmation_required',
                ], 409));
            }

            $lockedProvider->update(['enabled' => true]);
            $model->update([
                'enabled' => true,
                'commercial_resale_verified_at' => $model->commercial_resale_verified_at ?? now(),
            ]);
            $lockedAlias->update([
                'enabled' => true,
                'customer_visible' => true,
                'status' => 'active',
            ]);

            $audit->record(
                $request->user(),
                'provider_alias.published',
                'model_alias',
                $lockedAlias->id,
                'Published a provider model alias for customer sale after explicit resale confirmation.',
                [
                    'provider_id' => $lockedProvider->id,
                    'model_id' => $model->id,
                    'public_alias' => $lockedAlias->public_alias,
                    'active_connection_revision_id' => $active->id,
                    'commercial_resale_confirmed' => true,
                ]
            );

            return $lockedAlias->fresh('model');
        });

        $announcements->modelPublished($published);

        return response()->json(['data' => $this->resource($published, $provider->fresh())]);
    }

    public function mapModel(Request $request, string $provider, string $alias, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $modelAlias = $this->aliasForProvider($provider, $alias);
        $data = $request->validate(['model_id' => ['required', 'integer']]);
        $model = AiModel::query()->where('provider_id', $provider->id)->findOrFail($data['model_id']);
        $modelAlias->update(['ai_model_id' => $model->id]);
        $audit->record($request->user(), 'provider_alias.model_mapped', 'model_alias', $modelAlias->id,
            'Changed provider alias private model mapping.', ['provider_id' => $provider->id, 'model_id' => $model->id]);

        return response()->json(['data' => $this->resource($modelAlias->fresh('model'), $provider)]);
    }

    private function aliasForProvider(Provider $provider, string $alias): ModelAlias
    {
        return ModelAlias::query()
            ->whereKey($alias)
            ->whereHas('model', fn ($query) => $query->where('provider_id', $provider->id))
            ->firstOrFail();
    }

    private function rules(Provider $provider, ?ModelAlias $existing = null): array
    {
        return [
            'model_id' => ['required', 'integer', Rule::exists('ai_models', 'id')->where('provider_id', $provider->id)],
            'public_alias' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('model_aliases', 'public_alias')->ignore($existing?->id)],
            'display_name' => ['required', 'string', 'max:255'],
            'capabilities' => ['required', 'array'],
            'capabilities.streaming' => ['required', 'boolean'],
            'capabilities.tools' => ['required', 'boolean'],
            'capabilities.vision' => ['required', 'boolean'],
            'capabilities.reasoning' => ['required', 'boolean'],
            'capabilities.messages_api' => ['required', 'boolean'],
            'capabilities.responses_api' => ['required', 'boolean'],
            'capabilities.chat_completions_api' => ['required', 'boolean'],
            'capabilities.context_tokens' => ['required', 'integer', 'min:1'],
            'capabilities.max_output_tokens' => ['required', 'integer', 'min:1'],
            'limits' => ['required', 'array'],
            'limits.requests_per_minute' => ['nullable', 'integer', 'min:1'],
            'limits.tokens_per_minute' => ['nullable', 'integer', 'min:1'],
            'limits.concurrency' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'customer_visible' => ['required', 'boolean'],
        ];
    }

    private function resource(ModelAlias $alias, Provider $provider): array
    {
        $provider->loadMissing('activeConnectionRevision');
        $alias->loadMissing('model');
        $model = $alias->model;
        $blockers = [];

        if (! $provider->enabled) {
            $blockers[] = 'Provider is disabled.';
        }
        if (! $provider->activeConnectionRevision || ! $provider->activeConnectionRevision->isRouteReady()) {
            $blockers[] = 'Provider has no active READY connection.';
        }
        if (! $model || ! $model->enabled) {
            $blockers[] = 'Private model is disabled or missing.';
        } elseif ($model->commercial_resale_verified_at === null) {
            $blockers[] = 'Commercial resale is not verified for the private model.';
        }
        if (! $alias->enabled) {
            $blockers[] = 'Public alias is disabled.';
        }
        if (! $alias->customer_visible) {
            $blockers[] = 'Public alias is hidden from customers.';
        }
        if (! in_array($alias->status, ['active', 'beta'], true)) {
            $blockers[] = 'Public alias lifecycle status is not publishable.';
        }

        return [
            'id' => (string) $alias->id,
            'provider_id' => (string) $provider->id,
            'public_alias' => $alias->public_alias,
            'display_name' => $alias->display_name,
            'capabilities' => is_array($alias->capabilities) ? $alias->capabilities : [],
            'limits' => is_array($alias->limits) ? $alias->limits : [],
            'enabled' => (bool) $alias->enabled,
            'customer_visible' => (bool) $alias->customer_visible,
            'mapped_model_id' => $alias->ai_model_id ? (string) $alias->ai_model_id : null,
            'publication_ready' => $blockers === [],
            'publication_blockers' => $blockers,
            'created_at' => $alias->created_at->toAtomString(),
            'updated_at' => $alias->updated_at->toAtomString(),
        ];
    }
}
