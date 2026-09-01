param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'

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
    Run-Checked 'Vue resolution' {
        node -e "const p=require.resolve('vue/package.json'); console.log(p); const q=p.replace(/\//g,'\\').toLowerCase(); if(!q.includes('\\frontend\\node_modules\\')) process.exit(2)"
    }

    Run-Checked 'Vue Router resolution' {
        node -e "console.log(require.resolve('vue-router/package.json'))"
    }

    Run-Checked 'Claude Code Nuxt smoke test' {
        pnpm exec vitest run --project nuxt tests/component/ClaudeCodePage.spec.ts --reporter=verbose --bail=1 --maxWorkers=1
    }

    Run-Checked 'Reseller allocation Nuxt test' {
        pnpm exec vitest run --project nuxt tests/component/ResellerAllocation.spec.ts --reporter=verbose --bail=1 --maxWorkers=1
    }

    Run-Checked 'All Nuxt component tests' {
        pnpm exec vitest run --project nuxt
    }

    Run-Checked 'All frontend unit tests' {
        pnpm exec vitest run --project unit
    }

    Run-Checked 'Frontend lint' {
        pnpm run lint
    }

    Run-Checked 'Frontend typecheck' {
        pnpm run typecheck
    }

    Run-Checked 'Frontend production build' {
        pnpm run build
    }
}
finally {
    Pop-Location
}

Write-Host ''
Write-Host '[PASS] SP Cambo Vue Direct Dependencies R8.3 verification completed.'
