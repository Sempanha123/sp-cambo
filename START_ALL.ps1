param(
    [int]$StartupTimeoutSec = 90,
    [switch]$CleanFrontendCache,
    [switch]$RestartFrontend
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path -LiteralPath (Split-Path -Parent $MyInvocation.MyCommand.Path)).Path
$Backend = Join-Path $ProjectRoot 'backend'
$Frontend = Join-Path $ProjectRoot 'frontend'
$Gateway = Join-Path $ProjectRoot 'gateway'

$BackendEnv = Join-Path $Backend '.env'
$FrontendEnv = Join-Path $Frontend '.env'
$GatewayEnv = Join-Path $Gateway '.env'

$LaravelApiUrl = 'http://127.0.0.1:8000'
$ControlPlaneUrl = 'http://127.0.0.1:8001'
$FrontendUrl = 'http://127.0.0.1:3000'
$GatewayUrl = 'http://127.0.0.1:3010'
$KhqrUrl = 'http://127.0.0.1:3011'

function Write-Section {
    param([string]$Text)
    Write-Host ''
    Write-Host $Text -ForegroundColor Cyan
}

function Assert-Path {
    param(
        [string]$Path,
        [string]$Label
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "$Label not found: $Path"
    }
}

function Get-DotEnvValue {
    param(
        [string]$Path,
        [string]$Name
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*='
    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match $pattern } |
        Select-Object -Last 1

    if (-not $line) {
        return $null
    }

    $value = (($line -split '=', 2)[1]).Trim()

    if ($value.Length -ge 2) {
        $doubleQuoted = $value.StartsWith('"') -and $value.EndsWith('"')
        $singleQuoted = $value.StartsWith("'") -and $value.EndsWith("'")

        if ($doubleQuoted -or $singleQuoted) {
            $value = $value.Substring(1, $value.Length - 2)
        }
    }

    return $value
}

function Test-Http {
    param(
        [string]$Url,
        [int]$TimeoutSec = 3
    )

    try {
        $response = Invoke-WebRequest `
            -UseBasicParsing `
            -Uri $Url `
            -TimeoutSec $TimeoutSec

        return ([int]$response.StatusCode -lt 500)
    }
    catch {
        if ($_.Exception.Response) {
            try {
                return ([int]$_.Exception.Response.StatusCode -lt 500)
            }
            catch {}
        }

        return $false
    }
}

function Wait-Http {
    param(
        [string]$Name,
        [string]$Url,
        [int]$TimeoutSec
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSec)

    while ((Get-Date) -lt $deadline) {
        if (Test-Http -Url $Url -TimeoutSec 3) {
            Write-Host "[READY] $Name -> $Url" -ForegroundColor Green
            return
        }

        Start-Sleep -Seconds 1
    }

    throw "$Name did not become reachable at $Url within $TimeoutSec seconds."
}

function Get-ListeningProcess {
    param([int]$Port)

    try {
        $connection = Get-NetTCPConnection `
            -LocalPort $Port `
            -State Listen `
            -ErrorAction Stop |
            Select-Object -First 1

        if (-not $connection) {
            return $null
        }

        try {
            return Get-CimInstance Win32_Process `
                -Filter "ProcessId=$($connection.OwningProcess)" `
                -ErrorAction Stop
        }
        catch {
            return [pscustomobject]@{
                ProcessId = $connection.OwningProcess
                Name = ''
                CommandLine = ''
            }
        }
    }
    catch {
        return $null
    }
}

function Test-SpCamboFrontend {
    try {
        $response = Invoke-WebRequest `
            -UseBasicParsing `
            -Uri "$FrontendUrl/" `
            -TimeoutSec 5

        if ([int]$response.StatusCode -ge 500) {
            return $false
        }

        $body = [string]$response.Content

        return (
            $body -match 'SP Cambo' -or
            $body -match 'sp-global-stage' -or
            $body -match 'sp-cambo'
        )
    }
    catch {
        return $false
    }
}

function Stop-PortProcessIfOwnedByProject {
    param(
        [int]$Port,
        [string]$ExpectedPath,
        [string]$ServiceName
    )

    $process = Get-ListeningProcess -Port $Port

    if (-not $process) {
        return
    }

    $commandLine = [string]$process.CommandLine
    $normalizedExpected = $ExpectedPath.ToLowerInvariant()
    $normalizedCommand = $commandLine.ToLowerInvariant()

    if ($normalizedCommand.Contains($normalizedExpected)) {
        Write-Host "[RESTART] Stopping existing $ServiceName PID $($process.ProcessId)" -ForegroundColor Yellow
        Stop-Process -Id $process.ProcessId -Force
        Start-Sleep -Milliseconds 600
        return
    }

    Write-Host ''
    Write-Host "[BLOCKED] Port $Port is already owned by another process." -ForegroundColor Red
    Write-Host "PID: $($process.ProcessId)"
    Write-Host "Name: $($process.Name)"
    Write-Host "Command: $commandLine"
    Write-Host ''
    throw "Refusing to stop an unrelated process on port $Port."
}

function Start-ServiceWindow {
    param(
        [string]$Name,
        [string]$WorkingDirectory,
        [string]$Command,
        [string]$HealthUrl = '',
        [int]$ExpectedPort = 0,
        [switch]$ForceRestart
    )

    if ($ForceRestart -and $ExpectedPort -gt 0) {
        Stop-PortProcessIfOwnedByProject `
            -Port $ExpectedPort `
            -ExpectedPath $WorkingDirectory `
            -ServiceName $Name
    }

    if ($HealthUrl -and (Test-Http -Url $HealthUrl -TimeoutSec 3)) {
        Write-Host "[SKIP] $Name is already running." -ForegroundColor Green
        return
    }

    Write-Host "[START] $Name" -ForegroundColor Cyan

    $workingEscaped = $WorkingDirectory.Replace("'", "''")

    $script = @"
`$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath '$workingEscaped'
$Command
"@

    $bytes = [System.Text.Encoding]::Unicode.GetBytes($script)
    $encoded = [Convert]::ToBase64String($bytes)

    Start-Process `
        -FilePath 'powershell.exe' `
        -ArgumentList @(
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-EncodedCommand',
            $encoded
        ) |
        Out-Null

    if ($HealthUrl) {
        Wait-Http `
            -Name $Name `
            -Url $HealthUrl `
            -TimeoutSec $StartupTimeoutSec
    }
}

function Ensure-PnpmDependencies {
    param(
        [string]$WorkingDirectory,
        [string]$Label
    )

    Push-Location $WorkingDirectory

    try {
        if (-not (Test-Path -LiteralPath '.\node_modules')) {
            Write-Host "[INSTALL] $Label dependencies" -ForegroundColor Yellow

            & npx pnpm@11.22.0 install --frozen-lockfile

            if ($LASTEXITCODE -ne 0) {
                throw "$Label pnpm install failed."
            }
        }
    }
    finally {
        Pop-Location
    }
}

function Assert-SpCamboFrontendSource {
    param([string]$AppPath)

    $source = Get-Content -LiteralPath $AppPath -Raw

    if ($source -match '<NuxtWelcome\b') {
        throw "NuxtWelcome was found in $AppPath. This is not the SP Cambo frontend entrypoint."
    }

    if ($source -notmatch '<NuxtPage\b') {
        throw "NuxtPage was not found in $AppPath. Refusing to start the wrong Nuxt application."
    }
}

Write-Host ''
Write-Host '====================================================' -ForegroundColor DarkCyan
Write-Host '          SP CAMBO - LOCAL DEVELOPMENT START' -ForegroundColor Cyan
Write-Host '====================================================' -ForegroundColor DarkCyan
Write-Host "Project: $ProjectRoot"
Write-Host ''

Assert-Path -Path (Join-Path $Backend 'artisan') -Label 'Laravel backend'
Assert-Path -Path (Join-Path $Frontend 'package.json') -Label 'Nuxt frontend'
Assert-Path -Path (Join-Path $Frontend 'app\app.vue') -Label 'SP Cambo frontend app'
Assert-Path -Path (Join-Path $Gateway 'package.json') -Label 'Inference gateway'
Assert-Path -Path $BackendEnv -Label 'backend/.env'

$bakongToken = Get-DotEnvValue -Path $BackendEnv -Name 'BAKONG_TOKEN'
if ([string]::IsNullOrWhiteSpace($bakongToken)) {
    Write-Host '[WARN] BAKONG_TOKEN is not set. KHQR can be generated, but payment verification will fail until it is configured.' -ForegroundColor Yellow
}

$FrontendApp = Join-Path $Frontend 'app\app.vue'
Assert-SpCamboFrontendSource -AppPath $FrontendApp

# The old launcher forwarded an extra `--` to Nuxt. Nuxt interpreted `--host`
# as a project directory and generated a default welcome app under
# frontend/--host. Remove only that generated directory and rebuild the real
# frontend cache if an older copy is still present.
$LegacyWrongFrontendRoot = Join-Path $Frontend '--host'

if (Test-Path -LiteralPath $LegacyWrongFrontendRoot) {
    Write-Host '[CLEAN] Removing legacy frontend/--host Nuxt workspace' -ForegroundColor Yellow
    Remove-Item -LiteralPath $LegacyWrongFrontendRoot -Recurse -Force
    $CleanFrontendCache = $true
}

Write-Section '[1/7] Laravel preflight'

Push-Location $Backend

try {
    $oldCacheStore = $env:CACHE_STORE

    try {
        $env:CACHE_STORE = 'file'

        & php artisan optimize:clear

        if ($LASTEXITCODE -ne 0) {
            throw 'php artisan optimize:clear failed.'
        }

        Write-Host '[MIGRATE] Applying pending Laravel database migrations' -ForegroundColor Yellow

        & php artisan migrate --force --no-interaction

        if ($LASTEXITCODE -ne 0) {
            throw 'Laravel database migration failed. Check the database settings in backend/.env.'
        }
    }
    finally {
        $env:CACHE_STORE = $oldCacheStore
    }
}
finally {
    Pop-Location
}

$gatewaySecret = Get-DotEnvValue `
    -Path $BackendEnv `
    -Name 'SP_CAMBO_INTERNAL_GATEWAY_SECRET'

if ([string]::IsNullOrWhiteSpace($gatewaySecret) -or $gatewaySecret.Length -lt 32) {
    throw 'backend/.env SP_CAMBO_INTERNAL_GATEWAY_SECRET is missing or shorter than 32 characters.'
}

$env:SP_CAMBO_INTERNAL_GATEWAY_SECRET = $gatewaySecret
$env:CONTROL_PLANE_BASE_URL = $ControlPlaneUrl

foreach ($name in @(
    'GATEWAY_HOST',
    'GATEWAY_PORT',
    'GATEWAY_RATE_STORE',
    'REDIS_URL'
)) {
    $value = Get-DotEnvValue -Path $GatewayEnv -Name $name

    if (-not [string]::IsNullOrWhiteSpace($value)) {
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

if ([string]::IsNullOrWhiteSpace($env:GATEWAY_HOST)) {
    $env:GATEWAY_HOST = '127.0.0.1'
}

if ([string]::IsNullOrWhiteSpace($env:GATEWAY_PORT)) {
    $env:GATEWAY_PORT = '3010'
}

if ([string]::IsNullOrWhiteSpace($env:GATEWAY_RATE_STORE)) {
    $env:GATEWAY_RATE_STORE = 'memory'
}

Write-Section '[2/7] Laravel API :8000'

Start-ServiceWindow `
    -Name 'Laravel API' `
    -WorkingDirectory $Backend `
    -HealthUrl "$LaravelApiUrl/api/v1/health" `
    -ExpectedPort 8000 `
    -Command 'php artisan serve --host=127.0.0.1 --port=8000'

Write-Section '[3/7] Laravel control plane :8001'

Start-ServiceWindow `
    -Name 'Control Plane' `
    -WorkingDirectory $Backend `
    -HealthUrl "$ControlPlaneUrl/api/v1/health" `
    -ExpectedPort 8001 `
    -Command 'php artisan serve --host=127.0.0.1 --port=8001'

Write-Section '[4/7] Inference Gateway :3010'

Ensure-PnpmDependencies `
    -WorkingDirectory $Gateway `
    -Label 'Gateway'

Start-ServiceWindow `
    -Name 'Gateway' `
    -WorkingDirectory $Gateway `
    -HealthUrl "$GatewayUrl/health" `
    -ExpectedPort 3010 `
    -Command 'npx pnpm@11.22.0 dev'

Wait-Http `
    -Name 'Gateway readiness' `
    -Url "$GatewayUrl/ready" `
    -TimeoutSec $StartupTimeoutSec

Write-Section '[5/7] KHQR payment sidecar :3011'

$khqrSecret = Get-DotEnvValue `
    -Path $BackendEnv `
    -Name 'BAKONG_KHQR_GENERATOR_SECRET'

if ([string]::IsNullOrWhiteSpace($khqrSecret) -or $khqrSecret.Length -lt 32) {
    Write-Host '[SKIP] KHQR sidecar is not configured.' -ForegroundColor Yellow
}
else {
    $env:BAKONG_KHQR_GENERATOR_SECRET = $khqrSecret
    $env:KHQR_HOST = '127.0.0.1'
    $env:KHQR_PORT = '3011'

    Start-ServiceWindow `
        -Name 'KHQR' `
        -WorkingDirectory $Gateway `
        -HealthUrl "$KhqrUrl/health" `
        -ExpectedPort 3011 `
        -Command 'npx pnpm@11.22.0 khqr'
}

Write-Section '[6/7] Nuxt frontend :3000'

Ensure-PnpmDependencies `
    -WorkingDirectory $Frontend `
    -Label 'Frontend'

if ($CleanFrontendCache) {
    Write-Host '[CLEAN] Removing frontend .nuxt cache' -ForegroundColor Yellow

    $nuxtCache = Join-Path $Frontend '.nuxt'

    if (Test-Path -LiteralPath $nuxtCache) {
        Remove-Item -LiteralPath $nuxtCache -Recurse -Force
    }

    Push-Location $Frontend

    try {
        & npx pnpm@11.22.0 exec nuxt prepare

        if ($LASTEXITCODE -ne 0) {
            throw 'nuxt prepare failed.'
        }
    }
    finally {
        Pop-Location
    }
}

$frontendIsSpCambo = Test-SpCamboFrontend

if ($frontendIsSpCambo -and -not $RestartFrontend) {
    Write-Host "[SKIP] SP Cambo frontend is already running at $FrontendUrl" -ForegroundColor Green
}
else {
    if ((Test-Http -Url "$FrontendUrl/" -TimeoutSec 3) -and -not $frontendIsSpCambo) {
        Write-Host '[WARN] Port 3000 is responding, but it does not look like SP Cambo.' -ForegroundColor Yellow

        Stop-PortProcessIfOwnedByProject `
            -Port 3000 `
            -ExpectedPath $Frontend `
            -ServiceName 'Frontend'
    }
    elseif ($RestartFrontend) {
        Stop-PortProcessIfOwnedByProject `
            -Port 3000 `
            -ExpectedPath $Frontend `
            -ServiceName 'Frontend'
    }

    Start-ServiceWindow `
        -Name 'Frontend' `
        -WorkingDirectory $Frontend `
        -HealthUrl "$FrontendUrl/" `
        -ExpectedPort 3000 `
        -Command 'npx pnpm@11.22.0 dev --host 127.0.0.1 --port 3000'

    $identityDeadline = (Get-Date).AddSeconds($StartupTimeoutSec)

    while ((Get-Date) -lt $identityDeadline) {
        if (Test-SpCamboFrontend) {
            Write-Host '[READY] Verified SP Cambo frontend identity.' -ForegroundColor Green
            break
        }

        Start-Sleep -Seconds 1
    }

    if (-not (Test-SpCamboFrontend)) {
        throw 'Port 3000 is reachable, but the page does not look like the SP Cambo frontend.'
    }
}

Write-Section '[7/7] Laravel scheduler'

$schedulerRunning = $false

try {
    $schedulerRunning = @(
        Get-CimInstance Win32_Process -ErrorAction Stop |
        Where-Object {
            $_.CommandLine -and
            $_.CommandLine -match 'artisan\s+schedule:work'
        }
    ).Count -gt 0
}
catch {}

if ($schedulerRunning) {
    Write-Host '[SKIP] Scheduler is already running.' -ForegroundColor Green
}
else {
    Start-ServiceWindow `
        -Name 'Scheduler' `
        -WorkingDirectory $Backend `
        -Command 'php artisan schedule:work'
}

Write-Host ''
Write-Host 'Checking seeded sell catalog...' -ForegroundColor Cyan

Push-Location $Backend

try {
    & php artisan catalog:sell-status

    if ($LASTEXITCODE -ne 0) {
        Write-Host '[WARN] Stack is running, but the sell catalog is not fully READY.' -ForegroundColor Yellow
        Write-Host '[WARN] Check Admin > Providers and Admin > Model routing.' -ForegroundColor Yellow
    }
}
finally {
    Pop-Location
}

Write-Host ''
Write-Host '====================================================' -ForegroundColor Green
Write-Host ' SP CAMBO STARTED' -ForegroundColor Green
Write-Host '====================================================' -ForegroundColor Green
Write-Host "Frontend:      $FrontendUrl"
Write-Host "Laravel API:   $LaravelApiUrl"
Write-Host "Control Plane: $ControlPlaneUrl"
Write-Host "Gateway:       $GatewayUrl"

if (-not [string]::IsNullOrWhiteSpace($khqrSecret) -and $khqrSecret.Length -ge 32) {
    Write-Host "KHQR:          $KhqrUrl"
}

Write-Host ''
Write-Host 'Useful commands:' -ForegroundColor Cyan
Write-Host '  Normal start:            .\START_ALL.ps1'
Write-Host '  Clean Nuxt cache:        .\START_ALL.ps1 -CleanFrontendCache'
Write-Host '  Restart only stale UI:   .\START_ALL.ps1 -RestartFrontend -CleanFrontendCache'
Write-Host '  Stop services:           .\STOP_ALL.ps1'
Write-Host ''
