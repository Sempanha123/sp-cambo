param(
    [string]$RepoRoot = ".",
    [switch]$RunChecks
)

$ErrorActionPreference = "Stop"

function Fail([string]$Message) {
    throw "[SP Cambo diagnostic cleanup] $Message"
}

$root = (Resolve-Path $RepoRoot).Path
$appPath = Join-Path $root "gateway/src/app.ts"
$toolPath = Join-Path $root "gateway/src/tool-integrity.ts"

if (-not (Test-Path $appPath)) { Fail "Missing gateway/src/app.ts" }
if (-not (Test-Path $toolPath)) { Fail "Missing gateway/src/tool-integrity.ts" }

$appRaw = [System.IO.File]::ReadAllText($appPath)
$toolRaw = [System.IO.File]::ReadAllText($toolPath)

$appNl = if ($appRaw.Contains("`r`n")) { "`r`n" } else { "`n" }
$toolNl = if ($toolRaw.Contains("`r`n")) { "`r`n" } else { "`n" }

$app = $appRaw.Replace("`r`n", "`n")
$tool = $toolRaw.Replace("`r`n", "`n")

# 1) Remove temporary console diagnostic from app.ts.
$diagBlock = @'
      if (error instanceof InvalidToolInputError) {
        console.warn(
          `[SP Cambo tool diagnostic] upstream_invalid_tool_input request=${requestId} reservation=${reservationId}: ${error.message}`,
        );
      }

'@

if ($app.Contains($diagBlock)) {
    $app = $app.Replace($diagBlock, "")
    Write-Host "Removed temporary app.ts console diagnostic."
} else {
    Write-Host "Temporary app.ts console diagnostic not found; nothing to remove."
}

# 2) Restore validateState to throw the original InvalidToolInputError.
$diagThrow = @'
      if (originalError instanceof InvalidToolInputError) {
        throw new InvalidToolInputError(
          originalError.message + " " + summarizeInvalidToolInputShape(state.raw),
        );
      }

      throw originalError;
'@

$cleanThrow = @'
      throw originalError;
'@

if ($tool.Contains($diagThrow)) {
    $tool = $tool.Replace($diagThrow, $cleanThrow)
    Write-Host "Removed temporary structural error annotation."
} else {
    Write-Host "Temporary structural error annotation not found; nothing to remove."
}

# 3) Remove temporary summarizeInvalidToolInputShape helper.
$helperStart = $tool.IndexOf("function summarizeInvalidToolInputShape(raw: string): string {")
if ($helperStart -ge 0) {
    $helperEnd = $tool.IndexOf("function parseObject(raw: string): Record<string, unknown> {", $helperStart)
    if ($helperEnd -lt 0) {
        Fail "Found diagnostic helper but could not locate parseObject() boundary. No file written."
    }

    $tool = $tool.Substring(0, $helperStart) + $tool.Substring($helperEnd)
    Write-Host "Removed temporary summarizeInvalidToolInputShape helper."
} else {
    Write-Host "Temporary structural helper not found; nothing to remove."
}

# Safety: keep actual Edit continuation repair.
$requiredFixMarkers = @(
    "SAFE_EDIT_TRAILING_CONTINUATION_FIELDS",
    "toolName: string | null;",
    "repairDuplicatedTrailingFields(state.raw, state.toolName)",
    "parseObjectCompat(raw, toolName)"
)

foreach ($marker in $requiredFixMarkers) {
    if (-not $tool.Contains($marker)) {
        Fail "Actual Edit compatibility fix marker is missing: $marker. Refusing to write."
    }
}

# Ensure diagnostic markers are gone.
if ($app.Contains("[SP Cambo tool diagnostic]")) {
    Fail "Diagnostic console marker is still present in app.ts. Refusing to write."
}

if ($tool.Contains("summarizeInvalidToolInputShape")) {
    Fail "Diagnostic helper marker is still present in tool-integrity.ts. Refusing to write."
}

# Backups under .git.
$gitDir = Join-Path $root ".git"
if (Test-Path $gitDir) {
    $backupDir = Join-Path $gitDir "sp-cambo-fix-backups"
    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"

    [System.IO.File]::WriteAllText(
        (Join-Path $backupDir "app.ts.before-diagnostic-cleanup.$stamp"),
        $appRaw,
        (New-Object System.Text.UTF8Encoding($false))
    )
    [System.IO.File]::WriteAllText(
        (Join-Path $backupDir "tool-integrity.ts.before-diagnostic-cleanup.$stamp"),
        $toolRaw,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

if ($appNl -eq "`r`n") { $app = $app.Replace("`n", "`r`n") }
if ($toolNl -eq "`r`n") { $tool = $tool.Replace("`n", "`r`n") }

[System.IO.File]::WriteAllText(
    $appPath,
    $app,
    (New-Object System.Text.UTF8Encoding($false))
)

[System.IO.File]::WriteAllText(
    $toolPath,
    $tool,
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Host ""
Write-Host "Temporary diagnostics removed."
Write-Host "Actual Edit continuation fix was preserved."
Write-Host ""

Push-Location $root
try {
    git diff --check -- gateway/src/app.ts gateway/src/tool-integrity.ts gateway/tests/tool-integrity-edit-continuation.test.ts
    if ($LASTEXITCODE -ne 0) { Fail "git diff --check failed." }

    git status --short -- gateway/src/app.ts gateway/src/tool-integrity.ts gateway/tests/tool-integrity-edit-continuation.test.ts
}
finally {
    Pop-Location
}

if ($RunChecks) {
    Push-Location (Join-Path $root "gateway")
    try {
        pnpm test
        if ($LASTEXITCODE -ne 0) { Fail "pnpm test failed." }

        pnpm typecheck
        if ($LASTEXITCODE -ne 0) { Fail "pnpm typecheck failed." }

        pnpm build
        if ($LASTEXITCODE -ne 0) { Fail "pnpm build failed." }
    }
    finally {
        Pop-Location
    }

    Write-Host ""
    Write-Host "ALL CHECKS PASSED."
} else {
    Write-Host ""
    Write-Host "Next:"
    Write-Host "  cd gateway"
    Write-Host "  pnpm test"
    Write-Host "  pnpm typecheck"
    Write-Host "  pnpm build"
}
