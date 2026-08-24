param(
  [string]$ProjectRoot = "C:\Users\Rg Gear\Desktop\SP Cambo"
)

$ErrorActionPreference = 'Stop'

function Set-DotEnvValue {
    param(
        [Parameter(Mandatory=$true)][string]$Path,
        [Parameter(Mandatory=$true)][string]$Name,
        [Parameter(Mandatory=$true)][string]$Value
    )

    if (-not (Test-Path $Path)) {
        New-Item -ItemType File -Path $Path -Force | Out-Null
    }

    $content = @(Get-Content -LiteralPath $Path -ErrorAction SilentlyContinue)
    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*='
    $found = $false

    $newContent = foreach ($line in $content) {
        if ($line -match $pattern) {
            if (-not $found) {
                "$Name=$Value"
                $found = $true
            }
            # Drop duplicate entries for this key.
        } else {
            $line
        }
    }

    if (-not $found) {
        $newContent += "$Name=$Value"
    }

    Set-Content -LiteralPath $Path -Value $newContent -Encoding UTF8
}

function Get-DotEnvValue {
    param(
        [Parameter(Mandatory=$true)][string]$Path,
        [Parameter(Mandatory=$true)][string]$Name
    )

    if (-not (Test-Path $Path)) {
        return $null
    }

    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=') } |
        Select-Object -Last 1

    if (-not $line) {
        return $null
    }

    $value = (($line -split '=', 2)[1]).Trim()
    if (
        ($value.StartsWith('"') -and $value.EndsWith('"')) -or
        ($value.StartsWith("'") -and $value.EndsWith("'"))
    ) {
        $value = $value.Substring(1, $value.Length - 2)
    }

    return $value
}

function New-HexSecret {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    return ([Convert]::ToHexString($bytes)).ToLowerInvariant()
}

$ProjectRoot = (Resolve-Path $ProjectRoot).Path
$backend = Join-Path $ProjectRoot 'backend'
$gateway = Join-Path $ProjectRoot 'gateway'
$backendEnv = Join-Path $backend '.env'
$gatewayEnv = Join-Path $gateway '.env'

if (-not (Test-Path (Join-Path $backend 'artisan'))) {
    throw "Laravel backend not found: $backend"
}
if (-not (Test-Path (Join-Path $gateway 'package.json'))) {
    throw "Gateway not found: $gateway"
}

if (-not (Test-Path $gatewayEnv)) {
    $example = Join-Path $gateway '.env.example'
    if (Test-Path $example) {
        Copy-Item $example $gatewayEnv
    } else {
        New-Item -ItemType File -Path $gatewayEnv -Force | Out-Null
    }
}

$backendSecret = Get-DotEnvValue -Path $backendEnv -Name 'SP_CAMBO_INTERNAL_GATEWAY_SECRET'
$gatewaySecret = Get-DotEnvValue -Path $gatewayEnv -Name 'SP_CAMBO_INTERNAL_GATEWAY_SECRET'

if ($backendSecret -and $backendSecret.Length -ge 32) {
    $secret = $backendSecret
} elseif ($gatewaySecret -and $gatewaySecret.Length -ge 32) {
    $secret = $gatewaySecret
} else {
    $secret = New-HexSecret
}

# Synchronize the SAME secret without displaying it.
Set-DotEnvValue -Path $backendEnv -Name 'SP_CAMBO_INTERNAL_GATEWAY_SECRET' -Value $secret
Set-DotEnvValue -Path $gatewayEnv -Name 'SP_CAMBO_INTERNAL_GATEWAY_SECRET' -Value $secret

# Local inference gateway configuration.
Set-DotEnvValue -Path $gatewayEnv -Name 'GATEWAY_HOST' -Value '127.0.0.1'
Set-DotEnvValue -Path $gatewayEnv -Name 'GATEWAY_PORT' -Value '3010'
Set-DotEnvValue -Path $gatewayEnv -Name 'CONTROL_PLANE_BASE_URL' -Value 'http://127.0.0.1:8000'
Set-DotEnvValue -Path $gatewayEnv -Name 'GATEWAY_RATE_STORE' -Value 'memory'

# Local gateway advertised by Laravel/frontend.
Set-DotEnvValue -Path $backendEnv -Name 'SP_CAMBO_GATEWAY_BASE_URL' -Value 'http://127.0.0.1:3010'

# Keep KHQR settings aligned if a generator secret already exists in backend.
$khqrSecret = Get-DotEnvValue -Path $backendEnv -Name 'BAKONG_KHQR_GENERATOR_SECRET'
if ($khqrSecret -and $khqrSecret.Length -ge 32) {
    Set-DotEnvValue -Path $gatewayEnv -Name 'BAKONG_KHQR_GENERATOR_SECRET' -Value $khqrSecret
}

Set-DotEnvValue -Path $gatewayEnv -Name 'KHQR_HOST' -Value '127.0.0.1'
Set-DotEnvValue -Path $gatewayEnv -Name 'KHQR_PORT' -Value '3011'

Write-Host ""
Write-Host "SP Cambo local environment repaired." -ForegroundColor Green
Write-Host "Internal gateway secret synchronized between backend/.env and gateway/.env." -ForegroundColor Green
Write-Host "Secret length: $($secret.Length) characters (value intentionally hidden)." -ForegroundColor DarkGray
Write-Host "Gateway rate store: memory (Redis not required locally)." -ForegroundColor Green

# Clear Laravel cached configuration so it sees the synchronized secret.
Push-Location $backend
try {
    php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) {
        throw "php artisan optimize:clear failed."
    }
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "Next run:" -ForegroundColor Cyan
Write-Host 'powershell -ExecutionPolicy Bypass -File ".\scripts\START_LOCAL_STACK.ps1"'
