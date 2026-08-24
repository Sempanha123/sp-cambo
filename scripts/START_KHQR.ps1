param(
  [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
$gateway = Join-Path $ProjectRoot 'gateway'
$backendEnv = Join-Path $ProjectRoot 'backend\.env'

function Get-DotEnvValue([string]$Path, [string]$Name) {
    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=') } |
        Select-Object -Last 1
    if (-not $line) { return $null }
    return (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}

$secret = Get-DotEnvValue $backendEnv 'BAKONG_KHQR_GENERATOR_SECRET'
if (-not $secret -or $secret.Length -lt 32) {
    throw "backend/.env BAKONG_KHQR_GENERATOR_SECRET is missing or too short."
}

$env:BAKONG_KHQR_GENERATOR_SECRET = $secret
$env:KHQR_HOST = '127.0.0.1'
$env:KHQR_PORT = '3011'

Set-Location $gateway
Write-Host "Starting private KHQR generator on http://127.0.0.1:3011" -ForegroundColor Cyan
npx pnpm@11.22.0 khqr
