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

function Run-Checked {
    param(
        [string]$Title,
        [scriptblock]$Command
    )

    Write-Host ''
    Write-Host "=== $Title ==="

    & $Command

    if ($LASTEXITCODE -ne 0) {
        throw "$Title failed with exit code $LASTEXITCODE"
    }
}

$project = (Resolve-Path -LiteralPath $ProjectRoot).Path
$frontend = Join-Path $project 'frontend'
$frontendPackage = Join-Path $frontend 'package.json'
$frontendLock = Join-Path $frontend 'pnpm-lock.yaml'

Require-File $frontendPackage
Require-File $frontendLock

Write-Host ''
Write-Host '=== SP Cambo Vue Direct Dependencies R8.3 ==='
Write-Host ''

# R8.2 already performed the destructive cleanup before it stopped at
# require.resolve('vue/package.json'). Verify that the mixed package roots did
# not come back.
foreach ($forbidden in @(
    (Join-Path $project 'node_modules'),
    (Join-Path $project 'package.json'),
    (Join-Path $project 'package-lock.json'),
    (Join-Path $frontend 'package-lock.json')
)) {
    if (Test-Path -LiteralPath $forbidden) {
        throw "Mixed package-manager artifact still exists: $forbidden"
    }
}

Push-Location $frontend

try {
    # Nuxt's minimal package.json declares both Vue and Vue Router directly.
    # Making them direct frontend dependencies also gives pnpm one canonical
    # project-level Vue runtime for tests and direct imports such as nextTick.
    Run-Checked 'Add canonical Vue dependencies' {
        pnpm add --save-exact vue@3.5.42 vue-router@5.3.0
    }

    Run-Checked 'Regenerate Nuxt metadata' {
        pnpm exec nuxt prepare
    }

    Run-Checked 'Resolve Vue' {
        node -e "console.log(require.resolve('vue/package.json'))"
    }

    Run-Checked 'Resolve Vue Router' {
        node -e "console.log(require.resolve('vue-router/package.json'))"
    }

    Run-Checked 'Inspect Vue dependency ownership' {
        pnpm why vue
    }
}
finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] R8.3 added direct canonical Vue dependencies.'
Write-Host 'Next run:'
Write-Host '  powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_DIRECT_DEPS_R8_3.ps1'
