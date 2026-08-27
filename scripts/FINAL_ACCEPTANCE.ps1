[CmdletBinding()]
param(
    [switch]$SkipInstall,
    [switch]$SkipDocker,
    [switch]$FixLint,
    [switch]$SkipLive,
    [switch]$ContinueOnFailure
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
$Failures = [System.Collections.Generic.List[string]]::new()

function Section([string]$Name) { Write-Host "`n=== $Name ===" -ForegroundColor Cyan }
function Run-Step([string]$Name, [scriptblock]$Action) {
    Write-Host "--> $Name" -ForegroundColor Yellow
    try {
        $global:LASTEXITCODE = $null
        & $Action
        if ($null -ne $LASTEXITCODE -and $LASTEXITCODE -ne 0) { throw "exit code $LASTEXITCODE" }
        Write-Host "PASS: $Name" -ForegroundColor Green
    }
    catch {
        $Failures.Add("$Name`: $($_.Exception.Message)")
        Write-Host "FAIL: $Name - $($_.Exception.Message)" -ForegroundColor Red
        if (-not $ContinueOnFailure) { throw }
    }
}
function Need([string]$Command) {
    if (-not (Get-Command $Command -ErrorAction SilentlyContinue)) { throw "Required command '$Command' was not found in PATH." }
}
function Stop-FrontendDevProcesses([string]$FrontendPath) {
    if ($env:OS -ne 'Windows_NT') { return }

    $normalized = [IO.Path]::GetFullPath($FrontendPath).TrimEnd('\')
    $matches = @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $command = [string]$_.CommandLine
        $command -and $command.IndexOf($normalized, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and
            ($command -match '(?i)nuxt(.mjs|\\bin\\nuxt)?\s+dev|nuxi(.mjs)?\s+dev')
    })

    foreach ($process in $matches) {
        Write-Host "Stopping Nuxt dev process PID $($process.ProcessId) before clean npm install." -ForegroundColor Yellow
        Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
    }

    if ($matches.Count -gt 0) { Start-Sleep -Milliseconds 1500 }
}
$script:UseCorepackPnpm = $false
function Invoke-Pnpm([string[]]$Arguments) {
    if ($script:UseCorepackPnpm) { & corepack pnpm @Arguments }
    else { & pnpm @Arguments }
}
function Invoke-LaravelTests {
    & php artisan test
    $code = $LASTEXITCODE
    if ($code -eq 0) { return }

    # Windows PHP has previously terminated with 0xC0000005 while running the
    # entire suite in one process. Only that native-runtime crash gets the
    # isolated-file fallback. Normal assertion/test failures remain failures.
    $nativeCrashCodes = @(-1073741819, 139, 3221225477)
    if ($nativeCrashCodes -notcontains $code) {
        throw "Laravel suite failed with exit code $code"
    }

    Write-Host "Full Laravel process terminated with native crash code $code; retrying each Test.php in a fresh PHP process." -ForegroundColor Yellow
    $testFiles = Get-ChildItem tests -Recurse -File -Filter '*Test.php' | Sort-Object FullName
    if ($testFiles.Count -eq 0) { throw 'No Laravel test files were found.' }

    foreach ($test in $testFiles) {
        $relative = [IO.Path]::GetRelativePath((Get-Location).Path, $test.FullName)
        Write-Host "  php artisan test $relative"
        & php artisan test $relative
        if ($LASTEXITCODE -ne 0) {
            throw "Isolated Laravel test failed/crashed: $relative (exit code $LASTEXITCODE)"
        }
    }
    $global:LASTEXITCODE = 0
    Write-Host "Isolated Laravel fallback passed $($testFiles.Count) test files." -ForegroundColor Green
}

Set-Location $Root
Section 'Toolchain'
Run-Step 'Required tools' {
    Need php; Need node; Need npm; Need composer
    if (Get-Command pnpm -ErrorAction SilentlyContinue) {
        $script:UseCorepackPnpm = $false
    } elseif (Get-Command corepack -ErrorAction SilentlyContinue) {
        $script:UseCorepackPnpm = $true
    } else {
        throw "Required package manager 'pnpm' was not found. Install pnpm or provide Corepack."
    }
    Invoke-Pnpm @('--version')
}
$DockerAvailable = [bool](Get-Command docker -ErrorAction SilentlyContinue)
if (-not $SkipDocker -and -not $DockerAvailable) {
    Write-Host 'INFO: Docker is not installed/in PATH. Docker-only acceptance gates will be skipped; native Windows services are supported.' -ForegroundColor Yellow
    $SkipDocker = $true
}
Run-Step 'Supported Node version' {
    $raw = (node --version).TrimStart('v')
    $parts = $raw.Split('.')
    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $supported = (($major -eq 24) -and ($minor -ge 15)) -or ($major -ge 26)
    if (-not $supported) {
        throw "Unsupported Node $(node --version). Use Node 24.15+ or Node 26+."
    }
    Write-Host "Using supported Node $(node --version)."
}

Section 'Backend / Laravel'
Push-Location (Join-Path $Root 'backend')
try {
    if (-not $SkipInstall) { Run-Step 'Composer install' { composer install --no-interaction --prefer-dist --no-progress } }
    Run-Step 'Composer metadata' { composer validate --no-check-publish }
    Run-Step 'PHP syntax' {
        $files = Get-ChildItem app,bootstrap,config,database,routes,tests -Recurse -File -Filter '*.php'
        foreach ($file in $files) {
            & php -l $file.FullName | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "Syntax error: $($file.FullName)" }
        }
        Write-Host "Validated $($files.Count) PHP files."
        $global:LASTEXITCODE = 0
    }
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Host 'Created backend/.env from example for local validation. Configure real secrets only on your machine.' -ForegroundColor Yellow
    }
    Run-Step 'Laravel test suite' { Invoke-LaravelTests }
} finally { Pop-Location }

Section 'Frontend / Nuxt'
Push-Location (Join-Path $Root 'frontend')
try {
    if (-not (Test-Path 'package-lock.json')) { throw 'frontend/package-lock.json is required for reproducible npm installs.' }
    if (-not $SkipInstall) {
        Stop-FrontendDevProcesses (Get-Location).Path
        Run-Step 'Frontend frozen install' { npm ci --ignore-scripts --no-audit --no-fund }
    }
    Run-Step 'Frontend dependency sanity' { node -e "require.resolve('jiti'); console.log('jiti resolution OK')" }
    Run-Step 'Nuxt prepare' { npm run postinstall }
    if ($FixLint) { Run-Step 'Frontend lint autofix' { npm run lint -- --fix } }
    Run-Step 'Frontend lint' { npm run lint }
    Run-Step 'Frontend typecheck' { npm run typecheck }
    Run-Step 'Frontend tests' { npm run test }
    Run-Step 'Frontend production build' { npm run build }
} finally { Pop-Location }

Section 'Gateway'
Push-Location (Join-Path $Root 'gateway')
try {
    if (-not $SkipInstall) { Run-Step 'Gateway frozen install' { Invoke-Pnpm @('install', '--frozen-lockfile') } }
    Run-Step 'Gateway typecheck' { Invoke-Pnpm @('run', 'typecheck') }
    Run-Step 'Gateway tests' { Invoke-Pnpm @('run', 'test') }
    Run-Step 'Gateway production build' { Invoke-Pnpm @('run', 'build') }
} finally { Pop-Location }

if (-not $SkipDocker) {
    Section 'Docker (optional)'
    Run-Step 'Compose config' { docker compose -f infra/compose.yaml config --quiet }
    Run-Step 'Compose build' { docker compose -f infra/compose.yaml build }
}

if (-not $SkipLive) {
    Section 'Live-service acceptance still required'
    Write-Host 'Passing this script creates a RELEASE CANDIDATE only. Production promotion still requires:' -ForegroundColor Yellow
    Write-Host '  1. Real provider probe/model route through the configured public alias.'
    Write-Host '  2. Low-value Bakong KHQR payment -> server verification -> exactly-once entitlement/key.'
    Write-Host '  3. Telegram Store purchase -> payment -> same credential delivered exactly once.'
    Write-Host '  4. Issued key -> streaming inference -> settled usage/charge -> Key Checker.'
    Write-Host '  5. Playground free quota -> exhaustion -> redeem/purchased customer flow as designed.'
}

Section 'Result'
if ($Failures.Count -gt 0) {
    Write-Host "Acceptance FAILED ($($Failures.Count) step(s)):" -ForegroundColor Red
    $Failures | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
    exit 1
}
Write-Host 'All executable acceptance gates requested in this run passed. Status: RELEASE CANDIDATE, not Production Ready until live acceptance passes.' -ForegroundColor Green
exit 0
