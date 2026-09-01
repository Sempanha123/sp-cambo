param(
    [string]$ProjectRoot = (Get-Location).Path,
    [switch]$CleanFrontend
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

$nodeVersion = (& node -v).Trim()
$pnpmVersion = (& pnpm -v).Trim()

Write-Host "Node: $nodeVersion"
Write-Host "pnpm: $pnpmVersion"

if ($nodeVersion -notmatch '^v(24|2[6-9]|[3-9][0-9])\.') {
    throw "SP Cambo requires Node 24.15+ or Node 26+. Current version: $nodeVersion"
}

if ($pnpmVersion -notmatch '^11\.') {
    throw "Use pnpm 11 for this project. Install it with: npm install -g pnpm@11.22.0"
}

$nodeProcesses = Get-Process node -ErrorAction SilentlyContinue
if ($nodeProcesses) {
    Write-Host ''
    Write-Warning 'Node processes are currently running.'
    Write-Warning 'If pnpm reports EBUSY on frontend\node_modules\.bin\vitest.ps1, close SP Cambo dev/test terminals and any Vitest watcher first.'
    Write-Host ($nodeProcesses | Select-Object Id, ProcessName, Path | Format-Table -AutoSize | Out-String)
}

$frontend = Join-Path $project 'frontend'
$gateway = Join-Path $project 'gateway'

if ($CleanFrontend) {
    $frontendModules = Join-Path $frontend 'node_modules'

    if (Test-Path -LiteralPath $frontendModules) {
        Write-Host 'Cleaning frontend node_modules...'
        try {
            Remove-Item -LiteralPath $frontendModules -Recurse -Force
        } catch {
            Write-Host ''
            Write-Host '[EBUSY] Windows is still locking a frontend dependency file.' -ForegroundColor Red
            Write-Host 'Close npm/pnpm dev servers, Vitest/watch terminals, and VS Code test runners using this project.'
            Write-Host 'Then rerun:'
            Write-Host '  powershell -ExecutionPolicy Bypass -File .\PREPARE_NODE_DEPS_R5.ps1 -CleanFrontend'
            throw
        }
    }
}

Write-Host ''
Write-Host 'Installing frontend dependencies with the production package manager...'
Push-Location $frontend
try {
    pnpm install --frozen-lockfile
} finally {
    Pop-Location
}

Write-Host ''
Write-Host 'Installing gateway dependencies...'
Push-Location $gateway
try {
    pnpm install --frozen-lockfile
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] Node dependencies prepared.'
