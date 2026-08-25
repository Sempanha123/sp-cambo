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
        $global:LASTEXITCODE = 0
        & $Action
        if ($LASTEXITCODE -ne 0) { throw "exit code $LASTEXITCODE" }
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

Set-Location $Root
Section 'Toolchain'
Run-Step 'Required tools' { Need php; Need node; Need npm; Need pnpm; Need composer }
$DockerAvailable = [bool](Get-Command docker -ErrorAction SilentlyContinue)
if (-not $SkipDocker -and -not $DockerAvailable) {
    Write-Host 'INFO: Docker is not installed/in PATH. Docker-only acceptance gates will be skipped; local Laravel/Nuxt/Gateway validation can still run.' -ForegroundColor Yellow
    $SkipDocker = $true
}
Run-Step 'Supported Node version' {
    $raw = (node --version).TrimStart('v')
    $parts = $raw.Split('.')
    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $supported = (($major -eq 24) -and ($minor -ge 15)) -or ($major -ge 26)
    if (-not $supported) {
        throw "Unsupported Node $(node --version). Use Node 24.15+ or Node 26+. Node 22 is too old for the gateway, and Node 25 is outside Nuxt's supported engine range."
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
        foreach ($file in $files) { php -l $file.FullName | Out-Null; if ($LASTEXITCODE -ne 0) { throw "Syntax error: $($file.FullName)" } }
        Write-Host "Validated $($files.Count) PHP files."
    }
    if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env' }
    Run-Step 'Laravel test suite' { php artisan test }
} finally { Pop-Location }

Section 'Frontend / Nuxt'
Push-Location (Join-Path $Root 'frontend')
try {
    if (-not $SkipInstall) { Run-Step 'Frontend install' { npm install --ignore-scripts --no-audit --no-fund } }
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
    if (-not $SkipInstall) { Run-Step 'Gateway frozen install' { pnpm install --frozen-lockfile } }
    Run-Step 'Gateway typecheck' { pnpm run typecheck }
    Run-Step 'Gateway tests' { pnpm run test }
    Run-Step 'Gateway production build' { pnpm run build }
} finally { Pop-Location }

if (-not $SkipDocker) {
    Section 'Docker'
    Run-Step 'Compose config' { docker compose -f infra/compose.yaml config --quiet }
    Run-Step 'Compose build' { docker compose -f infra/compose.yaml build }
}

if (-not $SkipLive) {
    Section 'Live-service acceptance'
    Write-Host 'Automated local gates are complete. Live acceptance still requires real credentials/services:' -ForegroundColor Yellow
    Write-Host '  1. Bakong KHQR: pay -> reconcile/verify -> entitlement -> delivery'
    Write-Host '  2. Telegram: private-chat webhook -> purchase -> payment -> key delivery'
    Write-Host '  3. OmniRoute: admin-selected provider/private-model route through SP Cambo'
    Write-Host '  4. Claude Code and OpenAI/Codex-compatible base URL smoke tests'
}

Section 'Result'
if ($Failures.Count -gt 0) {
    Write-Host "Acceptance FAILED ($($Failures.Count) step(s)):" -ForegroundColor Red
    $Failures | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
    exit 1
}
Write-Host 'All executable acceptance gates requested in this run passed.' -ForegroundColor Green
exit 0
