param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'

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
        throw "Could not find expected code for: $Label`nFile: $Path`nStop here instead of applying a risky partial patch."
    }

    $content = $content.Replace($Old, $New)
    Set-Content -LiteralPath $Path -Value $content -NoNewline
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
    if ($content.Contains($Replacement)) {
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

    $content = $content.Substring(0, $start) + $Replacement + $content.Substring($end)
    Set-Content -LiteralPath $Path -Value $content -NoNewline
    Write-Host "[OK] $Label"
}

$billing = Join-Path $ProjectRoot 'backend\app\Services\InferenceBillingService.php'
$providers = Join-Path $ProjectRoot 'backend\bootstrap\providers.php'
$navigation = Join-Path $ProjectRoot 'frontend\app\composables\useSiteNavigation.ts'
$gatewayApp = Join-Path $ProjectRoot 'gateway\src\app.ts'

foreach ($path in @($billing, $providers, $navigation, $gatewayApp)) {
    if (-not (Test-Path $path)) {
        throw "Required project file is missing: $path"
    }
}

# ---- InferenceBillingService: inject scalable selector.
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
    -Label 'Replace single active revision with scalable route selection'

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
    -Label 'Snapshot private route history for safe failover'

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
    -Label 'Bind reservation to selected route-pool entry'

# ---- Register provider without removing Telegram providers.
$providerContent = Get-Content -LiteralPath $providers -Raw
if (-not $providerContent.Contains('ModelRoutePoolServiceProvider')) {
    $providerContent = $providerContent.Replace(
        'use App\Providers\AppServiceProvider;',
        "use App\Providers\AppServiceProvider;`r`nuse App\Providers\ModelRoutePoolServiceProvider;"
    )
    if (-not $providerContent.Contains('ModelRoutePoolServiceProvider')) {
        $providerContent = $providerContent.Replace(
            "use App\Providers\AppServiceProvider;`n",
            "use App\Providers\AppServiceProvider;`nuse App\Providers\ModelRoutePoolServiceProvider;`n"
        )
    }

    $providerContent = $providerContent.Replace(
        '    AppServiceProvider::class,',
        "    AppServiceProvider::class,`r`n    ModelRoutePoolServiceProvider::class,"
    )
    if (-not $providerContent.Contains('    ModelRoutePoolServiceProvider::class,')) {
        $providerContent = $providerContent.Replace(
            "    AppServiceProvider::class,`n",
            "    AppServiceProvider::class,`n    ModelRoutePoolServiceProvider::class,`n"
        )
    }

    Set-Content -LiteralPath $providers -Value $providerContent -NoNewline
    Write-Host '[OK] Registered ModelRoutePoolServiceProvider'
} else {
    Write-Host '[SKIP] ModelRoutePoolServiceProvider already registered.'
}

# ---- Sidebar discovery.
Replace-Once `
    -Path $navigation `
    -Old @'
      }, {
        label: 'Providers',
        icon: 'i-lucide-server',
        to: '/admin/providers',
        active: route.path.startsWith('/admin/providers')
      }, {
        label: 'Promotions',
'@ `
    -New @'
      }, {
        label: 'Providers',
        icon: 'i-lucide-server',
        to: '/admin/providers',
        active: route.path.startsWith('/admin/providers')
      }, {
        label: 'Model routing',
        icon: 'i-lucide-network',
        to: '/admin/route-pools',
        active: route.path.startsWith('/admin/route-pools')
      }, {
        label: 'Promotions',
'@ `
    -Label 'Add Model routing to admin navigation'

# ---- Gateway: replace only the upstream-connect section.
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
            try { await upstream.body?.cancel(); } catch { /* retry path is already terminal for this route */ }

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

          // Headers from a successful route are enough to close its circuit.
          // The update is best-effort and never adds latency to the customer.
          void routeSuccessBestEffort(reservationId);

          // Streaming keeps the same successful route until completion. SP Cambo
          // never switches providers after public output can start.
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
    -Path $gatewayApp `
    -StartMarker '      const controller = new AbortController();' `
    -EndMarker '    } finally { await lease.release(); }' `
    -Replacement ($gatewayReplacement + "`r`n") `
    -Label 'Enable pre-stream multi-route failover in gateway'

# Add route helper functions before releaseBestEffort only once.
Replace-Once `
    -Path $gatewayApp `
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
      // The original upstream failure remains the public error when the control
      // plane reports no alternate route or cannot complete failover safely.
      return null;
    }
  }

  async function routeSuccessBestEffort(reservationId: string): Promise<void> {
    if (!dependencies.controlPlane.routeSuccess) return;
    try {
      await dependencies.controlPlane.routeSuccess(reservationId);
    } catch {
      // Route-health telemetry must never block a successful completion.
    }
  }

  async function releaseBestEffort(reservationId: string): Promise<void> {
'@ `
    -Label 'Add gateway route failover helpers'

# Add retryable status helper before operationFailure.
Replace-Once `
    -Path $gatewayApp `
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
    -Label 'Add safe pre-stream failover status policy'

Write-Host ''
Write-Host 'SP Cambo Multi-Route R3 merge complete.'
Write-Host 'Run the verification commands in README_APPLY.md before deployment.'
