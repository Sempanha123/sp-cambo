param(
    [int]$StartupTimeoutSec = 90
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path -LiteralPath (Split-Path -Parent $MyInvocation.MyCommand.Path)).Path
$Backend = Join-Path $ProjectRoot 'backend'
$Frontend = Join-Path $ProjectRoot 'frontend'
$Gateway = Join-Path $ProjectRoot 'gateway'
$BackendEnv = Join-Path $Backend '.env'
$GatewayEnv = Join-Path $Gateway '.env'

function Get-DotEnvValue {
    param([string]$Path, [string]$Name)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*='
    $line = Get-Content -LiteralPath $Path | Where-Object { $_ -match $pattern } | Select-Object -Last 1
    if (-not $line) { return $null }
    $value = (($line -split '=', 2)[1]).Trim()
    if ($value.Length -ge 2) {
        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
    }
    return $value
}

function Test-Http {
    param([string]$Url, [int]$TimeoutSec = 3)
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec $TimeoutSec
        return ([int]$response.StatusCode -lt 500)
    } catch {
        if ($_.Exception.Response) {
            try { return ([int]$_.Exception.Response.StatusCode -lt 500) } catch {}
        }
        return $false
    }
}

function Wait-Http {
    param([string]$Name, [string]$Url, [int]$TimeoutSec)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        if (Test-Http -Url $Url -TimeoutSec 3) {
            Write-Host "[READY] $Name -> $Url" -ForegroundColor Green
            return
        }
        Start-Sleep -Seconds 1
    }
    throw "$Name did not become reachable at $Url within $TimeoutSec seconds. Check its service window."
}

function Start-ServiceWindow {
    param(
        [string]$Name,
        [string]$Command,
        [string]$HealthUrl = ''
    )

    if ($HealthUrl -and (Test-Http -Url $HealthUrl -TimeoutSec 3)) {
        Write-Host "[SKIP] $Name is already running." -ForegroundColor Green
        return
    }

    Write-Host "[START] $Name" -ForegroundColor Cyan
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($Command)
    $encoded = [Convert]::ToBase64String($bytes)
    Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded) | Out-Null

    if ($HealthUrl) {
        Wait-Http -Name $Name -Url $HealthUrl -TimeoutSec $StartupTimeoutSec
    }
}

function Assert-Path {
    param([string]$Path, [string]$Label)
    if (-not (Test-Path -LiteralPath $Path)) { throw "$Label not found: $Path" }
}

Write-Host ''
Write-Host '====================================================' -ForegroundColor DarkCyan
Write-Host '          SP CAMBO - ONE COMMAND LOCAL START' -ForegroundColor Cyan
Write-Host '====================================================' -ForegroundColor DarkCyan
Write-Host "Project: $ProjectRoot"
Write-Host ''

Assert-Path -Path (Join-Path $Backend 'artisan') -Label 'Laravel backend'
Assert-Path -Path (Join-Path $Frontend 'package.json') -Label 'Nuxt frontend'
Assert-Path -Path (Join-Path $Gateway 'package.json') -Label 'Inference gateway'
Assert-Path -Path $BackendEnv -Label 'backend/.env'

Write-Host '[1/7] Laravel preflight' -ForegroundColor Cyan
Push-Location $Backend
try {
    $oldCacheStore = $env:CACHE_STORE
    try {
        $env:CACHE_STORE = 'file'
        & php artisan optimize:clear
        if ($LASTEXITCODE -ne 0) { throw 'php artisan optimize:clear failed.' }
    } finally {
        $env:CACHE_STORE = $oldCacheStore
    }

    Write-Host '[INFO] Database bootstrap is intentionally manual.' -ForegroundColor DarkGray
    Write-Host '[INFO] For a new local database run: php artisan migrate:fresh --seed' -ForegroundColor Yellow
} finally {
    Pop-Location
}

# Read gateway process settings once. Child PowerShell windows inherit these
# process environment variables, so secrets never need to appear in command-line arguments.
$gatewaySecret = Get-DotEnvValue -Path $BackendEnv -Name 'SP_CAMBO_INTERNAL_GATEWAY_SECRET'
if ([string]::IsNullOrWhiteSpace($gatewaySecret) -or $gatewaySecret.Length -lt 32) {
    throw 'backend/.env SP_CAMBO_INTERNAL_GATEWAY_SECRET is missing or shorter than 32 characters.'
}
$env:SP_CAMBO_INTERNAL_GATEWAY_SECRET = $gatewaySecret
$env:CONTROL_PLANE_BASE_URL = 'http://127.0.0.1:8001'

foreach ($name in @('GATEWAY_HOST', 'GATEWAY_PORT', 'GATEWAY_RATE_STORE', 'REDIS_URL')) {
    $value = Get-DotEnvValue -Path $GatewayEnv -Name $name
    if (-not [string]::IsNullOrWhiteSpace($value)) {
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}
if ([string]::IsNullOrWhiteSpace($env:GATEWAY_HOST)) { $env:GATEWAY_HOST = '127.0.0.1' }
if ([string]::IsNullOrWhiteSpace($env:GATEWAY_PORT)) { $env:GATEWAY_PORT = '3010' }
if ([string]::IsNullOrWhiteSpace($env:GATEWAY_RATE_STORE)) { $env:GATEWAY_RATE_STORE = 'memory' }

Write-Host '[2/7] Laravel API :8000' -ForegroundColor Cyan
$backendEsc = $Backend.Replace("'", "''")
Start-ServiceWindow -Name 'Laravel API' -HealthUrl 'http://127.0.0.1:8000/api/v1/health' -Command @"
`$ErrorActionPreference='Stop'
Set-Location -LiteralPath '$backendEsc'
php artisan serve --host=127.0.0.1 --port=8000
"@

Write-Host '[3/7] Laravel control plane :8001' -ForegroundColor Cyan
Start-ServiceWindow -Name 'Control Plane' -HealthUrl 'http://127.0.0.1:8001/api/v1/health' -Command @"
`$ErrorActionPreference='Stop'
Set-Location -LiteralPath '$backendEsc'
php artisan serve --host=127.0.0.1 --port=8001
"@

Write-Host '[4/7] Inference Gateway :3010' -ForegroundColor Cyan
$gatewayEsc = $Gateway.Replace("'", "''")
Start-ServiceWindow -Name 'Gateway' -HealthUrl 'http://127.0.0.1:3010/health' -Command @"
`$ErrorActionPreference='Stop'
Set-Location -LiteralPath '$gatewayEsc'
if (-not (Test-Path -LiteralPath '.\node_modules')) {
    npx pnpm@11.22.0 install
    if (`$LASTEXITCODE -ne 0) { throw 'Gateway dependency install failed.' }
}
npx pnpm@11.22.0 dev
"@
Wait-Http -Name 'Gateway readiness' -Url 'http://127.0.0.1:3010/ready' -TimeoutSec $StartupTimeoutSec

Write-Host '[5/7] KHQR payment sidecar :3011' -ForegroundColor Cyan
$khqrSecret = Get-DotEnvValue -Path $BackendEnv -Name 'BAKONG_KHQR_GENERATOR_SECRET'
if ([string]::IsNullOrWhiteSpace($khqrSecret) -or $khqrSecret.Length -lt 32) {
    Write-Host '[SKIP] KHQR sidecar: BAKONG_KHQR_GENERATOR_SECRET is not configured.' -ForegroundColor Yellow
} else {
    $env:BAKONG_KHQR_GENERATOR_SECRET = $khqrSecret
    $env:KHQR_HOST = '127.0.0.1'
    $env:KHQR_PORT = '3011'
    Start-ServiceWindow -Name 'KHQR' -HealthUrl 'http://127.0.0.1:3011/health' -Command @"
`$ErrorActionPreference='Stop'
Set-Location -LiteralPath '$gatewayEsc'
if (-not (Test-Path -LiteralPath '.\node_modules')) {
    npx pnpm@11.22.0 install
    if (`$LASTEXITCODE -ne 0) { throw 'Gateway dependency install failed.' }
}
npx pnpm@11.22.0 khqr
"@
}

Write-Host '[6/7] Nuxt frontend :3000' -ForegroundColor Cyan
$frontendEsc = $Frontend.Replace("'", "''")
Start-ServiceWindow -Name 'Frontend' -HealthUrl 'http://127.0.0.1:3000/' -Command @"
`$ErrorActionPreference='Stop'
Set-Location -LiteralPath '$frontendEsc'
if (-not (Test-Path -LiteralPath '.\node_modules')) {
    if (-not (Test-Path -LiteralPath '.\package-lock.json')) { throw 'frontend/package-lock.json is missing.' }
    npm ci --no-audit --no-fund
    if (`$LASTEXITCODE -ne 0) { throw 'Frontend npm ci failed.' }
}
npm run dev -- --host 127.0.0.1 --port 3000
"@

Write-Host '[7/7] Laravel scheduler' -ForegroundColor Cyan
$schedulerRunning = $false
try {
    $schedulerRunning = @(
        Get-CimInstance Win32_Process -ErrorAction Stop |
        Where-Object { $_.CommandLine -and $_.CommandLine -match 'artisan\s+schedule:work' }
    ).Count -gt 0
} catch {}

if ($schedulerRunning) {
    Write-Host '[SKIP] Scheduler is already running.' -ForegroundColor Green
} else {
    Start-ServiceWindow -Name 'Scheduler' -Command @"
`$ErrorActionPreference='Stop'
Set-Location -LiteralPath '$backendEsc'
php artisan schedule:work
"@
}

Write-Host ''
Write-Host 'Checking the seeded OmniRoute sell catalog...' -ForegroundColor Cyan
Push-Location $Backend
try {
    & php artisan catalog:sell-status
    if ($LASTEXITCODE -ne 0) {
        Write-Host '[WARN] The stack is running, but the 2-model OmniRoute sell catalog is not fully READY.' -ForegroundColor Yellow
        Write-Host '[WARN] Open Admin > Providers > OmniRoute and Probe the seeded PENDING revision.' -ForegroundColor Yellow
        Write-Host '[WARN] If a seeded private model label is not a real OmniRoute model ID, use Discover upstream and remap it.' -ForegroundColor Yellow
        Write-Host '[WARN] Runtime routing uses the encrypted database revision; no OMNIROUTE_BASE_URL / OMNIROUTE_API_KEY backend env values are used.' -ForegroundColor Yellow
    }
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '====================================================' -ForegroundColor Green
Write-Host ' SP CAMBO STARTED' -ForegroundColor Green
Write-Host '====================================================' -ForegroundColor Green
Write-Host 'Frontend:      http://127.0.0.1:3000'
Write-Host 'Laravel API:   http://127.0.0.1:8000'
Write-Host 'Control Plane: http://127.0.0.1:8001'
Write-Host 'Gateway:       http://127.0.0.1:3010'
if (-not [string]::IsNullOrWhiteSpace($khqrSecret) -and $khqrSecret.Length -ge 32) {
    Write-Host 'KHQR:          http://127.0.0.1:3011'
}
Write-Host ''
Write-Host 'Normal startup: .\START_ALL.ps1' -ForegroundColor Cyan
Write-Host 'Stop services:  .\STOP_ALL.ps1' -ForegroundColor Cyan
