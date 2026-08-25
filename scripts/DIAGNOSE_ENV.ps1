param([string]$ProjectRoot = "")
$ErrorActionPreference = "Stop"

$ScriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ScriptRoot)) { $ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path }
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) { $ProjectRoot = Split-Path -Parent $ScriptRoot }
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path


function Get-DotEnvValue {
    param(
        [Parameter(Mandatory=$true)][string]$Path,
        [Parameter(Mandatory=$true)][string]$Name
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
        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }
    }

    return $value
}

function Set-ProcessEnvFromDotEnv {
    param(
        [Parameter(Mandatory=$true)][string]$Path,
        [Parameter(Mandatory=$true)][string]$Name
    )

    $value = Get-DotEnvValue -Path $Path -Name $Name
    if (-not [string]::IsNullOrWhiteSpace($value)) {
        [Environment]::SetEnvironmentVariable($Name, $value, "Process")
        return $value
    }

    return $null
}


$backendEnv = Join-Path $ProjectRoot "backend\.env"
$gatewayEnv = Join-Path $ProjectRoot "gateway\.env"

$backendSecret = Get-DotEnvValue $backendEnv "SP_CAMBO_INTERNAL_GATEWAY_SECRET"
$gatewaySecret = Get-DotEnvValue $gatewayEnv "SP_CAMBO_INTERNAL_GATEWAY_SECRET"
$khqrBackend = Get-DotEnvValue $backendEnv "BAKONG_KHQR_GENERATOR_SECRET"
$khqrGateway = Get-DotEnvValue $gatewayEnv "BAKONG_KHQR_GENERATOR_SECRET"

Write-Host "SP Cambo env diagnostic (values hidden)" -ForegroundColor Cyan
Write-Host "---------------------------------------"

Write-Host ("backend internal secret: " + $(if($backendSecret){"present, length $($backendSecret.Length)"}else{"MISSING"}))
Write-Host ("gateway internal secret: " + $(if($gatewaySecret){"present, length $($gatewaySecret.Length)"}else{"MISSING"}))

if ($backendSecret -and $gatewaySecret) {
    Write-Host ("internal secrets match: " + ($backendSecret -eq $gatewaySecret))
}

Write-Host ("backend KHQR secret: " + $(if($khqrBackend){"present, length $($khqrBackend.Length)"}else{"MISSING"}))
Write-Host ("gateway KHQR secret: " + $(if($khqrGateway){"present, length $($khqrGateway.Length)"}else{"MISSING"}))

Write-Host ""
Write-Host "No secret values were printed." -ForegroundColor DarkGray
