<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ProviderConnectionException;
use App\Http\Controllers\Controller;
use App\Models\ModelRoutePoolEntry;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Services\AuditService;
use App\Services\ProviderProbeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProviderConnectionRevisionController extends Controller
{
    /**
     * List all provider connection revisions for a provider.
     */
    public function index(Request $request, string $provider): JsonResponse
    {
        $provider = Provider::query()
            ->where('id', $provider)
            ->firstOrFail();

        $revisions = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->withExists('reservations')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $revisions->map(fn (ProviderConnectionRevision $revision) => $this->resource($revision)),
        ]);
    }

    /**
     * Create a new provider connection revision.
     */
    public function store(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()
            ->where('id', $provider)
            ->firstOrFail();

        $data = $request->validate([
            // Route versions are audit identifiers, not reusable UI slots. Admin UI
            // normally omits this field and the server allocates the next version.
            'route_version' => ['sometimes', 'integer', 'min:1', Rule::unique('provider_connection_revisions', 'route_version')->where('provider_id', $provider->id)],
            'origin' => ['required', 'string', 'max:512'],
            'connection_type' => ['required', 'string', 'max:50', Rule::in(ProviderConnectionRevision::CONNECTION_TYPES)],
            'credential' => ['required', 'string'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:60000'],
            'policy_version' => ['sometimes', 'integer', 'min:1'],
            'resolve_until' => ['nullable', 'date'],
        ]);

        $data['origin'] = $this->normalizeOrigin($data['origin'], $data['connection_type']);
        $this->validateOrigin($data['origin']);

        $revision = DB::transaction(function () use ($provider, $data): ProviderConnectionRevision {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            ProviderConnectionRevision::query()
                ->where('provider_id', $lockedProvider->id)
                ->lockForUpdate()
                ->get(['id']);

            $routeVersion = isset($data['route_version'])
                ? (int) $data['route_version']
                : ((int) ProviderConnectionRevision::query()
                    ->where('provider_id', $lockedProvider->id)
                    ->max('route_version')) + 1;

            return ProviderConnectionRevision::query()->create([
                'provider_id' => $lockedProvider->id,
                'route_version' => $routeVersion,
                'origin' => $data['origin'],
                'connection_type' => $data['connection_type'],
                'credential' => $data['credential'],
                'credential_suffix' => $this->extractCredentialSuffix($data['credential']),
                'timeout_ms' => $data['timeout_ms'],
                'policy_version' => $data['policy_version'] ?? 1,
                'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
                'resolve_until' => filled($data['resolve_until'] ?? null) ? Carbon::parse($data['resolve_until']) : null,
            ]);
        });

        $audit->record(
            $request->user(),
            'provider_connection_revision.created',
            'provider_connection_revision',
            $revision->id,
            'Created new provider connection revision.',
            [
                'provider_id' => $provider->id,
                'route_version' => $revision->route_version,
                'connection_type' => $revision->connection_type,
                'timeout_ms' => $revision->timeout_ms,
                'policy_version' => $revision->policy_version,
            ]
        );

        return response()->json([
            'data' => $this->resource($revision),
        ], 201);
    }

    /**
     * Edit an unused draft in place. For a live or historical revision, perform
     * a safe replacement: clone the stored credential when it is left blank,
     * verify the new route, then atomically move active/pool references to it.
     */
    public function update(
        Request $request,
        string $provider,
        string $revision,
        AuditService $audit,
        ProviderProbeService $probeService,
    ): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->findOrFail($revision);

        $isEditableDraft = $revision->lifecycle_status === ProviderConnectionRevision::STATUS_PENDING
            && $provider->active_connection_revision_id !== $revision->id
            && ! $revision->reservations()->exists();

        $data = $request->validate([
            'route_version' => $isEditableDraft
                ? ['required', 'integer', 'min:1', Rule::unique('provider_connection_revisions', 'route_version')->where('provider_id', $provider->id)->ignore($revision->id)]
                : ['required', 'integer', 'min:1'],
            'origin' => ['required', 'string', 'max:512'],
            'connection_type' => ['required', 'string', 'max:50', Rule::in(ProviderConnectionRevision::CONNECTION_TYPES)],
            'credential' => ['nullable', 'string'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:60000'],
            'policy_version' => ['sometimes', 'integer', 'min:1'],
            'resolve_until' => ['nullable', 'date'],
        ]);

        $origin = $this->normalizeOrigin($data['origin'], $data['connection_type']);
        $this->validateOrigin($origin);

        if (! $isEditableDraft) {
            return $this->replaceRevision(
                $request,
                $provider,
                $revision,
                array_merge($data, ['origin' => $origin]),
                $audit,
                $probeService,
            );
        }

        $before = $this->resource($revision);
        $updates = [
            'route_version' => $data['route_version'],
            'origin' => $origin,
            'connection_type' => $data['connection_type'],
            'timeout_ms' => $data['timeout_ms'],
            'policy_version' => $data['policy_version'] ?? $revision->policy_version,
            'resolve_until' => filled($data['resolve_until'] ?? null) ? Carbon::parse($data['resolve_until']) : null,
            'last_probe_status' => null,
            'last_probe_at' => null,
        ];
        if (filled($data['credential'] ?? null)) {
            $updates['credential'] = $data['credential'];
            $updates['credential_suffix'] = $this->extractCredentialSuffix($data['credential']);
        }
        $revision->update($updates);

        $audit->record($request->user(), 'provider_connection_revision.updated', 'provider_connection_revision', $revision->id,
            'Updated unused provider connection revision.', ['before' => $before, 'after' => $this->resource($revision->fresh())]);

        return response()->json(['data' => $this->resource($revision->fresh())]);
    }

    /**
     * Replace an immutable revision without exposing or requiring its existing
     * credential. The old row remains unchanged for billing/audit history.
     */
    private function replaceRevision(
        Request $request,
        Provider $provider,
        ProviderConnectionRevision $source,
        array $data,
        AuditService $audit,
        ProviderProbeService $probeService,
    ): JsonResponse
    {
        $credential = filled($data['credential'] ?? null)
            ? trim((string) $data['credential'])
            : (string) $source->credential;

        if ($credential === '') {
            return response()->json([
                'message' => 'Enter a credential before replacing this connection.',
                'code' => 'provider_connection_credential_required',
                'errors' => ['credential' => ['A credential is required for this connection.']],
            ], 422);
        }

        $replacement = DB::transaction(function () use ($provider, $source, $data, $credential): ProviderConnectionRevision {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            ProviderConnectionRevision::query()
                ->where('provider_id', $lockedProvider->id)
                ->lockForUpdate()
                ->get(['id']);

            $lockedSource = ProviderConnectionRevision::query()
                ->where('provider_id', $lockedProvider->id)
                ->lockForUpdate()
                ->findOrFail($source->id);
            $nextVersion = ((int) ProviderConnectionRevision::query()
                ->where('provider_id', $lockedProvider->id)
                ->max('route_version')) + 1;

            return ProviderConnectionRevision::query()->create([
                'provider_id' => $lockedProvider->id,
                'route_version' => $nextVersion,
                'origin' => $data['origin'],
                'connection_type' => $data['connection_type'],
                'credential' => $credential,
                'credential_suffix' => $this->extractCredentialSuffix($credential),
                'timeout_ms' => $data['timeout_ms'],
                'policy_version' => $data['policy_version'] ?? $lockedSource->policy_version,
                'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
                'resolve_until' => filled($data['resolve_until'] ?? null)
                    ? Carbon::parse($data['resolve_until'])
                    : null,
            ]);
        });

        $audit->record(
            $request->user(),
            'provider_connection_revision.replacement_started',
            'provider_connection_revision',
            $replacement->id,
            'Created and started verifying a replacement provider connection.',
            [
                'provider_id' => $provider->id,
                'source_revision_id' => (string) $source->id,
                'replacement_route_version' => (int) $replacement->route_version,
            ]
        );

        // Keep the currently serving revision untouched unless the complete
        // provider/model probe succeeds.
        $capabilitySnapshot = $provider->models()
            ->with('aliases:id,ai_model_id,capabilities')
            ->get()
            ->flatMap(fn ($model) => $model->aliases->mapWithKeys(
                fn ($alias): array => [(string) $alias->id => $alias->capabilities]
            ));

        $probeResponse = $this->probe(
            $request,
            (string) $provider->id,
            (string) $replacement->id,
            $audit,
            $probeService,
        );

        if ($probeResponse->getStatusCode() >= 400) {
            DB::transaction(function () use ($replacement, $capabilitySnapshot): void {
                foreach ($capabilitySnapshot as $aliasId => $capabilities) {
                    DB::table('model_aliases')
                        ->where('id', $aliasId)
                        ->update([
                            'capabilities' => json_encode($capabilities ?? [], JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                }

                ProviderConnectionRevision::query()
                    ->whereKey($replacement->id)
                    ->where('lifecycle_status', ProviderConnectionRevision::STATUS_PENDING)
                    ->delete();
            });

            $audit->record(
                $request->user(),
                'provider_connection_revision.replacement_failed',
                'provider_connection_revision',
                $source->id,
                'Replacement verification failed; the existing connection was left unchanged.',
                [
                    'provider_id' => $provider->id,
                    'attempted_revision_id' => (string) $replacement->id,
                    'attempted_route_version' => (int) $replacement->route_version,
                ]
            );

            return response()->json([
                'message' => 'The replacement connection could not be verified. The existing route was left unchanged.',
                'code' => 'provider_connection_replacement_probe_failed',
            ], 502);
        }

        $swap = DB::transaction(function () use ($provider, $source, $replacement): array {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            $lockedSource = ProviderConnectionRevision::query()->lockForUpdate()->findOrFail($source->id);
            $lockedReplacement = ProviderConnectionRevision::query()->lockForUpdate()->findOrFail($replacement->id);

            if (! $lockedReplacement->isRouteReady() || $lockedReplacement->last_probe_status !== 'SUCCESS') {
                throw new ProviderConnectionException(
                    'The replacement connection is not ready.',
                    'provider_revision_not_ready'
                );
            }

            $wasActive = (string) $lockedProvider->active_connection_revision_id === (string) $lockedSource->id;
            if ($wasActive) {
                $lockedProvider->forceFill([
                    'active_connection_revision_id' => $lockedReplacement->id,
                ])->saveOrFail();
            }

            $movedPoolEntries = ModelRoutePoolEntry::query()
                ->where('provider_connection_revision_id', $lockedSource->id)
                ->update([
                    'provider_connection_revision_id' => $lockedReplacement->id,
                    'updated_at' => now(),
                ]);

            return [
                'was_active' => $wasActive,
                'moved_pool_entries' => $movedPoolEntries,
                'active_connection_revision_id' => $lockedProvider->fresh()->active_connection_revision_id,
            ];
        });

        if ($swap['was_active']) {
            $audit->record(
                $request->user(),
                'provider.active_connection_revision_changed',
                'provider',
                $provider->id,
                'Activated a verified replacement provider connection.',
                [
                    'previous_revision_id' => (string) $source->id,
                    'new_revision_id' => (string) $replacement->id,
                    'source' => 'safe_revision_replacement',
                ]
            );
        }

        $audit->record(
            $request->user(),
            'provider_connection_revision.replaced',
            'provider_connection_revision',
            $replacement->id,
            'Verified and installed a replacement provider connection.',
            [
                'provider_id' => $provider->id,
                'source_revision_id' => (string) $source->id,
                'moved_pool_entries' => (int) $swap['moved_pool_entries'],
                'became_active' => (bool) $swap['was_active'],
            ]
        );

        return response()->json(['data' => $this->resource($replacement->fresh()) + [
            'replacement_created' => true,
            'replaced_revision_id' => (string) $source->id,
            'active_connection_revision_id' => $swap['active_connection_revision_id']
                ? (string) $swap['active_connection_revision_id']
                : null,
            'moved_pool_entries' => (int) $swap['moved_pool_entries'],
        ]]);
    }

    /**
     * Remove a non-active connection from the working UI. Unused revisions are
     * deleted permanently. Revisions with request history are archived by
     * revoking them and removing any route-pool references, preserving billing
     * and audit history while keeping the normal Admin list clean.
     */
    public function destroy(Request $request, string $provider, string $revision, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->findOrFail($revision);

        if ((string) $provider->active_connection_revision_id === (string) $revision->id) {
            return response()->json(['message' => 'The active connection revision cannot be removed.', 'code' => 'provider_revision_active'], 409);
        }

        if ($revision->reservations()->where('status', 'ACTIVE')->exists()) {
            return response()->json([
                'message' => 'This connection still has active requests. Wait for them to finish before removing it.',
                'code' => 'provider_revision_has_active_requests',
            ], 409);
        }

        $id = (string) $revision->id;
        $version = (int) $revision->route_version;
        $hasHistory = $revision->reservations()->exists();

        if ($hasHistory) {
            $removedPoolEntries = DB::transaction(function () use ($revision): int {
                $lockedRevision = ProviderConnectionRevision::query()->lockForUpdate()->findOrFail($revision->id);

                $removed = ModelRoutePoolEntry::query()
                    ->where('provider_connection_revision_id', $lockedRevision->id)
                    ->delete();

                if ($lockedRevision->lifecycle_status !== ProviderConnectionRevision::STATUS_REVOKED) {
                    $lockedRevision->forceFill([
                        'lifecycle_status' => ProviderConnectionRevision::STATUS_REVOKED,
                    ])->saveOrFail();
                }

                return $removed;
            });

            $audit->record(
                $request->user(),
                'provider_connection_revision.archived',
                'provider_connection_revision',
                $id,
                'Archived a historical provider connection revision from the working UI.',
                [
                    'provider_id' => $provider->id,
                    'route_version' => $version,
                    'removed_pool_entries' => $removedPoolEntries,
                ]
            );

            return response()->json(['data' => [
                'success' => true,
                'hidden' => true,
                'hard_deleted' => false,
            ]]);
        }

        $revision->delete();
        $audit->record($request->user(), 'provider_connection_revision.deleted', 'provider_connection_revision', $id,
            'Deleted unused provider connection revision.', ['provider_id' => $provider->id, 'route_version' => $version]);

        return response()->json(['data' => [
            'success' => true,
            'hidden' => false,
            'hard_deleted' => true,
        ]]);
    }

    /**
     * Update the active connection revision for a provider.
     */
    public function updateActive(Request $request, string $provider, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()
            ->where('id', $provider)
            ->firstOrFail();

        $data = $request->validate([
            'revision_id' => ['required', 'string', 'exists:provider_connection_revisions,id'],
        ]);

        $revision = ProviderConnectionRevision::query()->findOrFail($data['revision_id']);

        $provider = DB::transaction(function () use ($request, $provider, $revision, $audit): Provider {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            $previousRevisionId = $lockedProvider->active_connection_revision_id;
            $activatedProvider = $lockedProvider->activateConnectionRevision($revision);

            $audit->record(
                $request->user(),
                'provider.active_connection_revision_changed',
                'provider',
                $activatedProvider->id,
                'Changed active provider connection revision.',
                [
                    'previous_revision_id' => $previousRevisionId,
                    'new_revision_id' => $activatedProvider->active_connection_revision_id,
                ]
            );

            return $activatedProvider;
        });

        return response()->json([
            'data' => $this->providerResource($provider),
        ]);
    }

    /**
     * Initiate a probe for a provider connection revision.
     */
    public function probe(
        Request $request,
        string $provider,
        string $revision,
        AuditService $audit,
        ProviderProbeService $probeService
    ): JsonResponse {
        $provider = Provider::query()->findOrFail($provider);
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->findOrFail($revision);

        $probeFingerprint = $this->probeFingerprint($revision);

        // R20: the provider route comes from the Admin-managed database revision,
        // and READY means every enabled private model configured for that provider
        // can actually serve at least one supported inference protocol. This keeps
        // the seeded OpenAI Codex + Gemini catalog from becoming sellable when only
        // one of the two private OmniRoute model IDs is healthy.
        $internalModels = $provider->models()
            ->where('enabled', true)
            ->orderBy('created_at')
            ->pluck('internal_model_id')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->values();

        $modelProbeResults = [];
        $attempts = [];

        if ($internalModels->isEmpty()) {
            $probe = $probeService->probe($revision, null);
            $modelProbeResults['__connection__'] = $probe;
            $attempts = $probe['attempts'];
            $success = (bool) $probe['success'];
            $successfulEndpointKind = $probe['endpoint_kind'];
        } else {
            foreach ($internalModels as $internalModel) {
                $probe = $probeService->probe($revision, $internalModel);
                $modelProbeResults[$internalModel] = $probe;

                foreach ($probe['attempts'] as $attempt) {
                    $attempts[] = [
                        'kind' => $internalModel.' / '.$attempt['kind'],
                        'status' => $attempt['status'],
                    ];
                }
            }

            $success = collect($modelProbeResults)
                ->every(fn (array $result): bool => (bool) ($result['success'] ?? false));
            $successfulEndpointKind = collect($modelProbeResults)
                ->pluck('endpoint_kind')
                ->filter(fn ($value): bool => is_string($value) && $value !== '')
                ->first();
        }

        [$revision, $canPromote, $autoActivated] = DB::transaction(function () use (
            $request,
            $provider,
            $revision,
            $probeFingerprint,
            $success,
            $attempts,
            $modelProbeResults,
            $audit
        ): array {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            $lockedRevision = ProviderConnectionRevision::query()
                ->where('provider_id', $lockedProvider->id)
                ->lockForUpdate()
                ->findOrFail($revision->id);

            if (! hash_equals($probeFingerprint, $this->probeFingerprint($lockedRevision))) {
                throw new ProviderConnectionException(
                    'The connection revision changed while it was being probed. Probe the current revision again.',
                    'provider_revision_changed_during_probe'
                );
            }

            // Persist the exact protocols verified for each configured private
            // model. Public model discovery/docs then advertise only protocols
            // this Admin-selected provider revision actually proved.
            foreach ($modelProbeResults as $internalModel => $result) {
                if ($internalModel === '__connection__' || ! (bool) ($result['success'] ?? false)) {
                    continue;
                }

                $endpointKinds = collect($result['endpoint_kinds'] ?? [])
                    ->filter(fn ($kind): bool => is_string($kind) && in_array($kind, ['messages', 'responses', 'chat_completions'], true))
                    ->values()
                    ->all();
                $playgroundProtocol = in_array('chat_completions', $endpointKinds, true)
                    ? 'chat_completions'
                    : (in_array('messages', $endpointKinds, true)
                        ? 'messages'
                        : (in_array('responses', $endpointKinds, true) ? 'responses' : null));

                $model = $lockedProvider->models()->where('internal_model_id', $internalModel)->first();
                if (! $model) {
                    continue;
                }

                foreach ($model->aliases()->get() as $alias) {
                    $capabilities = is_array($alias->capabilities) ? $alias->capabilities : [];
                    $capabilities['messages_api'] = in_array('messages', $endpointKinds, true);
                    $capabilities['responses_api'] = in_array('responses', $endpointKinds, true);
                    $capabilities['chat_completions_api'] = in_array('chat_completions', $endpointKinds, true);
                    $capabilities['playground_protocol'] = $playgroundProtocol;
                    $alias->forceFill(['capabilities' => $capabilities])->save();
                }
            }

            $canPromote = $success
                && $lockedRevision->lifecycle_status === ProviderConnectionRevision::STATUS_PENDING
                && $lockedProvider->active_connection_revision_id !== $lockedRevision->id
                && ! $lockedRevision->reservations()->exists();

            $lockedRevision->update([
                'last_probe_status' => $success ? 'SUCCESS' : 'FAILED',
                'last_probe_at' => now(),
                'lifecycle_status' => $canPromote
                    ? ProviderConnectionRevision::STATUS_READY
                    : $lockedRevision->lifecycle_status,
            ]);
            $lockedRevision->refresh();

            // First-time provider setup should not require a second, easy-to-miss
            // activation click. A successful READY probe becomes active only when
            // this provider has no active route yet. Existing active routes are
            // never replaced automatically.
            $autoActivated = false;
            if ($success
                && $lockedProvider->active_connection_revision_id === null
                && $lockedRevision->lifecycle_status === ProviderConnectionRevision::STATUS_READY) {
                $lockedProvider->forceFill([
                    'active_connection_revision_id' => $lockedRevision->id,
                ])->saveOrFail();
                $autoActivated = true;

                $audit->record(
                    $request->user(),
                    'provider.active_connection_revision_changed',
                    'provider',
                    $lockedProvider->id,
                    'Activated the first healthy provider connection automatically after a successful probe.',
                    [
                        'previous_revision_id' => null,
                        'new_revision_id' => $lockedRevision->id,
                        'source' => 'probe_auto_activation',
                    ]
                );
            }

            $audit->record(
                $request->user(),
                'provider_connection_revision.probed',
                'provider_connection_revision',
                $lockedRevision->id,
                $success ? 'Provider connection probe succeeded.' : 'Provider connection probe failed.',
                [
                    'provider_id' => $lockedProvider->id,
                    'route_version' => $lockedRevision->route_version,
                    'success' => $success,
                    'promoted_to_ready' => $canPromote,
                    'auto_activated' => $autoActivated,
                    'lifecycle_status' => $lockedRevision->lifecycle_status,
                    'attempts' => $attempts,
                ]
            );

            return [$lockedRevision, $canPromote, $autoActivated];
        });

        if (! $success) {
            $attemptSummary = collect($attempts)
                ->map(fn (array $attempt): string => $attempt['kind'].' '.($attempt['status'] === null ? 'connection error' : 'HTTP '.$attempt['status']))
                ->implode(', ');
            $message = 'Provider connection probe failed'.($attemptSummary !== '' ? ' ('.$attemptSummary.')' : '').'. Verify the local upstream, origin, and credential.';

            return response()->json([
                'message' => $message,
                'code' => 'provider_connection_probe_failed',
                'data' => $this->resource($revision) + [
                    'probe_success' => false,
                    'probe_message' => $message,
                ],
            ], 502);
        }

        $message = $autoActivated
            ? 'Connection probe succeeded, the revision is READY, and it was set active automatically.'
            : ($canPromote
                ? 'Connection probe succeeded and the revision is READY.'
                : 'Connection probe succeeded.');

        return response()->json([
            'data' => $this->resource($revision) + [
                'probe_success' => true,
                'probe_message' => $message,
                'probe_endpoint_kind' => $successfulEndpointKind,
                'auto_activated' => $autoActivated,
                'active_connection_revision_id' => $autoActivated ? (string) $revision->id : ($provider->active_connection_revision_id ? (string) $provider->active_connection_revision_id : null),
            ],
        ]);
    }

    /**
     * Update the lifecycle status of a provider connection revision.
     */
    public function updateStatus(Request $request, string $provider, string $revision, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()
            ->where('id', $provider)
            ->firstOrFail();

        $data = $request->validate([
            'lifecycle_status' => ['required', 'string', Rule::in([
                ProviderConnectionRevision::STATUS_DRAINING,
                ProviderConnectionRevision::STATUS_REVOKED,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $revision = DB::transaction(function () use ($request, $provider, $revision, $data, $audit): ProviderConnectionRevision {
            $lockedProvider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            $lockedRevision = ProviderConnectionRevision::query()
                ->where('provider_id', $lockedProvider->id)
                ->lockForUpdate()
                ->findOrFail($revision);

            $previousStatus = $lockedRevision->lifecycle_status;
            $allowedTransitions = [
                ProviderConnectionRevision::STATUS_PENDING => [ProviderConnectionRevision::STATUS_REVOKED],
                ProviderConnectionRevision::STATUS_READY => [
                    ProviderConnectionRevision::STATUS_DRAINING,
                    ProviderConnectionRevision::STATUS_REVOKED,
                ],
                ProviderConnectionRevision::STATUS_DRAINING => [ProviderConnectionRevision::STATUS_REVOKED],
                ProviderConnectionRevision::STATUS_REVOKED => [],
            ];

            if (! in_array($data['lifecycle_status'], $allowedTransitions[$previousStatus] ?? [], true)) {
                throw new ProviderConnectionException(
                    "Cannot transition a provider connection revision from {$previousStatus} to {$data['lifecycle_status']}.",
                    'invalid_provider_revision_transition'
                );
            }

            $lockedRevision->update([
                'lifecycle_status' => $data['lifecycle_status'],
            ]);
            $lockedRevision->refresh();

            $audit->record(
                $request->user(),
                'provider_connection_revision.status_changed',
                'provider_connection_revision',
                $lockedRevision->id,
                $data['reason'],
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $lockedRevision->lifecycle_status,
                ]
            );

            return $lockedRevision;
        });

        return response()->json([
            'data' => $this->resource($revision),
        ]);
    }

    protected function normalizeOrigin(string $origin, string $connectionType): string
    {
        $origin = rtrim(trim($origin), '/');
        if ($connectionType === 'omniroute' && str_ends_with(strtolower($origin), '/v1')) {
            $origin = substr($origin, 0, -3);
        }

        return $origin;
    }

    protected function probeFingerprint(ProviderConnectionRevision $revision): string
    {
        return hash('sha256', implode("\n", [
            (string) $revision->provider_id,
            (string) $revision->route_version,
            $revision->origin,
            $revision->connection_type,
            $revision->credential,
            (string) $revision->timeout_ms,
            (string) $revision->policy_version,
        ]));
    }

    /**
     * Validate that the origin is safe and allowed.
     */
    protected function validateOrigin(string $origin): void
    {
        $validator = Validator::make(['origin' => $origin], [
            'origin' => [
                'url',
                function (string $attribute, string $value, \Closure $fail): void {
                    $parsed = parse_url($value);

                    // Allow only an origin root or the conventional OpenAI-compatible
                    // /v1 base path. Arbitrary paths make endpoint construction ambiguous.
                    $path = rtrim((string) ($parsed['path'] ?? ''), '/');
                    if (
                        isset($parsed['user'])
                        || isset($parsed['pass'])
                        || isset($parsed['query'])
                        || isset($parsed['fragment'])
                        || ! in_array(strtolower($path), ['', '/v1'], true)
                    ) {
                        $fail('The origin may only include the optional /v1 path and must not contain userinfo, query parameters, or fragments.');

                        return;
                    }

                    // Reject non-HTTP/HTTPS schemes
                    if (! in_array($parsed['scheme'] ?? null, ['http', 'https'], true)) {
                        $fail('The origin must use http or https.');

                        return;
                    }

                    // Local development commonly runs OmniRoute on loopback.
                    // Keep the SSRF protection in non-local environments while
                    // allowing localhost/private origins during local testing.
                    if (isset($parsed['host']) && ! app()->environment(['local', 'testing']) && ! config('services.spcambo.allow_private_provider_origins')) {
                        $host = $parsed['host'];
                        $isPrivate = $host === 'localhost'
                            || $host === '127.0.0.1'
                            || $host === '::1'
                            || str_starts_with($host, '169.254.')
                            || str_starts_with($host, '192.168.')
                            || str_starts_with($host, '10.')
                            || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) === 1;

                        if ($isPrivate) {
                            $fail('Private provider origins are only allowed in the local/test environment.');
                        }
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            throw new ProviderConnectionException(
                $validator->errors()->first('origin') ?? 'Invalid provider connection origin.',
                'invalid_provider_origin'
            );
        }
    }

    /**
     * Extract a safe credential suffix for display.
     */
    protected function extractCredentialSuffix(string $credential): ?string
    {
        // Extract last 4 characters for display
        $suffix = substr($credential, -4);

        return ctype_alnum($suffix) ? $suffix : null;
    }

    /**
     * Transform a provider connection revision for API response.
     */
    protected function resource(ProviderConnectionRevision $revision): array
    {
        return [
            'id' => (string) $revision->id,
            'provider_id' => (string) $revision->provider_id,
            'route_version' => (int) $revision->route_version,
            'origin' => $revision->origin,
            'connection_type' => $revision->connection_type,
            'credential_suffix' => $revision->maskedCredential(),
            'credential_configured' => $revision->credential !== null,
            'timeout_ms' => (int) $revision->timeout_ms,
            'policy_version' => (int) $revision->policy_version,
            'lifecycle_status' => $revision->lifecycle_status,
            'last_probe_status' => $revision->last_probe_status,
            'last_probe_at' => $revision->last_probe_at?->toAtomString(),
            'resolve_until' => $revision->resolve_until?->toAtomString(),
            'created_at' => $revision->created_at->toAtomString(),
            'updated_at' => $revision->updated_at->toAtomString(),
            'has_request_history' => array_key_exists('reservations_exists', $revision->getAttributes())
                ? (bool) $revision->getAttribute('reservations_exists')
                : $revision->reservations()->exists(),
            'hidden' => $revision->lifecycle_status === ProviderConnectionRevision::STATUS_REVOKED,
        ];
    }

    /**
     * Transform a provider for API response.
     */
    protected function providerResource(Provider $provider): array
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
