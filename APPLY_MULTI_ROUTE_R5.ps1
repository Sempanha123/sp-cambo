param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'

function Require-File {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Required file is missing: $Path"
    }
}

function Save-Text {
    param([string]$Path, [string]$Content)
    Set-Content -LiteralPath $Path -Value $Content -NoNewline -Encoding UTF8
}

function Replace-Once {
    param(
        [string]$Path,
        [string]$Old,
        [string]$New,
        [string]$Label
    )

    $content = Get-Content -LiteralPath $Path -Raw

    if ($content.Contains($New)) {
        Write-Host "[SKIP] $Label already applied."
        return
    }

    if (-not $content.Contains($Old)) {
        throw "Could not find expected code for: $Label`nFile: $Path`nR5 stopped instead of applying a risky partial patch."
    }

    Save-Text $Path ($content.Replace($Old, $New))
    Write-Host "[OK] $Label"
}

function Replace-Between {
    param(
        [string]$Path,
        [string]$StartMarker,
        [string]$EndMarker,
        [string]$Replacement,
        [string]$Label
    )

    $content = Get-Content -LiteralPath $Path -Raw

    if ($content.Contains('rerouteBestEffort(') -and $Label -like '*gateway*') {
        Write-Host "[SKIP] $Label already applied."
        return
    }

    $start = $content.IndexOf($StartMarker)
    if ($start -lt 0) {
        throw "Start marker not found for: $Label`nFile: $Path"
    }

    $end = $content.IndexOf($EndMarker, $start)
    if ($end -lt 0) {
        throw "End marker not found for: $Label`nFile: $Path"
    }

    Save-Text $Path ($content.Substring(0, $start) + $Replacement + $content.Substring($end))
    Write-Host "[OK] $Label"
}

$project = (Resolve-Path -LiteralPath $ProjectRoot).Path
$billing = Join-Path $project 'backend\app\Services\InferenceBillingService.php'
$providers = Join-Path $project 'backend\bootstrap\providers.php'
$navigation = Join-Path $project 'frontend\app\composables\useSiteNavigation.ts'
$gateway = Join-Path $project 'gateway\src\app.ts'
$workspace = Join-Path $project 'frontend\pnpm-workspace.yaml'
$ci = Join-Path $project '.github\workflows\ci.yml'

foreach ($path in @($billing, $providers, $navigation, $gateway, $workspace, $ci)) {
    Require-File $path
}

Write-Host ''
Write-Host '=== SP Cambo Multi-Route Production R5 ==='
Write-Host 'R5 is safe to run after the earlier R3/R4 partial attempt.'
Write-Host ''

# -------------------------------------------------------------------------
# 1) Billing integration. Current GitHub main already has this, while this
#    fallback also supports a clean tree that still has the old single route.
# -------------------------------------------------------------------------
$billingContent = Get-Content -LiteralPath $billing -Raw

if (-not $billingContent.Contains('private readonly ModelRoutePoolService $routePools')) {
    Replace-Once `
        -Path $billing `
        -Old '    public function __construct(private readonly ReservationService $reservations) {}' `
        -New @'
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ModelRoutePoolService $routePools,
    ) {}
'@ `
        -Label 'Inject ModelRoutePoolService'
}

$billingContent = Get-Content -LiteralPath $billing -Raw
if (-not $billingContent.Contains('$route = $this->routePools->select($alias, $primaryModel);')) {
    Replace-Once `
        -Path $billing `
        -Old @'
            $model = $alias->model()->with('provider.activeConnectionRevision')->firstOrFail();
            $provider = $model->provider;
            $revision = $provider?->activeConnectionRevision;
            if (! $provider || ! $provider->enabled || ! $revision || ! $revision->isRouteReady()) {
                throw new InvalidArgumentException('The selected model route is not ready.');
            }
'@ `
        -New @'
            $primaryModel = $alias->model()->with('provider')->firstOrFail();
            $route = $this->routePools->select($alias, $primaryModel);
            $model = $route['model'];
            $revision = $route['revision'];
            $routePoolEntryId = $route['entry']?->id;
'@ `
        -Label 'Use scalable route selection in billing'
}

$billingContent = Get-Content -LiteralPath $billing -Raw
if (-not $billingContent.Contains("'route_history'")) {
    Replace-Once `
        -Path $billing `
        -Old @'
                    $snapshot['route_revision_id'] = (string) $revision->id;
                    $snapshot['route_version'] = (int) $revision->route_version;
                    $snapshot['internal_model_id'] = (string) $model->internal_model_id;
'@ `
        -New @'
                    $snapshot['route_revision_id'] = (string) $revision->id;
                    $snapshot['route_version'] = (int) $revision->route_version;
                    $snapshot['route_pool_entry_id'] = $routePoolEntryId === null ? null : (int) $routePoolEntryId;
                    $snapshot['internal_model_id'] = (string) $model->internal_model_id;
                    $snapshot['route_history'] = [[
                        'entry_id' => $routePoolEntryId === null ? null : (int) $routePoolEntryId,
                        'revision_id' => (string) $revision->id,
                        'provider_id' => (int) $model->provider_id,
                        'ai_model_id' => (int) $model->id,
                        'internal_model_id' => (string) $model->internal_model_id,
                        'selected_at' => now()->toAtomString(),
                    ]];
'@ `
        -Label 'Snapshot route history'
}

$billingContent = Get-Content -LiteralPath $billing -Raw
if (-not $billingContent.Contains("model_route_pool_entry_id' => `$routePoolEntryId")) {
    Replace-Once `
        -Path $billing `
        -Old @'
                    return [
                        'reservation' => $reservation,
                        'billing_mode' => $billingMode,
'@ `
        -New @'
                    if ($routePoolEntryId !== null
                        && (string) $reservation->model_route_pool_entry_id !== (string) $routePoolEntryId) {
                        $reservation->forceFill([
                            'model_route_pool_entry_id' => $routePoolEntryId,
                        ])->saveOrFail();
                    }

                    return [
                        'reservation' => $reservation->fresh('allocations'),
                        'billing_mode' => $billingMode,
'@ `
        -Label 'Bind reservation to route-pool entry'
}

# -------------------------------------------------------------------------
# 2) Register route-pool provider independently. No dependency on R3 script.
# -------------------------------------------------------------------------
$providerContent = Get-Content -LiteralPath $providers -Raw
if (-not $providerContent.Contains('use App\Providers\ModelRoutePoolServiceProvider;')) {
    $needle = 'use App\Providers\AppServiceProvider;'
    if (-not $providerContent.Contains($needle)) {
        throw "Could not locate AppServiceProvider import in $providers"
    }
    $providerContent = $providerContent.Replace(
        $needle,
        $needle + [Environment]::NewLine + 'use App\Providers\ModelRoutePoolServiceProvider;'
    )
}

if (-not $providerContent.Contains('    ModelRoutePoolServiceProvider::class,')) {
    $needle = '    AppServiceProvider::class,'
    if (-not $providerContent.Contains($needle)) {
        throw "Could not locate AppServiceProvider registration in $providers"
    }
    $providerContent = $providerContent.Replace(
        $needle,
        $needle + [Environment]::NewLine + '    ModelRoutePoolServiceProvider::class,'
    )
}
Save-Text $providers $providerContent
Write-Host '[OK] ModelRoutePoolServiceProvider registered'

# -------------------------------------------------------------------------
# 3) Robust admin navigation insertion.
#    Earlier R3 failed here because it expected one exact neighboring block.
# -------------------------------------------------------------------------
$navigationContent = Get-Content -LiteralPath $navigation -Raw

if ($navigationContent.Contains("to: '/admin/route-pools'")) {
    Write-Host '[SKIP] Model routing navigation already exists.'
} else {
    $promotionLabel = "        label: 'Promotions',"
    $promotionIndex = $navigationContent.IndexOf($promotionLabel)

    if ($promotionIndex -lt 0) {
        throw "Could not find the Promotions admin navigation item in $navigation"
    }

    $transition = '      }, {'
    $transitionIndex = $navigationContent.LastIndexOf($transition, $promotionIndex)

    if ($transitionIndex -lt 0) {
        throw "Could not find the navigation item boundary before Promotions in $navigation"
    }

    $routeBlock = @'
      }, {
        label: 'Model routing',
        icon: 'i-lucide-network',
        to: '/admin/route-pools',
        active: route.path.startsWith('/admin/route-pools')
      }, {
'@

    $navigationContent = $navigationContent.Substring(0, $transitionIndex) +
        $routeBlock +
        $navigationContent.Substring($transitionIndex + $transition.Length)

    Save-Text $navigation $navigationContent
    Write-Host '[OK] Added Model routing to admin navigation'
}

# -------------------------------------------------------------------------
# 4) Pre-stream failover in gateway.
# -------------------------------------------------------------------------
$gatewayContent = Get-Content -LiteralPath $gateway -Raw

if ($gatewayContent.Contains('rerouteBestEffort(')) {
    Write-Host '[SKIP] Gateway pre-stream failover already exists.'
} else {
    $gatewayReplacement = @'
      const clientController = new AbortController();
      const onRequestAborted = (): void => clientController.abort("client_disconnect");
      const onResponseClose = (): void => {
        if (!reply.raw.writableEnded) clientController.abort("client_disconnect");
      };
      request.raw.once("aborted", onRequestAborted);
      reply.raw.once("close", onResponseClose);

      let route = {
        internal_model: preflight.internal_model,
        route_revision_id: preflight.route_revision_id,
        route_version: preflight.route_version,
        upstream_origin: preflight.upstream_origin,
        upstream_credential: preflight.upstream_credential,
        upstream_timeout_ms: preflight.upstream_timeout_ms,
      };

      try {
        while (true) {
          const controller = new AbortController();
          const forwardClientAbort = (): void => controller.abort("client_disconnect");

          if (clientController.signal.aborted) {
            controller.abort("client_disconnect");
          } else {
            clientController.signal.addEventListener("abort", forwardClientAbort, { once: true });
          }

          const routeTimeoutMs = route.upstream_timeout_ms || config.upstreamTimeoutMs;
          const upstreamTimeoutMs = Math.min(
            Math.max(routeTimeoutMs, 1000),
            Math.max(config.upstreamTimeoutMs, 1000),
            600_000,
          );
          const timeout = setTimeout(() => controller.abort("upstream_timeout"), upstreamTimeoutMs);

          let upstream: Response;
          try {
            const upstreamOrigin = route.upstream_origin.replace(/\/+$/, "");
            const fetchPromise = fetchImpl(`${upstreamOrigin}${path}`, {
              method: "POST",
              headers: {
                ...upstreamHeaders(request, route.upstream_credential, preflight.correlation_id),
                "x-route-revision": route.route_revision_id ?? "",
                "x-route-version": route.route_version?.toString() ?? "",
              },
              body: upstreamBody(path, prepared, route.internal_model, preflight.max_output_tokens),
              signal: controller.signal,
            });

            upstream = await abortable(fetchPromise, controller.signal);
          } catch {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);

            const reason = abortReason(controller.signal);
            if (reason === "client_disconnect") {
              await releaseBestEffort(reservationId);
              throw operationFailure(reason);
            }

            const next = await rerouteBestEffort(
              reservationId,
              reason === "upstream_timeout" ? "upstream_timeout" : "upstream_connect_error",
            );

            if (next) {
              route = next;
              continue;
            }

            await releaseBestEffort(reservationId);
            throw operationFailure(reason ?? "upstream_connect_error");
          }

          if (!upstream.ok && failoverStatus(upstream.status)) {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);
            try { await upstream.body?.cancel(); } catch { /* current route is finished */ }

            const next = await rerouteBestEffort(
              reservationId,
              `upstream_http_${upstream.status}`,
              upstream.status,
            );

            if (next) {
              route = next;
              continue;
            }

            await releaseBestEffort(reservationId);
            return proxyError(reply, upstream, path);
          }

          if (!upstream.ok) {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);
            await releaseBestEffort(reservationId);
            return proxyError(reply, upstream, path);
          }

          // A successful response header is enough to mark the selected route
          // healthy again. This best-effort telemetry never blocks the customer.
          void routeSuccessBestEffort(reservationId);

          // Once a streaming response has usable headers, the route timeout has
          // served its purpose. Never switch providers after public output starts.
          if (prepared.streaming && upstream.body) {
            clearTimeout(timeout);
          }

          promptCache.remember(
            inspection.key_id,
            path,
            prepared.publicModel,
            prepared.promptSegments,
          );
          void markStateBestEffort(reservationId, "STREAMING");

          try {
            if (prepared.streaming && upstream.body) {
              return await stream(
                reply,
                upstream,
                reservationId,
                path,
                preflight.correlation_id,
                requestStartedAt,
                controller.signal,
                toolNames,
                localInput.input_tokens,
                localInput.cache_read_tokens,
              );
            }

            return await json(
              reply,
              upstream,
              reservationId,
              path,
              requestStartedAt,
              controller.signal,
              toolNames,
              localInput.input_tokens,
              localInput.cache_read_tokens,
            );
          } finally {
            clearTimeout(timeout);
            clientController.signal.removeEventListener("abort", forwardClientAbort);
          }
        }
      } finally {
        request.raw.off("aborted", onRequestAborted);
        reply.raw.off("close", onResponseClose);
      }
'@

    Replace-Between `
        -Path $gateway `
        -StartMarker '      const controller = new AbortController();' `
        -EndMarker '    } finally { await lease.release(); }' `
        -Replacement ($gatewayReplacement + [Environment]::NewLine) `
        -Label 'gateway pre-stream failover'

    $gatewayContent = Get-Content -LiteralPath $gateway -Raw

    if (-not $gatewayContent.Contains('async function rerouteBestEffort(')) {
        Replace-Once `
            -Path $gateway `
            -Old @'
  async function releaseBestEffort(reservationId: string): Promise<void> {
'@ `
            -New @'
  async function rerouteBestEffort(
    reservationId: string,
    failureCode: string,
    upstreamStatus?: number,
  ) {
    if (!dependencies.controlPlane.reroute) return null;

    try {
      return await dependencies.controlPlane.reroute(reservationId, {
        failure_code: failureCode,
        ...(upstreamStatus !== undefined ? { upstream_status: upstreamStatus } : {}),
      });
    } catch {
      // Keep the original upstream failure when no alternate route is available.
      return null;
    }
  }

  async function routeSuccessBestEffort(reservationId: string): Promise<void> {
    if (!dependencies.controlPlane.routeSuccess) return;
    try {
      await dependencies.controlPlane.routeSuccess(reservationId);
    } catch {
      // Route-health telemetry must never block successful inference.
    }
  }

  async function releaseBestEffort(reservationId: string): Promise<void> {
'@ `
            -Label 'Add gateway reroute helpers'
    }

    $gatewayContent = Get-Content -LiteralPath $gateway -Raw

    if (-not $gatewayContent.Contains('function failoverStatus(status: number)')) {
        Replace-Once `
            -Path $gateway `
            -Old @'
function operationFailure(reason: string): GatewayError {
'@ `
            -New @'
function failoverStatus(status: number): boolean {
  return status === 408
    || status === 429
    || status === 500
    || status === 502
    || status === 503
    || status === 504;
}

function operationFailure(reason: string): GatewayError {
'@ `
            -Label 'Add retryable upstream status policy'
    }
}

# -------------------------------------------------------------------------
# 5) Verify the required markers.
# -------------------------------------------------------------------------
$billingContent = Get-Content -LiteralPath $billing -Raw
$providerContent = Get-Content -LiteralPath $providers -Raw
$navigationContent = Get-Content -LiteralPath $navigation -Raw
$gatewayContent = Get-Content -LiteralPath $gateway -Raw
$workspaceContent = Get-Content -LiteralPath $workspace -Raw
$ciContent = Get-Content -LiteralPath $ci -Raw

$checks = @(
    @{ Name = 'Billing route selector'; Ok = $billingContent.Contains('private readonly ModelRoutePoolService $routePools') -and $billingContent.Contains('$this->routePools->select') },
    @{ Name = 'Reservation route history'; Ok = $billingContent.Contains("'route_history'") -and $billingContent.Contains("'route_pool_entry_id'") },
    @{ Name = 'Route-pool service provider'; Ok = $providerContent.Contains('ModelRoutePoolServiceProvider::class') },
    @{ Name = 'Admin Model routing link'; Ok = $navigationContent.Contains("to: '/admin/route-pools'") },
    @{ Name = 'Gateway reroute loop'; Ok = $gatewayContent.Contains('rerouteBestEffort(') -and $gatewayContent.Contains('routeSuccessBestEffort(') },
    @{ Name = 'Gateway retry policy'; Ok = $gatewayContent.Contains('function failoverStatus(status: number)') },
    @{ Name = 'pnpm watcher build permission'; Ok = $workspaceContent.Contains("'@parcel/watcher': true") },
    @{ Name = 'pnpm resolver build permission'; Ok = $workspaceContent.Contains('unrs-resolver: true') },
    @{ Name = 'pnpm vue-demi build permission'; Ok = $workspaceContent.Contains('vue-demi: true') },
    @{ Name = 'CI Node 24'; Ok = $ciContent.Contains('name: Nuxt / Node 24') }
)

foreach ($check in $checks) {
    if (-not $check.Ok) {
        throw "R5 integration marker missing: $($check.Name)"
    }

    Write-Host "[OK] $($check.Name)"
}

Push-Location (Join-Path $project 'backend')
try {
    php artisan optimize:clear
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] Multi-route R5 source integration completed.'
Write-Host 'Next: close any running frontend dev/test processes, then run PREPARE_NODE_DEPS_R5.ps1.'
