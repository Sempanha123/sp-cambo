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
        throw "Could not find the expected block for: $Label`nFile: $Path`nYour branch may differ from the GitHub main version used to build R1."
    }

    $updated = $content.Replace($Old, $New)
    Set-Content -LiteralPath $Path -Value $updated -NoNewline
    Write-Host "[OK] $Label"
}

$billing = Join-Path $ProjectRoot 'backend\app\Services\InferenceBillingService.php'
$providers = Join-Path $ProjectRoot 'backend\bootstrap\providers.php'

if (-not (Test-Path $billing)) { throw "Missing $billing" }
if (-not (Test-Path $providers)) { throw "Missing $providers" }

$oldCtor = @'
    public function __construct(private readonly ReservationService $reservations) {}
'@

$newCtor = @'
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ModelRoutePoolService $routePools,
    ) {}
'@

Replace-Once -Path $billing -Old $oldCtor -New $newCtor -Label 'Inject ModelRoutePoolService'

$oldRoute = @'
            $model = $alias->model()->with('provider.activeConnectionRevision')->firstOrFail();
            $provider = $model->provider;
            $revision = $provider?->activeConnectionRevision;
            if (! $provider || ! $provider->enabled || ! $revision || ! $revision->isRouteReady()) {
                throw new InvalidArgumentException('The selected model route is not ready.');
            }
'@

$newRoute = @'
            $model = $alias->model()->with('provider')->firstOrFail();
            $provider = $model->provider;
            if (! $provider || ! $provider->enabled) {
                throw new InferenceAccessException(
                    'model_route_unavailable',
                    'The selected model route is not ready.',
                    503,
                );
            }
            $revision = $this->routePools->select($alias, $provider);
'@

Replace-Once -Path $billing -Old $oldRoute -New $newRoute -Label 'Enable weighted least-connections route selection'

# InferenceBillingService already imports other exception classes in some branches.
# Ensure InferenceAccessException is available because the new route block uses it.
$content = Get-Content -LiteralPath $billing -Raw
if (-not $content.Contains('use App\Exceptions\InferenceAccessException;')) {
    $namespaceNeedle = "namespace App\Services;`r`n"
    $namespaceNeedleLf = "namespace App\Services;`n"
    if ($content.Contains($namespaceNeedle)) {
        $content = $content.Replace($namespaceNeedle, "$namespaceNeedle`r`nuse App\Exceptions\InferenceAccessException;`r`n")
    } elseif ($content.Contains($namespaceNeedleLf)) {
        $content = $content.Replace($namespaceNeedleLf, "$namespaceNeedleLf`nuse App\Exceptions\InferenceAccessException;`n")
    } else {
        throw "Could not find InferenceBillingService namespace declaration."
    }
    Set-Content -LiteralPath $billing -Value $content -NoNewline
    Write-Host '[OK] Added InferenceAccessException import'
}

$providerContent = Get-Content -LiteralPath $providers -Raw
if (-not $providerContent.Contains('ModelRoutePoolRouteServiceProvider')) {
    $providerContent = $providerContent.Replace(
        'use App\Providers\AppServiceProvider;',
        "use App\Providers\AppServiceProvider;`r`nuse App\Providers\ModelRoutePoolRouteServiceProvider;"
    )

    $providerContent = $providerContent.Replace(
        '    AppServiceProvider::class,',
        "    AppServiceProvider::class,`r`n    ModelRoutePoolRouteServiceProvider::class,"
    )

    Set-Content -LiteralPath $providers -Value $providerContent -NoNewline
    Write-Host '[OK] Registered ModelRoutePoolRouteServiceProvider'
} else {
    Write-Host '[SKIP] ModelRoutePoolRouteServiceProvider already registered.'
}

Write-Host ''
Write-Host 'R1 merge completed. Next run:'
Write-Host '  cd backend'
Write-Host '  php artisan migrate'
Write-Host '  php artisan optimize:clear'
Write-Host '  php artisan route:list --path=model-route-pools'
Write-Host '  php artisan test'
Write-Host ''
Write-Host 'Then frontend:'
Write-Host '  cd ..\frontend'
Write-Host '  npm run typecheck'
Write-Host '  npm run build'
