param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

$frontendModules = Join-Path $project 'frontend\node_modules'
$gatewayModules = Join-Path $project 'gateway\node_modules'

if (-not (Test-Path -LiteralPath $frontendModules)) {
    throw 'frontend\node_modules is missing. Run PREPARE_NODE_DEPS_R5.ps1 first.'
}

if (-not (Test-Path -LiteralPath $gatewayModules)) {
    throw 'gateway\node_modules is missing. Run PREPARE_NODE_DEPS_R5.ps1 first.'
}

Write-Host '=== Backend ==='
Push-Location (Join-Path $project 'backend')
try {
    php artisan optimize:clear
    php artisan migrate --pretend
    php artisan route:list --path=model-route-pools
    php artisan route:list --path=internal/gateway
    php artisan test --filter=AdminModelRoutePoolTest
    php artisan test
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '=== Gateway ==='
Push-Location (Join-Path $project 'gateway')
try {
    pnpm run typecheck
    pnpm run test
    pnpm run build
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '=== Frontend ==='
Push-Location (Join-Path $project 'frontend')
try {
    pnpm run lint
    pnpm run typecheck
    pnpm run test
    pnpm run build
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] SP Cambo Multi-Route R5 verification completed.'
Write-Host 'Now inspect git status, commit the tested changes, and push normally.'
