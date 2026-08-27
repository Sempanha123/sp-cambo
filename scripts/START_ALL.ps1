param([string]$ProjectRoot = "")

$ErrorActionPreference = "Stop"

$ScriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ScriptRoot)) {
    $ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
}
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $ScriptRoot
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path

function Test-Service {
    param(
        [string]$Url,
        [int]$TimeoutSec = 3
    )

    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec $TimeoutSec
        return ([int]$response.StatusCode -lt 500)
    }
    catch {
        if ($_.Exception.Response) {
            try { return ([int]$_.Exception.Response.StatusCode -lt 500) } catch {}
        }
        return $false
    }
}

function Start-ServiceWindow {
    param(
        [string]$Name,
        [string]$ScriptFile,
        [string]$HealthUrl = "",
        [int]$WarmupSeconds = 1
    )

    if (-not [string]::IsNullOrWhiteSpace($HealthUrl)) {
        if (Test-Service -Url $HealthUrl -TimeoutSec 3) {
            Write-Host "[SKIP] $Name is already running." -ForegroundColor Green
            return
        }
    }

    $serviceScript = Join-Path $ScriptRoot $ScriptFile
    if (-not (Test-Path -LiteralPath $serviceScript)) {
        throw "Missing service script: $serviceScript"
    }

    Write-Host "[START] $Name" -ForegroundColor Cyan

    $argumentLine = '-NoExit -NoProfile -ExecutionPolicy Bypass -File "' +
        $serviceScript + '" -ProjectRoot "' + $ProjectRoot + '"'

    Start-Process -FilePath "powershell.exe" -ArgumentList $argumentLine | Out-Null
    Start-Sleep -Seconds $WarmupSeconds
}

Write-Host ""
Write-Host "==========================================" -ForegroundColor DarkCyan
Write-Host "       SP CAMBO EASY LOCAL START          " -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor DarkCyan
Write-Host "Project: $ProjectRoot"
Write-Host "Mode: native Windows services (Docker is NOT required)." -ForegroundColor DarkGray
Write-Host ""

$schemaScript = Join-Path $ScriptRoot "ENSURE_LOCAL_SCHEMA.ps1"
if (-not (Test-Path -LiteralPath $schemaScript)) { throw "Missing schema preflight: $schemaScript" }
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $schemaScript -ProjectRoot $ProjectRoot
if ($LASTEXITCODE -ne 0) { throw "Database schema preflight failed." }

Start-ServiceWindow "Frontend" "START_FRONTEND.ps1" "http://127.0.0.1:3000/" 1
Start-ServiceWindow "Laravel" "START_LARAVEL.ps1" "http://127.0.0.1:8000/api/v1/health" 2

# Windows `php artisan serve` is single-process. Hosted Playground requests call
# Laravel -> Gateway -> Laravel internal preflight, so the Gateway callback must
# use a second Laravel process locally to avoid a re-entrant deadlock.
Start-ServiceWindow "Control Plane" "START_CONTROL_PLANE.ps1" "http://127.0.0.1:8001/api/v1/health" 2

# A healthy old Gateway is still the wrong Gateway after a source update.
# Do not silently skip port 3010 unless it identifies the database-routing build.
$gatewayState = 'down'
try {
    $gatewayHealth = Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:3010/health' -TimeoutSec 3
    $gatewayState = if ($gatewayHealth.data.model_routing -eq 'database_internal_model_id' -and $gatewayHealth.data.build -eq 'fix28') { 'current' } else { 'stale' }
}
catch { $gatewayState = 'down' }

if ($gatewayState -eq 'current') {
    Write-Host '[SKIP] Gateway is already running from the Fix25 database-routing build.' -ForegroundColor Green
}
elseif ($gatewayState -eq 'stale') {
    throw 'An older/unknown Gateway is already running on port 3010. Close that Gateway terminal/process, then run START_ALL.ps1 again.'
}
else {
    Start-ServiceWindow "Gateway" "START_GATEWAY.ps1" "" 1
}

Start-ServiceWindow "KHQR" "START_KHQR.ps1" "http://127.0.0.1:3011/health" 1

# Scheduler has no HTTP endpoint, so start it only if no schedule:work process exists.
$schedulerRunning = $false
try {
    $schedulerRunning = @(Get-CimInstance Win32_Process -ErrorAction Stop |
        Where-Object {
            $_.CommandLine -and
            $_.CommandLine -match 'artisan\s+schedule:work'
        }).Count -gt 0
} catch {}

if ($schedulerRunning) {
    Write-Host "[SKIP] Scheduler is already running." -ForegroundColor Green
}
else {
    Start-ServiceWindow "Scheduler" "START_SCHEDULER.ps1" "" 1
}

Write-Host ""
Write-Host "Startup command finished." -ForegroundColor Green
Write-Host "Existing services were left untouched." -ForegroundColor DarkGray
Write-Host ""
Write-Host "Wait for Nuxt to finish compiling, then run:" -ForegroundColor Cyan
Write-Host 'powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\CHECK_ALL.ps1'
