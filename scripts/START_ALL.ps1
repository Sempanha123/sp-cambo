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
        Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec $TimeoutSec | Out-Null
        return $true
    }
    catch {
        if ($_.Exception.Response) {
            return $true
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
Write-Host ""

Start-ServiceWindow "Frontend" "START_FRONTEND.ps1" "http://127.0.0.1:3000/" 1
Start-ServiceWindow "Laravel" "START_LARAVEL.ps1" "http://127.0.0.1:8000/api/v1/health" 2
Start-ServiceWindow "Gateway" "START_GATEWAY.ps1" "http://127.0.0.1:3010/health" 1
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
