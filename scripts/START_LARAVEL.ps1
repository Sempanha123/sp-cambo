param([string]$ProjectRoot = '')
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) { throw 'Could not resolve the current script path. Pass -ProjectRoot explicitly.' }
    $scriptDir = Split-Path -Parent $scriptPath
    $ProjectRoot = Split-Path -Parent $scriptDir
}
$ProjectRoot = (Resolve-Path $ProjectRoot).Path
$backend = Join-Path $ProjectRoot 'backend'
if (-not (Test-Path (Join-Path $backend 'artisan'))) { throw "Laravel backend not found: $backend" }

Set-Location $backend
$schemaScript = Join-Path (Join-Path $ProjectRoot 'scripts') 'ENSURE_LOCAL_SCHEMA.ps1'
if (-not (Test-Path -LiteralPath $schemaScript)) { throw "Missing schema preflight: $schemaScript" }
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $schemaScript -ProjectRoot $ProjectRoot
if ($LASTEXITCODE -ne 0) { throw 'Database schema preflight failed.' }

# optimize:clear must not depend on MySQL being available just to clear local caches.
$oldCacheStore = $env:CACHE_STORE
try {
    $env:CACHE_STORE = 'file'
    php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { throw 'Laravel optimize:clear failed.' }
} finally {
    $env:CACHE_STORE = $oldCacheStore
}

Write-Host "Starting customer/API Laravel on http://127.0.0.1:8000" -ForegroundColor Cyan
Write-Host "Fix25 also requires scripts\START_CONTROL_PLANE.ps1 on :8001 for local Playground/Gateway callbacks." -ForegroundColor DarkGray
php artisan serve --host=127.0.0.1 --port=8000
