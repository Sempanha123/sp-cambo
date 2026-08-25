param(
  [string]$ProjectRoot = ''
)

$ErrorActionPreference = 'Stop'

# Resolve the project root after parameter binding. Windows PowerShell 5.1 can
# expose an empty $PSScriptRoot while evaluating a parameter default in some
# launch paths, so use the actual script path from $MyInvocation instead.
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        throw 'Could not resolve the current script path. Pass -ProjectRoot explicitly.'
    }
    $scriptDir = Split-Path -Parent $scriptPath
    $ProjectRoot = Split-Path -Parent $scriptDir
}
$ProjectRoot = (Resolve-Path $ProjectRoot).Path
$repair = Join-Path $ProjectRoot 'scripts\APPLY_LOCAL_STACK_FIX.ps1'
if (-not (Test-Path $repair)) {
    throw "APPLY_LOCAL_STACK_FIX.ps1 not found."
}

# Repair/synchronize env first.
& powershell -ExecutionPolicy Bypass -File $repair -ProjectRoot $ProjectRoot
if ($LASTEXITCODE -ne 0) {
    throw "Local environment repair failed."
}

$laravelScript = Join-Path $ProjectRoot 'scripts\START_LARAVEL.ps1'
$gatewayScript = Join-Path $ProjectRoot 'scripts\START_LOCAL_GATEWAY.ps1'
$khqrScript = Join-Path $ProjectRoot 'scripts\START_KHQR.ps1'
$schedulerScript = Join-Path $ProjectRoot 'scripts\START_SCHEDULER.ps1'

Write-Host ""
Write-Host "Opening four local service terminals..." -ForegroundColor Cyan

Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-ExecutionPolicy', 'Bypass',
    '-File', ('"' + $laravelScript + '"'),
    '-ProjectRoot', ('"' + $ProjectRoot + '"')
)

Start-Sleep -Seconds 2

Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-ExecutionPolicy', 'Bypass',
    '-File', ('"' + $gatewayScript + '"'),
    '-ProjectRoot', ('"' + $ProjectRoot + '"')
)

Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-ExecutionPolicy', 'Bypass',
    '-File', ('"' + $khqrScript + '"'),
    '-ProjectRoot', ('"' + $ProjectRoot + '"')
)

Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-ExecutionPolicy', 'Bypass',
    '-File', ('"' + $schedulerScript + '"'),
    '-ProjectRoot', ('"' + $ProjectRoot + '"')
)

Write-Host ""
Write-Host "Services launched. Wait 5-10 seconds, then run:" -ForegroundColor Green
Write-Host 'powershell -ExecutionPolicy Bypass -File ".\scripts\CHECK_LOCAL_STACK.ps1"'
