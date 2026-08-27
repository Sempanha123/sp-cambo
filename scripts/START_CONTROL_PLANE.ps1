param([string]$ProjectRoot = '')
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) { throw 'Could not resolve the current script path. Pass -ProjectRoot explicitly.' }
    $scriptDir = Split-Path -Parent $scriptPath
    $ProjectRoot = Split-Path -Parent $scriptDir
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$backend = Join-Path $ProjectRoot 'backend'
if (-not (Test-Path (Join-Path $backend 'artisan'))) { throw "Laravel backend not found: $backend" }

Set-Location $backend

# IMPORTANT (Windows local development):
# `php artisan serve` is a single PHP CLI server process on Windows. A hosted
# Playground request enters Laravel :8000, waits on Gateway :3010, and Gateway
# must call Laravel's internal billing/preflight endpoints before it can call
# OmniRoute. Sending that callback to the same :8000 process deadlocks because
# it is already busy handling the outer Playground request.
#
# Run a second Laravel process on :8001 exclusively as the Gateway control-plane
# callback target. Both processes share the same application/database, so this
# preserves the production architecture while making the local Windows stack
# re-entrant.
Write-Host 'Starting SP Cambo local control plane on http://127.0.0.1:8001' -ForegroundColor Cyan
Write-Host 'Gateway internal callbacks use this process; browser/API traffic stays on :8000.' -ForegroundColor DarkGray
php artisan serve --host=127.0.0.1 --port=8001
