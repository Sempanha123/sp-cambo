[CmdletBinding()]
param(
    [switch]$SkipInstall
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path

Write-Host "=== SP Cambo validation repair ===" -ForegroundColor Cyan

Push-Location (Join-Path $Root 'backend')
try {
    if (-not $SkipInstall) { composer install --no-interaction --prefer-dist --no-progress }
    composer validate --no-check-publish
    if ($LASTEXITCODE -ne 0) { throw "Composer metadata validation failed with exit code $LASTEXITCODE" }
    php artisan config:clear
    php artisan test --filter=ApiKeyCheckTest
    if ($LASTEXITCODE -ne 0) { throw "ApiKeyCheckTest failed with exit code $LASTEXITCODE" }
} finally { Pop-Location }

Push-Location (Join-Path $Root 'frontend')
try {
    if (-not $SkipInstall) { npm install --ignore-scripts --no-audit --no-fund }
    npm run postinstall
    npm run lint -- --fix
    if ($LASTEXITCODE -ne 0) { throw "ESLint autofix left non-fixable errors. Run npm run lint and inspect the remaining lines." }
    npm run typecheck
    if ($LASTEXITCODE -ne 0) { throw "Frontend typecheck failed with exit code $LASTEXITCODE" }
    npx vitest run tests/component/PlaygroundPage.spec.ts
    if ($LASTEXITCODE -ne 0) { throw "Playground component test failed with exit code $LASTEXITCODE" }
    npm run test
    if ($LASTEXITCODE -ne 0) { throw "Frontend tests failed with exit code $LASTEXITCODE" }
} finally { Pop-Location }

Push-Location (Join-Path $Root 'gateway')
try {
    if (-not $SkipInstall) { pnpm install --frozen-lockfile }
    pnpm run typecheck
    if ($LASTEXITCODE -ne 0) { throw "Gateway typecheck failed with exit code $LASTEXITCODE" }
    pnpm exec vitest run tests/app.test.ts
    if ($LASTEXITCODE -ne 0) { throw "Gateway app test failed with exit code $LASTEXITCODE" }
} finally { Pop-Location }

Write-Host "Focused backend + frontend + gateway validation repair passed." -ForegroundColor Green
