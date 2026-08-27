param([string]$ProjectRoot = '')
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$backend = Join-Path $ProjectRoot 'backend'
$artisan = Join-Path $backend 'artisan'
$vendor = Join-Path $backend 'vendor\autoload.php'
$envFile = Join-Path $backend '.env'

if (-not (Test-Path -LiteralPath $artisan)) { throw "Laravel backend not found: $backend" }
if (-not (Test-Path -LiteralPath $vendor)) { throw 'Backend dependencies are missing. Run composer install in backend first.' }
if (-not (Test-Path -LiteralPath $envFile)) { throw 'backend/.env is missing.' }

function Has-EnvValue([string]$Name) {
    $line = Get-Content -LiteralPath $envFile | Where-Object { $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=\s*.+$') } | Select-Object -Last 1
    return $null -ne $line
}

$required = @('ANTHROPIC_BASE_URL', 'ANTHROPIC_AUTH_TOKEN')
$missing = @($required | Where-Object { -not (Has-EnvValue $_) })
if ($missing.Count -gt 0) {
    throw ('Missing backend/.env values: ' + ($missing -join ', '))
}

Write-Host ''
Write-Host '==========================================' -ForegroundColor DarkCyan
Write-Host ' SP CAMBO PLAYGROUND PROTOCOL RECOVERY' -ForegroundColor Cyan
Write-Host '==========================================' -ForegroundColor DarkCyan
Write-Host 'This re-probes both exact database-mapped OmniRoute models, records every verified protocol, and selects the safest streaming Playground protocol.' -ForegroundColor DarkGray
Write-Host 'ANTHROPIC_MODEL is not used by SP Cambo backend routing.' -ForegroundColor DarkGray
Write-Host 'Credentials are not printed.' -ForegroundColor DarkGray
Write-Host ''

Push-Location $backend
try {
    & php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { throw 'php artisan optimize:clear failed.' }

    & php artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'php artisan migrate failed.' }

    & php artisan db:seed --class=SellCatalogSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'SellCatalogSeeder failed. Confirm OmniRoute is running and both custom models exist.' }

    & php artisan catalog:sell-status
    if ($LASTEXITCODE -ne 0) {
        throw 'Sell catalog is not ready. Start/fix OmniRoute on ANTHROPIC_BASE_URL, then run FIX_PLAYGROUND.ps1 again.'
    }
}
finally {
    Pop-Location
}

Write-Host ''
Write-Host '[OK] Playground protocols were refreshed from real inference probes. Fix25 streams model output live in the browser.' -ForegroundColor Green
try {
    $gatewayHealth = Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:3010/health' -TimeoutSec 3
    if ($gatewayHealth.data.model_routing -eq 'database_internal_model_id' -and $gatewayHealth.data.build -eq 'fix28') {
        Write-Host '[OK] Running Gateway is Fix25 database-model-routing build.' -ForegroundColor Green
    }
    else {
        Write-Host '[ACTION] An older Gateway is still running on port 3010. Close its terminal/process and run .\scripts\START_GATEWAY.ps1 before testing Playground.' -ForegroundColor Yellow
    }
}
catch {
    Write-Host '[INFO] Gateway is not running. Start it with .\scripts\START_GATEWAY.ps1 before testing Playground.' -ForegroundColor Yellow
}
Write-Host 'For Windows local development, also keep scripts\START_CONTROL_PLANE.ps1 running on port 8001.' -ForegroundColor Cyan
Write-Host 'Reload Playground after Laravel/Control Plane/Gateway are running from this source tree.' -ForegroundColor Cyan
