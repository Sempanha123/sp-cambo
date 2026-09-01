param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path
$frontend = Join-Path $project 'frontend'

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

foreach ($forbidden in @(
    (Join-Path $project 'node_modules'),
    (Join-Path $project 'package.json'),
    (Join-Path $project 'package-lock.json'),
    (Join-Path $frontend 'package-lock.json')
)) {
    if (Test-Path -LiteralPath $forbidden) {
        throw "Mixed/parent package artifact still exists: $forbidden"
    }
}

Push-Location $frontend
try {
    Run-Step 'Vue resolution' {
        node -e "const p=require.resolve('vue/package.json'); const r=require.resolve('@vue/runtime-core/package.json'); console.log('vue=',p); console.log('runtime-core=',r); if(p.includes('SP Cambo\\node_modules') || r.includes('SP Cambo\\node_modules')) process.exit(2)"
    }

    Run-Step 'Claude Code Nuxt smoke test' {
        pnpm exec vitest run --project nuxt tests/component/ClaudeCodePage.spec.ts --reporter=verbose --bail=1 --maxWorkers=1
    }

    Run-Step 'Reseller allocation Nuxt test' {
        pnpm exec vitest run --project nuxt tests/component/ResellerAllocation.spec.ts --reporter=verbose --bail=1 --maxWorkers=1
    }

    Run-Step 'All Nuxt component tests' {
        pnpm exec vitest run --project nuxt
    }

    Run-Step 'All frontend unit tests' {
        pnpm exec vitest run --project unit
    }

    Run-Step 'Frontend typecheck' {
        pnpm run typecheck
    }

    Run-Step 'Frontend production build' {
        pnpm run build
    }
} finally {
    Pop-Location
}

Push-Location $project
try {
    Run-Step 'Git whitespace check' {
        git diff --check
    }

    Write-Host ''
    Write-Host '=== Git status ==='
    git status --short
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] SP Cambo Vue Runtime Cleanup R8 verification completed.'
