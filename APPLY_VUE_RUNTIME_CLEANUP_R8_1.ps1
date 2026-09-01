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

function Read-Utf8Lines {
    param([string]$Path)
    return [System.IO.File]::ReadAllLines($Path)
}

function Write-Utf8Lines {
    param(
        [string]$Path,
        [string[]]$Lines
    )

    [System.IO.File]::WriteAllLines(
        $Path,
        $Lines,
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
Write-Host '=== SP Cambo Vue Runtime Cleanup R8.1 ==='
Write-Host 'R8.1 resumes safely after the R8 partial apply.'
Write-Host ''

# -------------------------------------------------------------------------
# 1) Confirm frontend package-manager ownership.
# R8 already added this on the user's current tree, but R8.1 can repair a clean
# tree too without reformatting package.json unnecessarily.
# -------------------------------------------------------------------------
$packageJson = [System.IO.File]::ReadAllText($frontendPackage)

if ($packageJson -match '"packageManager"\s*:\s*"pnpm@11\.22\.0"') {
    Write-Host '[SKIP] frontend packageManager already pnpm@11.22.0'
} elseif ($packageJson -match '"type"\s*:\s*"module"\s*,') {
    $packageJson = [regex]::Replace(
        $packageJson,
        '("type"\s*:\s*"module"\s*,)',
        '$1' + [Environment]::NewLine + '  "packageManager": "pnpm@11.22.0",',
        1
    )
    [System.IO.File]::WriteAllText(
        $frontendPackage,
        $packageJson,
        (New-Object System.Text.UTF8Encoding($false))
    )
    Write-Host '[OK] Added frontend packageManager=pnpm@11.22.0'
} else {
    throw 'Could not safely add packageManager to frontend/package.json.'
}

# -------------------------------------------------------------------------
# 2) Update only the [6/7] frontend section in START_ALL.ps1.
# This is line-based so CRLF, spacing, or small neighboring changes do not make
# the script fail like R8 did.
# -------------------------------------------------------------------------
$lines = [System.Collections.Generic.List[string]]::new()
foreach ($line in (Read-Utf8Lines $startAll)) {
    [void]$lines.Add($line)
}

$sectionStart = -1
$sectionEnd = -1

for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($sectionStart -lt 0 -and $lines[$i] -match "\[6/7\]\s+Nuxt frontend") {
        $sectionStart = $i
        continue
    }

    if ($sectionStart -ge 0 -and $lines[$i] -match "\[7/7\]\s+Laravel scheduler") {
        $sectionEnd = $i
        break
    }
}

if ($sectionStart -lt 0 -or $sectionEnd -lt 0 -or $sectionEnd -le $sectionStart) {
    throw 'Could not locate the [6/7] Nuxt frontend section in START_ALL.ps1.'
}

$alreadyPnpm = $false
for ($i = $sectionStart; $i -lt $sectionEnd; $i++) {
    if ($lines[$i] -match 'pnpm@11\.22\.0\s+dev') {
        $alreadyPnpm = $true
        break
    }
}

if ($alreadyPnpm) {
    Write-Host '[SKIP] START_ALL frontend section already uses pnpm.'
} else {
    $installStart = -1
    $devLine = -1

    for ($i = $sectionStart; $i -lt $sectionEnd; $i++) {
        $trimmed = $lines[$i].Trim()

        if ($installStart -lt 0
            -and $trimmed.StartsWith('if (-not (Test-Path')
            -and $trimmed.Contains('node_modules')) {
            $installStart = $i
            continue
        }

        if ($trimmed -match '^npm\s+run\s+dev\b') {
            $devLine = $i
            break
        }
    }

    if ($installStart -lt 0) {
        throw 'Could not locate the frontend node_modules bootstrap block inside START_ALL.ps1.'
    }

    if ($devLine -lt 0 -or $devLine -le $installStart) {
        throw 'Could not locate the frontend npm run dev line inside START_ALL.ps1.'
    }

    $replacement = [string[]]@(
        "if (-not (Test-Path -LiteralPath '.\node_modules')) {",
        '    npx pnpm@11.22.0 install --frozen-lockfile',
        "    if (`$LASTEXITCODE -ne 0) { throw 'Frontend pnpm install failed.' }",
        '}',
        'npx pnpm@11.22.0 dev -- --host 127.0.0.1 --port 3000'
    )

    $removeCount = $devLine - $installStart + 1
    $lines.RemoveRange($installStart, $removeCount)
    $lines.InsertRange($installStart, $replacement)

    Write-Utf8Lines $startAll $lines.ToArray()
    Write-Host '[OK] START_ALL frontend changed from npm to pnpm.'
}

# -------------------------------------------------------------------------
# 3) Remove the duplicate package roots / mixed lockfile.
# Root package.json currently exists only for @pinia/nuxt, which is already a
# frontend dependency. CI and Docker build the frontend from frontend/ via pnpm.
# -------------------------------------------------------------------------
$rootNodeModules = Join-Path $project 'node_modules'
$rootPackage = Join-Path $project 'package.json'
$rootPackageLock = Join-Path $project 'package-lock.json'
$frontendPackageLock = Join-Path $frontend 'package-lock.json'

foreach ($path in @($rootPackage, $rootPackageLock, $frontendPackageLock)) {
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Force
        Write-Host "[OK] Removed $path"
    } else {
        Write-Host "[SKIP] Already absent: $path"
    }
}

# -------------------------------------------------------------------------
# 4) Clear all installed/generated frontend state so Windows cannot reuse the
# parent Vue runtime that caused renderSlot() to see a null render context.
# -------------------------------------------------------------------------
$runningNode = @(Get-Process node -ErrorAction SilentlyContinue)
if ($runningNode.Count -gt 0) {
    Write-Host ''
    Write-Warning 'Node processes are running.'
    Write-Warning 'If cleanup reports EBUSY, close SP Cambo dev/Vitest terminals and VS Code test runners, then rerun R8.1.'
    $runningNode | Select-Object Id, ProcessName, Path | Format-Table -AutoSize
}

foreach ($path in @(
    $rootNodeModules,
    (Join-Path $frontend 'node_modules'),
    (Join-Path $frontend '.nuxt')
)) {
    if (Test-Path -LiteralPath $path) {
        try {
            Remove-Item -LiteralPath $path -Recurse -Force
            Write-Host "[OK] Removed $path"
        } catch {
            Write-Host ''
            Write-Host '[EBUSY] Windows still has a dependency file open.' -ForegroundColor Red
            Write-Host 'Close SP Cambo frontend/Vitest/VS Code test processes and rerun this same R8.1 script.'
            throw
        }
    }
}

# -------------------------------------------------------------------------
# 5) Install one canonical pnpm dependency tree.
# -------------------------------------------------------------------------
Push-Location $frontend
try {
    Write-Host ''
    Write-Host 'Installing frontend dependencies from pnpm-lock.yaml...'
    pnpm install --frozen-lockfile
    if ($LASTEXITCODE -ne 0) {
        throw "pnpm install failed with exit code $LASTEXITCODE"
    }

    Write-Host ''
    Write-Host 'Regenerating Nuxt metadata...'
    pnpm exec nuxt prepare
    if ($LASTEXITCODE -ne 0) {
        throw "nuxt prepare failed with exit code $LASTEXITCODE"
    }

    Write-Host ''
    Write-Host 'Vue resolves from:'
    node -e "console.log(require.resolve('vue/package.json'))"
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not resolve frontend Vue package.'
    }

    Write-Host ''
    Write-Host 'Vue dependency tree:'
    pnpm list vue --depth 20
} finally {
    Pop-Location
}

if (Test-Path -LiteralPath $rootNodeModules) {
    throw 'Root node_modules still exists after cleanup.'
}

foreach ($forbidden in @($rootPackage, $rootPackageLock, $frontendPackageLock)) {
    if (Test-Path -LiteralPath $forbidden) {
        throw "Mixed package-manager artifact still exists: $forbidden"
    }
}

$finalStart = [System.IO.File]::ReadAllText($startAll)
if ($finalStart -notmatch 'pnpm@11\.22\.0\s+dev\s+--\s+--host\s+127\.0\.0\.1\s+--port\s+3000') {
    throw 'START_ALL frontend pnpm dev verification failed.'
}

Write-Host ''
Write-Host '[PASS] R8.1 removed the duplicate parent Vue dependency tree.'
Write-Host 'Next run:'
Write-Host '  powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_RUNTIME_CLEANUP_R8_1.ps1'
