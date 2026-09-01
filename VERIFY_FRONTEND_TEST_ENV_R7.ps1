param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

function Run-Step {
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

Push-Location (Join-Path $project 'frontend')
try {
    Run-Step 'Single Nuxt component smoke test' {
        pnpm exec vitest run --project nuxt tests/component/ClaudeCodePage.spec.ts --reporter=verbose --bail=1 --maxWorkers=1
    }

    Run-Step 'Reseller allocation component test' {
        pnpm exec vitest run --project nuxt tests/component/ResellerAllocation.spec.ts --reporter=verbose --bail=1 --maxWorkers=1
    }

    Run-Step 'All Nuxt component tests' {
        pnpm exec vitest run --project nuxt
    }

    Run-Step 'All unit tests' {
        pnpm exec vitest run --project unit
    }

    Run-Step 'Complete frontend test suite' {
        pnpm run test
    }
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] SP Cambo frontend R7 test environment verification completed.'
