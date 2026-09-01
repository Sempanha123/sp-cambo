param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

Write-Host '=== Backend ==='
Push-Location (Join-Path $project 'backend')
try {
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
    pnpm install --frozen-lockfile
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
    pnpm install --frozen-lockfile
    pnpm run lint
    pnpm run typecheck
    pnpm run test
    pnpm run build
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] R4 verification finished successfully.'
Write-Host 'You can commit this tested state, then deploy.'
