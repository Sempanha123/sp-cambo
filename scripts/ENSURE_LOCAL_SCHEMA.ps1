param([string]$ProjectRoot = '')
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) { throw 'Could not resolve script path. Pass -ProjectRoot explicitly.' }
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $scriptPath)
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$backend = Join-Path $ProjectRoot 'backend'
$artisan = Join-Path $backend 'artisan'
$vendor = Join-Path $backend 'vendor\autoload.php'

if (-not (Test-Path -LiteralPath $artisan)) { throw "Laravel backend not found: $backend" }
if (-not (Test-Path -LiteralPath $vendor)) {
    throw 'Backend dependencies are missing. Run composer install in backend before starting the full stack.'
}

Push-Location $backend
try {
    Write-Host '[DB] Applying pending SP Cambo migrations...' -ForegroundColor Cyan
    & php artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Laravel migration failed. Fix the migration error before starting SP Cambo.' }

    & php artisan system:check-access-allocation-schema
    if ($LASTEXITCODE -ne 0) {
        throw 'The access-allocation schema is still incomplete after migration. Check the migration output above.'
    }

    Write-Host '[OK] Database schema is ready for Playground and API key details.' -ForegroundColor Green
}
finally {
    Pop-Location
}
