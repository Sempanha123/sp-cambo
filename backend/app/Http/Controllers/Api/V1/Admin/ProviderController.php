<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelAlias;
use App\Models\Package;
use App\Models\PlaygroundSetting;
use App\Models\Provider;
use App\Models\RedeemCode;
use App\Models\Reservation;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
     * Delete a provider. A normal delete is conservative and refuses to remove
     * customer-facing aliases. Admins can explicitly request cascade=1 to remove
     * provider-owned catalog configuration and detach it from packages/API keys.
     * Historical request reservations are never destroyed by this endpoint.
     */
    public function destroy(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $cascade = filter_var($request->query('cascade', false), FILTER_VALIDATE_BOOLEAN);

        $modelIds = $provider->models()->pluck('id');
        $aliases = $modelIds->isEmpty()
            ? collect()
            : ModelAlias::query()->whereIn('ai_model_id', $modelIds)->get();

        if ($aliases->isNotEmpty() && ! $cascade) {
            return response()->json([
                'message' => 'This provider still has public model aliases. Enable cascade deletion to remove the provider configuration and detach those aliases from packages/API keys.',
                'code' => 'provider_in_use_by_aliases',
                'data' => [
                    'alias_count' => $aliases->count(),
                    'cascade_available' => true,
                ],
            ], 409);
        }

        $revisionIds = $provider->connectionRevisions()->pluck('id');
        $hasReservations = $revisionIds->isNotEmpty()
            && Reservation::query()->whereIn('provider_connection_revision_id', $revisionIds)->exists();

        if ($hasReservations) {
            return response()->json([
                'message' => 'This provider has historical request reservations. Disable the provider instead of deleting it so billing and routing history remain intact.',
                'code' => 'provider_in_use_by_history',
                'data' => ['cascade_available' => false],
            ], 409);
        }

        $result = DB::transaction(function () use ($request, $provider, $aliases, $cascade, $audit): array {
            $snapshot = $this->resource($provider);
            $aliasIds = $aliases->pluck('id')->values();
            $aliasNames = $aliases->pluck('public_alias')->filter()->values()->all();
            $detachedPackageIds = collect();
            $detachedKeyCount = 0;
            $disabledPackageIds = collect();

            if ($cascade && $aliasIds->isNotEmpty()) {
                $detachedPackageIds = DB::table('model_alias_package')
                    ->whereIn('model_alias_id', $aliasIds)
                    ->pluck('package_id')
                    ->unique()
                    ->values();

                $detachedKeyCount = DB::table('api_key_model_alias')
                    ->whereIn('model_alias_id', $aliasIds)
                    ->count();

                DB::table('model_alias_package')->whereIn('model_alias_id', $aliasIds)->delete();
                DB::table('api_key_model_alias')->whereIn('model_alias_id', $aliasIds)->delete();

                // model_pricing rows cascade from model_aliases at the database layer.
                ModelAlias::query()->whereIn('id', $aliasIds)->delete();

                if ($detachedPackageIds->isNotEmpty()) {
                    $disabledPackageIds = Package::query()
                        ->whereIn('id', $detachedPackageIds)
                        ->whereDoesntHave('modelAliases')
                        ->pluck('id');

                    if ($disabledPackageIds->isNotEmpty()) {
                        Package::query()->whereIn('id', $disabledPackageIds)->update([
                            'enabled' => false,
                            'customer_visible' => false,
                        ]);
                    }
                }

                $this->removeAliasesFromPlayground($aliasNames);
                $this->removeAliasesFromRedeemCodes($aliasNames);
            }

            // The active revision FK is restrictive, so clear it before removing
            // provider-owned revisions. The history guard above guarantees these
            // revisions are unused by billing reservations.
            $provider->forceFill(['active_connection_revision_id' => null])->save();
            $provider->connectionRevisions()->delete();
            $provider->models()->delete();

            $audit->record(
                $request->user(),
                'provider.deleted',
                'provider',
                $provider->id,
                $cascade
                    ? 'Deleted an unused provider and cascaded its catalog configuration.'
                    : 'Deleted an unused provider and its configuration.',
                [
                    'provider' => $snapshot,
                    'cascade' => $cascade,
                    'deleted_aliases' => $aliasNames,
                    'detached_package_ids' => $detachedPackageIds->values()->all(),
                    'detached_api_key_scope_count' => $detachedKeyCount,
                    'disabled_empty_package_ids' => $disabledPackageIds->values()->all(),
                ]
            );

            $provider->delete();

            return [
                'success' => true,
                'cascade' => $cascade,
                'deleted_aliases' => count($aliasNames),
                'detached_packages' => $detachedPackageIds->count(),
                'detached_api_key_scopes' => $detachedKeyCount,
                'disabled_empty_packages' => $disabledPackageIds->count(),
            ];
        });

        return response()->json(['data' => $result]);
    }

    /** @param array<int,string> $aliases */
    private function removeAliasesFromPlayground(array $aliases): void
    {
        if ($aliases === []) {
            return;
        }

        PlaygroundSetting::query()->each(function (PlaygroundSetting $setting) use ($aliases): void {
            $allowed = array_values(array_filter(
                $setting->allowed_model_aliases ?? [],
                static fn ($value): bool => is_string($value) && ! in_array($value, $aliases, true)
            ));

            $updates = ['allowed_model_aliases' => $allowed];
            if (is_string($setting->default_model_alias) && in_array($setting->default_model_alias, $aliases, true)) {
                $updates['default_model_alias'] = null;
            }

            $setting->update($updates);
        });
    }

    /** @param array<int,string> $aliases */
    private function removeAliasesFromRedeemCodes(array $aliases): void
    {
        if ($aliases === []) {
            return;
        }

        RedeemCode::query()->each(function (RedeemCode $code) use ($aliases): void {
            $before = array_values(array_filter($code->allowed_model_aliases ?? [], 'is_string'));
            $after = array_values(array_filter(
                $before,
                static fn (string $value): bool => ! in_array($value, $aliases, true)
            ));

            if ($before === $after) {
                return;
            }

            $updates = ['allowed_model_aliases' => $after];
            if ($after === []) {
                // An empty redeem-code scope would create an unusable entitlement.
                $updates['enabled'] = false;
            }

            $code->update($updates);
        });
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
