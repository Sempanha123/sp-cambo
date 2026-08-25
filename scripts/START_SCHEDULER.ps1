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
$backend = Join-Path $ProjectRoot 'backend'
if (-not (Test-Path (Join-Path $backend 'artisan'))) {
  throw "Laravel backend not found: $backend"
}

Set-Location $backend
Write-Host "Starting SP Cambo scheduler (auto payment + Telegram reconciliation)..." -ForegroundColor Cyan
Write-Host "Keep this terminal open during local development." -ForegroundColor DarkGray
php artisan schedule:work
