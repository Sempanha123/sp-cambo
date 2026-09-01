param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'

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
    param(
        [string]$Path,
        [string]$Content
    )

    [System.IO.File]::WriteAllText(
        $Path,
        $Content,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

function Write-Lines {
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

$project = (Resolve-Path -LiteralPath $ProjectRoot).Path
$frontend = Join-Path $project 'frontend'

$frontendPackage = Join-Path $frontend 'package.json'
$frontendLock = Join-Path $frontend 'pnpm-lock.yaml'
$frontendWorkspace = Join-Path $frontend 'pnpm-workspace.yaml'
$startAll = Join-Path $project 'START_ALL.ps1'

foreach ($required in @(
    $frontendPackage,
    $frontendLock,
    $frontendWorkspace,
    $startAll
)) {
    Require-File $required
}

Write-Host ''
Write-Host '=== SP Cambo Vue Runtime Cleanup R8.2 ==='
Write-Host 'Safe continuation after the earlier R8/R8.1 parser failures.'
Write-Host ''

# ----------------------------------------------------------------------
# 1. Ensure frontend is explicitly pnpm-owned.
# ----------------------------------------------------------------------
$packageJson = Read-Text $frontendPackage

if ($packageJson -match '"packageManager"\s*:\s*"pnpm@11\.22\.0"') {
    Write-Host '[SKIP] frontend packageManager already pnpm@11.22.0'
}
else {
    $typePattern = '"type"\s*:\s*"module"\s*,'
    if ($packageJson -notmatch $typePattern) {
        throw 'Could not safely add packageManager to frontend/package.json.'
    }

    $replacement = '"type": "module",' + [Environment]::NewLine + '  "packageManager": "pnpm@11.22.0",'
    $packageJson = [regex]::Replace(
        $packageJson,
        $typePattern,
        $replacement,
        1
    )

    Write-Text $frontendPackage $packageJson
    Write-Host '[OK] Added frontend packageManager=pnpm@11.22.0'
}

# ----------------------------------------------------------------------
# 2. Patch only the START_ALL frontend section.
#    No multiline boolean expressions are used here.
# ----------------------------------------------------------------------
$lines = [System.IO.File]::ReadAllLines($startAll)

$sectionStart = -1
$sectionEnd = -1

for ($i = 0; $i -lt $lines.Length; $i++) {
    if ($sectionStart -eq -1) {
        if ($lines[$i] -match '\[6/7\]\s+Nuxt frontend') {
            $sectionStart = $i
        }
    }
    else {
        if ($lines[$i] -match '\[7/7\]\s+Laravel scheduler') {
            $sectionEnd = $i
            break
        }
    }
}

if ($sectionStart -eq -1) {
    throw 'Could not find the [6/7] Nuxt frontend section in START_ALL.ps1.'
}

if ($sectionEnd -eq -1) {
    throw 'Could not find the [7/7] Laravel scheduler section in START_ALL.ps1.'
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
}
else {
    $installStart = -1
    $devLine = -1

    for ($i = $sectionStart; $i -lt $sectionEnd; $i++) {
        $trimmed = $lines[$i].Trim()

        if ($installStart -eq -1) {
            if ($trimmed -like 'if (-not (Test-Path*node_modules*') {
                $installStart = $i
            }
        }

        if ($trimmed -match '^npm\s+run\s+dev\b') {
            $devLine = $i
            break
        }
    }

    if ($installStart -eq -1) {
        throw 'Could not locate the frontend node_modules bootstrap block in START_ALL.ps1.'
    }

    if ($devLine -eq -1) {
        throw 'Could not locate the frontend npm run dev line in START_ALL.ps1.'
    }

    if ($devLine -lt $installStart) {
        throw 'START_ALL frontend section order is unexpected.'
    }

    $replacementLines = @(
        "if (-not (Test-Path -LiteralPath '.\node_modules')) {",
        '    npx pnpm@11.22.0 install --frozen-lockfile',
        "    if (`$LASTEXITCODE -ne 0) { throw 'Frontend pnpm install failed.' }",
        '}',
        'npx pnpm@11.22.0 dev -- --host 127.0.0.1 --port 3000'
    )

    $newLines = @()

    if ($installStart -gt 0) {
        $newLines += $lines[0..($installStart - 1)]
    }

    $newLines += $replacementLines

    if (($devLine + 1) -lt $lines.Length) {
        $newLines += $lines[($devLine + 1)..($lines.Length - 1)]
    }

    Write-Lines $startAll $newLines
    Write-Host '[OK] START_ALL frontend changed from npm to pnpm.'
}

# ----------------------------------------------------------------------
# 3. Remove mixed/root Node package ownership.
# ----------------------------------------------------------------------
$rootPackage = Join-Path $project 'package.json'
$rootPackageLock = Join-Path $project 'package-lock.json'
$rootNodeModules = Join-Path $project 'node_modules'
$frontendPackageLock = Join-Path $frontend 'package-lock.json'

foreach ($file in @(
    $rootPackage,
    $rootPackageLock,
    $frontendPackageLock
)) {
    if (Test-Path -LiteralPath $file) {
        Remove-Item -LiteralPath $file -Force
        Write-Host "[OK] Removed $file"
    }
    else {
        Write-Host "[SKIP] Already absent: $file"
    }
}

# ----------------------------------------------------------------------
# 4. Remove stale installed/generated state.
# ----------------------------------------------------------------------
$nodeProcesses = @(Get-Process node -ErrorAction SilentlyContinue)

if ($nodeProcesses.Count -gt 0) {
    Write-Host ''
    Write-Warning 'Node processes are running.'
    Write-Warning 'If Windows reports EBUSY, close SP Cambo dev/Vitest/VS Code test processes and rerun R8.2.'
    $nodeProcesses | Select-Object Id, ProcessName, Path | Format-Table -AutoSize
}

foreach ($directory in @(
    $rootNodeModules,
    (Join-Path $frontend 'node_modules'),
    (Join-Path $frontend '.nuxt')
)) {
    if (Test-Path -LiteralPath $directory) {
        try {
            Remove-Item -LiteralPath $directory -Recurse -Force
            Write-Host "[OK] Removed $directory"
        }
        catch {
            Write-Host ''
            Write-Host '[EBUSY] Windows still has a dependency file open.' -ForegroundColor Red
            Write-Host 'Close SP Cambo frontend/Vitest/VS Code test processes, then rerun R8.2.'
            throw
        }
    }
}

# ----------------------------------------------------------------------
# 5. Install one frontend dependency tree and regenerate Nuxt.
# ----------------------------------------------------------------------
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
    Write-Host 'Resolved Vue package:'

    node -e "console.log(require.resolve('vue/package.json'))"

    if ($LASTEXITCODE -ne 0) {
        throw 'Could not resolve Vue from the frontend dependency tree.'
    }

    Write-Host ''
    Write-Host 'Installed Vue tree:'

    pnpm list vue @vue/runtime-core --depth 20
}
finally {
    Pop-Location
}

# ----------------------------------------------------------------------
# 6. Final source/state checks.
# ----------------------------------------------------------------------
foreach ($forbidden in @(
    $rootPackage,
    $rootPackageLock,
    $rootNodeModules,
    $frontendPackageLock
)) {
    if (Test-Path -LiteralPath $forbidden) {
        throw "Mixed package-manager artifact still exists: $forbidden"
    }
}

$finalStartAll = Read-Text $startAll

if ($finalStartAll -notmatch 'pnpm@11\.22\.0\s+dev\s+--\s+--host\s+127\.0\.0\.1\s+--port\s+3000') {
    throw 'START_ALL frontend pnpm dev verification failed.'
}

$finalPackage = Read-Text $frontendPackage

if ($finalPackage -notmatch '"packageManager"\s*:\s*"pnpm@11\.22\.0"') {
    throw 'frontend packageManager verification failed.'
}

Write-Host ''
Write-Host '[PASS] R8.2 removed the duplicate parent Vue dependency tree.'
Write-Host 'Next run:'
Write-Host '  powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_RUNTIME_CLEANUP_R8_2.ps1'
