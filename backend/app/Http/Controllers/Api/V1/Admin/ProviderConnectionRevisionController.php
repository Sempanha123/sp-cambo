<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\CatalogPublicationException;
use App\Exceptions\ProviderConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ProviderConnectionRevision;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
            'route_version' => ['required', 'integer', 'min:1', Rule::unique('provider_connection_revisions', 'route_version')->where('provider_id', $provider->id)],
            'origin' => ['required', 'string', 'max:512'],
            'connection_type' => ['required', 'string', 'max:50', Rule::in(ProviderConnectionRevision::CONNECTION_TYPES)],
            'credential' => ['required', 'string'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:60000'],
            'policy_version' => ['sometimes', 'integer', 'min:1'],
            'resolve_until' => ['nullable', 'date'],
        ]);

        $data['origin'] = $this->normalizeOrigin($data['origin'], $data['connection_type']);
        $this->validateOrigin($data['origin']);

        $revision = ProviderConnectionRevision::query()->create([
            'provider_id' => $provider->id,
            'route_version' => $data['route_version'],
            'origin' => $data['origin'],
            'connection_type' => $data['connection_type'],
            'credential' => $data['credential'],
            'credential_suffix' => $this->extractCredentialSuffix($data['credential']),
            'timeout_ms' => $data['timeout_ms'],
            'policy_version' => $data['policy_version'] ?? 1,
            'lifecycle_status' => ProviderConnectionRevision::STATUS_PENDING,
            'resolve_until' => filled($data['resolve_until'] ?? null) ? Carbon::parse($data['resolve_until']) : null,
        ]);

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
     * Edit a draft connection revision. Only unused PENDING revisions may change
     * routing fields; once activated/probed ready or referenced by usage history,
     * create a new revision instead.
     */
    public function update(Request $request, string $provider, string $revision, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->findOrFail($revision);

        if ($provider->active_connection_revision_id === $revision->id || $revision->reservations()->exists()) {
            return response()->json([
                'message' => 'This revision is active or has request history. Create a new revision instead.',
                'code' => 'provider_revision_immutable',
            ], 409);
        }

        if ($revision->lifecycle_status !== ProviderConnectionRevision::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only PENDING revisions can be edited. Create a new revision instead.',
                'code' => 'provider_revision_immutable',
            ], 409);
        }

        $data = $request->validate([
            'route_version' => ['required', 'integer', 'min:1', Rule::unique('provider_connection_revisions', 'route_version')->where('provider_id', $provider->id)->ignore($revision->id)],
            'origin' => ['required', 'string', 'max:512'],
            'connection_type' => ['required', 'string', 'max:50', Rule::in(ProviderConnectionRevision::CONNECTION_TYPES)],
            'credential' => ['nullable', 'string'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:60000'],
            'policy_version' => ['sometimes', 'integer', 'min:1'],
            'resolve_until' => ['nullable', 'date'],
        ]);

        $origin = $this->normalizeOrigin($data['origin'], $data['connection_type']);
        $this->validateOrigin($origin);
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

    /** Delete an unused, non-active connection revision. */
    public function destroy(Request $request, string $provider, string $revision, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()->findOrFail($provider);
        $revision = ProviderConnectionRevision::query()
            ->where('provider_id', $provider->id)
            ->findOrFail($revision);

        if ($provider->active_connection_revision_id === $revision->id) {
            return response()->json(['message' => 'The active connection revision cannot be deleted.', 'code' => 'provider_revision_active'], 409);
        }
        if ($revision->reservations()->exists()) {
            return response()->json(['message' => 'This revision has request history and cannot be deleted.', 'code' => 'provider_revision_has_history'], 409);
        }

        $id = (string) $revision->id;
        $version = (int) $revision->route_version;
        $revision->delete();
        $audit->record($request->user(), 'provider_connection_revision.deleted', 'provider_connection_revision', $id,
            'Deleted unused provider connection revision.', ['provider_id' => $provider->id, 'route_version' => $version]);

        return response()->json(['data' => ['success' => true]]);
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

        $revision = ProviderConnectionRevision::query()
            ->where('id', $data['revision_id'])
            ->where('provider_id', $provider->id)
            ->firstOrFail();

        if (! $revision->isRouteReady()) {
            throw new CatalogPublicationException('Cannot activate a revision that is not route-ready.');
        }

        $previousRevisionId = $provider->active_connection_revision_id;

        $provider->update([
            'active_connection_revision_id' => $revision->id,
        ]);

        $audit->record(
            $request->user(),
            'provider.active_connection_revision_changed',
            'provider',
            $provider->id,
            'Changed active provider connection revision.',
            [
                'previous_revision_id' => $previousRevisionId,
                'new_revision_id' => $revision->id,
            ]
        );

        return response()->json([
            'data' => $this->providerResource($provider),
        ]);
    }

    /**
     * Initiate a probe for a provider connection revision.
     */
    public function probe(Request $request, string $provider, string $revision): JsonResponse
    {
        $provider = Provider::query()
            ->where('id', $provider)
            ->firstOrFail();

        $revision = ProviderConnectionRevision::query()
            ->where('id', $revision)
            ->where('provider_id', $provider->id)
            ->firstOrFail();

        // Decrypt credential for the probe
        $credential = $revision->credential;

        try {
            $base = rtrim($revision->origin, '/');
            $headers = [
                'Authorization' => 'Bearer '.$credential,
                'Accept' => 'application/json',
            ];

            // OmniRoute installations are not guaranteed to expose /health.
            // Prefer it when available, then fall back to the standard models
            // endpoint which also verifies the configured credential.
            $attempts = [
                $base.'/health',
                $base.'/v1/models',
            ];

            $response = null;
            $probedUrl = null;
            foreach ($attempts as $url) {
                $candidate = Http::timeout($revision->timeout_ms / 1000)
                    ->withHeaders($headers)
                    ->get($url);

                $response = $candidate;
                $probedUrl = $url;
                if ($candidate->successful()) {
                    break;
                }
            }

            $success = $response?->successful() ?? false;
            $status = $success ? ProviderConnectionRevision::STATUS_READY : 'FAILED';
            $message = $success
                ? 'Connection successful via '.parse_url((string) $probedUrl, PHP_URL_PATH)
                : 'Connection failed: '.($response?->status() ?? 0);

            $revision->update([
                'last_probe_status' => $success ? 'SUCCESS' : 'FAILED',
                'last_probe_at' => now(),
                'lifecycle_status' => $success && $revision->lifecycle_status === ProviderConnectionRevision::STATUS_PENDING
                    ? ProviderConnectionRevision::STATUS_READY
                    : $revision->lifecycle_status,
            ]);

            return response()->json([
                'data' => $this->resource($revision->fresh()) + [
                    'probe_success' => $success,
                    'probe_message' => $message,
                ],
            ]);
        } catch (\Throwable $e) {
            $revision->update([
                'last_probe_status' => 'FAILED',
                'last_probe_at' => now(),
            ]);

            return response()->json([
                'data' => $this->resource($revision->fresh()) + [
                    'probe_success' => false,
                    'probe_message' => 'Probe failed: '.$e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * Update the lifecycle status of a provider connection revision.
     */
    public function updateStatus(Request $request, string $provider, string $revision, AuditService $audit): JsonResponse
    {
        $provider = Provider::query()
            ->where('id', $provider)
            ->firstOrFail();

        $revision = ProviderConnectionRevision::query()
            ->where('id', $revision)
            ->where('provider_id', $provider->id)
            ->firstOrFail();

        $data = $request->validate([
            'lifecycle_status' => ['required', 'string', Rule::in([
                ProviderConnectionRevision::STATUS_READY,
                ProviderConnectionRevision::STATUS_DRAINING,
                ProviderConnectionRevision::STATUS_REVOKED,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $previousStatus = $revision->lifecycle_status;
        $revision->update([
            'lifecycle_status' => $data['lifecycle_status'],
        ]);

        $audit->record(
            $request->user(),
            'provider_connection_revision.status_changed',
            'provider_connection_revision',
            $revision->id,
            $data['reason'],
            [
                'previous_status' => $previousStatus,
                'new_status' => $data['lifecycle_status'],
            ]
        );

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

                    // Reject userinfo, path, query, fragment
                    if (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
                        $fail('The origin must not contain userinfo, query parameters, or fragments.');

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
