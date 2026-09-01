param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

$source = Join-Path $PSScriptRoot 'frontend\vitest.config.ts'
$target = Join-Path $project 'frontend\vitest.config.ts'

if (-not (Test-Path -LiteralPath $source)) {
    throw "R7 source file missing: $source"
}

if (-not (Test-Path -LiteralPath $target)) {
    throw "Project Vitest config missing: $target"
}

$sourceResolved = (Resolve-Path -LiteralPath $source).Path
$targetResolved = (Resolve-Path -LiteralPath $target).Path

if ($sourceResolved -ne $targetResolved) {
    Copy-Item -LiteralPath $source -Destination $target -Force
}

$nuxtDir = Join-Path $project 'frontend\.nuxt'
if (Test-Path -LiteralPath $nuxtDir) {
    Remove-Item -LiteralPath $nuxtDir -Recurse -Force
    Write-Host '[OK] Removed stale frontend\.nuxt'
}

Push-Location (Join-Path $project 'frontend')
try {
    pnpm exec nuxt prepare
    if ($LASTEXITCODE -ne 0) {
        throw "nuxt prepare failed with exit code $LASTEXITCODE"
    }
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] R7 Nuxt/Vitest project test environment applied.'
Write-Host 'Run VERIFY_FRONTEND_TEST_ENV_R7.ps1 next.'
