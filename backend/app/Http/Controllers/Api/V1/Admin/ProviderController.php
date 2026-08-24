<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ProviderController extends Controller
{
    /**
     * List all providers.
     */
    public function index(Request $request): JsonResponse
    {
        $providers = Provider::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $providers->map(fn (Provider $provider) => $this->resource($provider)),
        ]);
    }

    /**
     * Create a new provider.
     */
    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('providers', 'slug')],
            'enabled' => ['required', 'boolean'],
        ]);

        $provider = Provider::query()->create($data);

        $audit->record(
            $request->user(),
            'provider.created',
            'provider',
            $provider->id,
            'Created new provider.',
            [
                'name' => $provider->name,
                'slug' => $provider->slug,
                'enabled' => $provider->enabled,
            ]
        );

        return response()->json([
            'data' => $this->resource($provider),
        ], 201);
    }

    /**
     * Update a provider.
     */
    public function update(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('providers', 'slug')->ignore($provider->id)],
            'enabled' => ['required', 'boolean'],
        ]);

        $previousAttributes = $provider->getAttributes();

        $provider->update($data);

        $audit->record(
            $request->user(),
            'provider.updated',
            'provider',
            $provider->id,
            'Updated provider.',
            [
                'previous' => $previousAttributes,
                'new' => $provider->getAttributes(),
            ]
        );

        return response()->json([
            'data' => $this->resource($provider),
        ]);
    }

    /**
     * Delete a provider when it is not referenced by customer-facing model aliases
     * or historical reservations. Connection revisions and unused private models
     * are configuration owned by the provider and can be safely removed together.
     */
    public function destroy(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);

        $modelIds = $provider->models()->pluck('id');
        $hasAliases = $modelIds->isNotEmpty()
            && \App\Models\ModelAlias::query()->whereIn('ai_model_id', $modelIds)->exists();

        if ($hasAliases) {
            return response()->json([
                'message' => 'This provider still has public model aliases. Remove or remap those aliases before deleting the provider.',
                'code' => 'provider_in_use_by_aliases',
            ], 409);
        }

        $revisionIds = $provider->connectionRevisions()->pluck('id');
        $hasReservations = $revisionIds->isNotEmpty()
            && \App\Models\Reservation::query()->whereIn('provider_connection_revision_id', $revisionIds)->exists();

        if ($hasReservations) {
            return response()->json([
                'message' => 'This provider has historical request reservations. Disable the provider instead of deleting it so billing history remains intact.',
                'code' => 'provider_in_use_by_history',
            ], 409);
        }

        DB::transaction(function () use ($request, $provider, $audit): void {
            $snapshot = $this->resource($provider);

            // The active revision FK is restrictive, so clear it before removing
            // the provider-owned revisions.
            $provider->forceFill(['active_connection_revision_id' => null])->save();
            $provider->connectionRevisions()->delete();
            $provider->models()->delete();

            $audit->record(
                $request->user(),
                'provider.deleted',
                'provider',
                $provider->id,
                'Deleted an unused provider and its configuration.',
                ['provider' => $snapshot]
            );

            $provider->delete();
        });

        return response()->json(['data' => ['success' => true]]);
    }

    /**
     * Transform a provider for API response.
     */
    protected function resource(Provider $provider): array
    {
        return [
            'id' => (string) $provider->id,
            'name' => $provider->name,
            'slug' => $provider->slug,
            'enabled' => (bool) $provider->enabled,
            'active_connection_revision_id' => $provider->active_connection_revision_id ? (string) $provider->active_connection_revision_id : null,
            'created_at' => $provider->created_at->toAtomString(),
            'updated_at' => $provider->updated_at->toAtomString(),
        ];
    }
}
