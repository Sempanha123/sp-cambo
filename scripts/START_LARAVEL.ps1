param(
  [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
$backend = Join-Path $ProjectRoot 'backend'

if (-not (Test-Path (Join-Path $backend 'artisan'))) {
    throw "Laravel backend not found: $backend"
}

Set-Location $backend
php artisan optimize:clear

Write-Host "Starting Laravel on http://127.0.0.1:8000" -ForegroundColor Cyan
php artisan serve --host=127.0.0.1 --port=8000
