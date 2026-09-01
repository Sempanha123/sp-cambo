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
    Run-Step 'Frontend lint' { pnpm run lint }
    Run-Step 'Frontend typecheck' { pnpm run typecheck }
    Run-Step 'Frontend tests' { pnpm run test }
    Run-Step 'Frontend production build' { pnpm run build }
} finally {
    Pop-Location
}

Push-Location (Join-Path $project 'backend')
try {
    Run-Step 'Telegram audit regression' {
        php artisan test tests/Feature/Feature/Api/V1/TelegramDeliveryAuditTest.php
    }

    Run-Step 'Route pool regression' {
        php artisan test --filter=AdminModelRoutePoolTest
    }
} finally {
    Pop-Location
}

Push-Location $project
try {
    Run-Step 'Git diff check' { git diff --check }

    Write-Host ''
    Write-Host '=== Git status ==='
    git status --short
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] SP Cambo CI Fix R6.2 verification completed.'
