param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

function Require-File {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Required file is missing: $Path"
    }
}

function Read-Text {
    param([string]$Path)
    return [System.IO.File]::ReadAllText($Path)
}

function Write-Text {
    param([string]$Path, [string]$Content)
    [System.IO.File]::WriteAllText(
        $Path,
        $Content,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

$frontend = Join-Path $project 'frontend'
$frontendPackage = Join-Path $frontend 'package.json'
$frontendLock = Join-Path $frontend 'pnpm-lock.yaml'
$frontendWorkspace = Join-Path $frontend 'pnpm-workspace.yaml'
$startAll = Join-Path $project 'START_ALL.ps1'

foreach ($path in @($frontendPackage, $frontendLock, $frontendWorkspace, $startAll)) {
    Require-File $path
}

Write-Host ''
Write-Host '=== SP Cambo Vue Runtime Cleanup R8 ==='
Write-Host ''

# -------------------------------------------------------------------------
# 1) Diagnose the exact duplicate-runtime condition before removing it.
# -------------------------------------------------------------------------
$rootNodeModules = Join-Path $project 'node_modules'
$rootPackage = Join-Path $project 'package.json'
$rootPackageLock = Join-Path $project 'package-lock.json'
$frontendPackageLock = Join-Path $frontend 'package-lock.json'

if (Test-Path -LiteralPath $rootNodeModules) {
    Write-Host '[FOUND] Root node_modules exists and can shadow frontend Vue packages.' -ForegroundColor Yellow
}

if (Test-Path -LiteralPath $rootPackage) {
    Write-Host '[FOUND] Root package.json exists.' -ForegroundColor Yellow
}

if (Test-Path -LiteralPath $rootPackageLock) {
    Write-Host '[FOUND] Root package-lock.json exists.' -ForegroundColor Yellow
}

if (Test-Path -LiteralPath $frontendPackageLock) {
    Write-Host '[FOUND] frontend/package-lock.json exists beside pnpm-lock.yaml.' -ForegroundColor Yellow
}

# -------------------------------------------------------------------------
# 2) Ensure the frontend declares the one canonical package manager.
# -------------------------------------------------------------------------
$pkg = Get-Content -LiteralPath $frontendPackage -Raw | ConvertFrom-Json

if (-not $pkg.packageManager) {
    $ordered = [ordered]@{}
    foreach ($property in $pkg.PSObject.Properties) {
        $ordered[$property.Name] = $property.Value
        if ($property.Name -eq 'type') {
            $ordered['packageManager'] = 'pnpm@11.22.0'
        }
    }
    $json = $ordered | ConvertTo-Json -Depth 100
    Write-Text $frontendPackage ($json + [Environment]::NewLine)
    Write-Host '[OK] Added frontend packageManager=pnpm@11.22.0'
} elseif ($pkg.packageManager -ne 'pnpm@11.22.0') {
    $pkg.packageManager = 'pnpm@11.22.0'
    $json = $pkg | ConvertTo-Json -Depth 100
    Write-Text $frontendPackage ($json + [Environment]::NewLine)
    Write-Host '[OK] Normalized frontend packageManager=pnpm@11.22.0'
} else {
    Write-Host '[SKIP] Frontend packageManager already canonical.'
}

# -------------------------------------------------------------------------
# 3) Update START_ALL so it never recreates npm node_modules for the frontend.
# -------------------------------------------------------------------------
$startContent = Read-Text $startAll

$oldFrontendBootstrap = @'
if (-not (Test-Path -LiteralPath '.\node_modules')) {
    if (-not (Test-Path -LiteralPath '.\package-lock.json')) { throw 'frontend/package-lock.json is missing.' }
    npm ci --no-audit --no-fund
    if (`$LASTEXITCODE -ne 0) { throw 'Frontend npm ci failed.' }
}
npm run dev -- --host 127.0.0.1 --port 3000
'@

$newFrontendBootstrap = @'
if (-not (Test-Path -LiteralPath '.\node_modules')) {
    npx pnpm@11.22.0 install --frozen-lockfile
    if (`$LASTEXITCODE -ne 0) { throw 'Frontend pnpm install failed.' }
}
npx pnpm@11.22.0 dev -- --host 127.0.0.1 --port 3000
'@

if ($startContent.Contains($newFrontendBootstrap)) {
    Write-Host '[SKIP] START_ALL frontend already uses pnpm.'
} elseif ($startContent.Contains($oldFrontendBootstrap)) {
    Write-Text $startAll ($startContent.Replace($oldFrontendBootstrap, $newFrontendBootstrap))
    Write-Host '[OK] START_ALL frontend changed from npm to pnpm.'
} else {
    throw 'Could not find the expected frontend dependency block in START_ALL.ps1. R8 stopped before guessing.'
}

# -------------------------------------------------------------------------
# 4) Remove the duplicate package roots and mixed lockfile.
#
# Root package.json only existed to install @pinia/nuxt, which belongs inside
# frontend/package.json. Keeping it creates C:\...\SP Cambo\node_modules and
# gives Vue two different runtime-core instances.
# -------------------------------------------------------------------------
foreach ($path in @($rootPackage, $rootPackageLock, $frontendPackageLock)) {
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Force
        Write-Host "[OK] Removed $path"
    }
}

# -------------------------------------------------------------------------
# 5) Clean generated/install state so no stale Vue instance survives.
# -------------------------------------------------------------------------
$runningNode = @(Get-Process node -ErrorAction SilentlyContinue)
if ($runningNode.Count -gt 0) {
    Write-Host ''
    Write-Warning 'Node processes are running. Close SP Cambo dev servers / Vitest watchers before cleanup if Windows reports EBUSY.'
    $runningNode | Select-Object Id, ProcessName, Path | Format-Table -AutoSize
}

$pathsToRemove = @(
    $rootNodeModules,
    (Join-Path $frontend 'node_modules'),
    (Join-Path $frontend '.nuxt')
)

foreach ($path in $pathsToRemove) {
    if (Test-Path -LiteralPath $path) {
        try {
            Remove-Item -LiteralPath $path -Recurse -Force
            Write-Host "[OK] Removed $path"
        } catch {
            Write-Host ''
            Write-Host '[EBUSY] Windows still has a Node/Vitest file open.' -ForegroundColor Red
            Write-Host 'Close SP Cambo dev/test terminals and VS Code Vitest runners, then rerun this same R8 script.'
            throw
        }
    }
}

# -------------------------------------------------------------------------
# 6) Install only the frontend pnpm tree and regenerate Nuxt metadata.
# -------------------------------------------------------------------------
Push-Location $frontend
try {
    Write-Host ''
    Write-Host 'Installing canonical frontend dependency tree...'
    pnpm install --frozen-lockfile
    if ($LASTEXITCODE -ne 0) {
        throw "pnpm install failed with exit code $LASTEXITCODE"
    }

    pnpm exec nuxt prepare
    if ($LASTEXITCODE -ne 0) {
        throw "nuxt prepare failed with exit code $LASTEXITCODE"
    }

    Write-Host ''
    Write-Host 'Resolved Vue package:'
    node -e "console.log(require.resolve('vue/package.json'))"

    Write-Host 'Resolved @vue/runtime-core package:'
    node -e "console.log(require.resolve('@vue/runtime-core/package.json'))"

    Write-Host ''
    Write-Host 'Installed Vue versions:'
    pnpm list vue @vue/runtime-core --depth 20
} finally {
    Pop-Location
}

if (Test-Path -LiteralPath $rootNodeModules) {
    throw 'Root node_modules still exists after cleanup.'
}

Write-Host ''
Write-Host '[PASS] R8 removed the duplicate parent Vue dependency tree.'
Write-Host 'Run VERIFY_VUE_RUNTIME_CLEANUP_R8.ps1 next.'
