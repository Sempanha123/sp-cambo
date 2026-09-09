param(
    [string]$RepoRoot = ".",
    [switch]$RunChecks
)

$ErrorActionPreference = "Stop"

function Fail([string]$Message) {
    throw "[SP Cambo diagnostic syntax repair] $Message"
}

$root = (Resolve-Path $RepoRoot).Path
$toolPath = Join-Path $root "gateway/src/tool-integrity.ts"

if (-not (Test-Path $toolPath)) {
    Fail "Cannot find gateway/src/tool-integrity.ts"
}

$raw = [System.IO.File]::ReadAllText($toolPath)
$newline = if ($raw.Contains("`r`n")) { "`r`n" } else { "`n" }
$text = $raw.Replace("`r`n", "`n")

# Fix the malformed diagnostic line created by the previous PowerShell script.
$brokenPattern = '(?s)throw new InvalidToolInputError\(\s*\$\{originalError\.message\}\s*,?\s*\);'

if ([regex]::IsMatch($text, $brokenPattern)) {
    $text = [regex]::Replace(
        $text,
        $brokenPattern,
@'
throw new InvalidToolInputError(
          originalError.message + " " + summarizeInvalidToolInputShape(state.raw),
        );
'@,
        1
    )
} elseif ($text -match 'originalError\.message \+ " " \+ summarizeInvalidToolInputShape\(state\.raw\)') {
    Write-Host "Syntax repair already appears to be applied."
} else {
    # Fallback for the exact broken shape shown by esbuild.
    $old = @'
        throw new InvalidToolInputError(
          ${originalError.message} ,
        );
'@
    $new = @'
        throw new InvalidToolInputError(
          originalError.message + " " + summarizeInvalidToolInputShape(state.raw),
        );
'@
    if (-not $text.Contains($old)) {
        Fail "Could not find the malformed diagnostic block. No file written."
    }
    $text = $text.Replace($old, $new)
}

if (-not $text.Contains('originalError.message + " " + summarizeInvalidToolInputShape(state.raw)')) {
    Fail "Post-repair validation failed. No file written."
}

if ($newline -eq "`r`n") {
    $text = $text.Replace("`n", "`r`n")
}

[System.IO.File]::WriteAllText(
    $toolPath,
    $text,
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Host "Fixed TypeScript diagnostic syntax:"
Write-Host "  gateway/src/tool-integrity.ts"
Write-Host ""

Push-Location $root
try {
    git diff --check -- gateway/src/tool-integrity.ts gateway/src/app.ts
    if ($LASTEXITCODE -ne 0) {
        Fail "git diff --check failed."
    }
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
    Write-Host "Next:"
    Write-Host "  cd gateway"
    Write-Host "  pnpm test"
    Write-Host "  pnpm typecheck"
    Write-Host "  pnpm build"
}
