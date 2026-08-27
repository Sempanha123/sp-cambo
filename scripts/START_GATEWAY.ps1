param([string]$ProjectRoot = "")
$ErrorActionPreference = "Stop"

$ScriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ScriptRoot)) { $ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path }
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) { $ProjectRoot = Split-Path -Parent $ScriptRoot }
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path

try {
    $r = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:3010/health" -TimeoutSec 3
    $health = $null
    try { $health = $r.Content | ConvertFrom-Json } catch {}
    if ($health -and $health.data.model_routing -eq 'database_internal_model_id' -and $health.data.build -eq 'fix28') {
        Write-Host "[OK] Fix25 gateway is already running (HTTP $($r.StatusCode))." -ForegroundColor Green
        exit 0
    }
    throw 'Port 3010 is running an older/unknown gateway. Close that Gateway terminal/process first, then run START_GATEWAY.ps1 again so the database-model-routing build actually starts.'
} catch {
    if ($_.Exception.Message -like 'Port 3010 is running an older/unknown gateway*') { throw }
    if ($_.Exception.Response) {
        throw 'Port 3010 is already serving an unknown HTTP process. Stop it before starting the SP Cambo gateway.'
    }
}

function Get-DotEnvValue {
    param([string]$Path,[string]$Name)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }

    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*='
    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match $pattern } |
        Select-Object -Last 1

    if (-not $line) { return $null }

    $value = (($line -split '=',2)[1]).Trim()
    if ($value.Length -ge 2) {
        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1,$value.Length-2)
        }
    }
    return $value
}

$GatewayDir = Join-Path $ProjectRoot "gateway"
$BackendEnv = Join-Path $ProjectRoot "backend\.env"
$GatewayEnv = Join-Path $GatewayDir ".env"

$backendSecret = Get-DotEnvValue $BackendEnv "SP_CAMBO_INTERNAL_GATEWAY_SECRET"
$gatewaySecret = Get-DotEnvValue $GatewayEnv "SP_CAMBO_INTERNAL_GATEWAY_SECRET"

if ([string]::IsNullOrWhiteSpace($backendSecret)) {
    throw "SP_CAMBO_INTERNAL_GATEWAY_SECRET is missing in backend\.env."
}
if ($backendSecret.Length -lt 32) {
    throw "SP_CAMBO_INTERNAL_GATEWAY_SECRET must be at least 32 characters."
}

$env:SP_CAMBO_INTERNAL_GATEWAY_SECRET = $backendSecret

foreach ($name in @(
    "GATEWAY_HOST",
    "GATEWAY_PORT",
    "CONTROL_PLANE_BASE_URL",
    "GATEWAY_RATE_STORE",
    "REDIS_URL"
)) {
    $v = Get-DotEnvValue $GatewayEnv $name
    if (-not [string]::IsNullOrWhiteSpace($v)) {
        [Environment]::SetEnvironmentVariable($name,$v,"Process")
    }
}

if ([string]::IsNullOrWhiteSpace($env:GATEWAY_HOST)) { $env:GATEWAY_HOST="127.0.0.1" }
if ([string]::IsNullOrWhiteSpace($env:GATEWAY_PORT)) { $env:GATEWAY_PORT="3010" }
if ([string]::IsNullOrWhiteSpace($env:CONTROL_PLANE_BASE_URL)) {
    $env:CONTROL_PLANE_BASE_URL="http://127.0.0.1:8001"
}
elseif ($env:CONTROL_PLANE_BASE_URL -in @('http://127.0.0.1:8000','http://localhost:8000')) {
    # Upgrade the old local default in-process. On Windows, pointing Gateway
    # callbacks at the same single-process artisan server handling Playground
    # requests creates a Laravel -> Gateway -> Laravel deadlock.
    Write-Host '[FIX] Local Gateway control plane moved from port 8000 to 8001 to avoid the Windows artisan-serve deadlock.' -ForegroundColor Yellow
    $env:CONTROL_PLANE_BASE_URL='http://127.0.0.1:8001'
}
if ([string]::IsNullOrWhiteSpace($env:GATEWAY_RATE_STORE)) { $env:GATEWAY_RATE_STORE="memory" }

if ($gatewaySecret -eq $backendSecret) {
    Write-Host "[OK] Internal gateway secret matches." -ForegroundColor Green
} else {
    Write-Host "[WARN] gateway\.env secret differs/missing; using backend\.env for this process." -ForegroundColor Yellow
}

try {
    $cpHealth = Invoke-WebRequest -UseBasicParsing -Uri ($env:CONTROL_PLANE_BASE_URL.TrimEnd('/') + '/api/v1/health') -TimeoutSec 5
    if ([int]$cpHealth.StatusCode -ge 500) { throw "HTTP $($cpHealth.StatusCode)" }
    Write-Host "[OK] Dedicated control plane is reachable at $env:CONTROL_PLANE_BASE_URL." -ForegroundColor Green
}
catch {
    throw "Gateway control plane is not reachable at $env:CONTROL_PLANE_BASE_URL. Run scripts\START_CONTROL_PLANE.ps1 first (Fix25 local Windows stack)."
}

Set-Location -LiteralPath $GatewayDir

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "SP CAMBO INFERENCE GATEWAY" -ForegroundColor Cyan
Write-Host "http://127.0.0.1:3010" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Cyan

if (-not (Test-Path -LiteralPath ".\node_modules")) {
    npx pnpm@11.22.0 install
    if ($LASTEXITCODE -ne 0) { throw "Gateway dependency install failed." }
}

npx pnpm@11.22.0 dev
