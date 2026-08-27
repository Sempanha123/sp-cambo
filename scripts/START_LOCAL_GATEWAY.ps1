param(
  [string]$ProjectRoot = ''
)

$ErrorActionPreference = 'Stop'

# Resolve the project root after parameter binding. Windows PowerShell 5.1 can
# expose an empty $PSScriptRoot while evaluating a parameter default in some
# launch paths, so use the actual script path from $MyInvocation instead.
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        throw 'Could not resolve the current script path. Pass -ProjectRoot explicitly.'
    }
    $scriptDir = Split-Path -Parent $scriptPath
    $ProjectRoot = Split-Path -Parent $scriptDir
}
$ProjectRoot = (Resolve-Path $ProjectRoot).Path
$gateway = Join-Path $ProjectRoot 'gateway'
$backend = Join-Path $ProjectRoot 'backend'
$gatewayEnv = Join-Path $gateway '.env'
$backendEnv = Join-Path $backend '.env'

function Get-DotEnvValue([string]$Path, [string]$Name) {
    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=') } |
        Select-Object -Last 1
    if (-not $line) { return $null }
    $value = (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
    return $value
}

if (-not (Test-Path $gatewayEnv)) {
    throw "gateway/.env is missing. Run APPLY_LOCAL_STACK_FIX.ps1 first."
}

$secret = Get-DotEnvValue $gatewayEnv 'SP_CAMBO_INTERNAL_GATEWAY_SECRET'
if (-not $secret -or $secret.Length -lt 32) {
    throw "gateway/.env SP_CAMBO_INTERNAL_GATEWAY_SECRET is missing/short. Run APPLY_LOCAL_STACK_FIX.ps1 first."
}

# Explicit process variables make startup reliable even if Node dotenv loading changes.
$env:SP_CAMBO_INTERNAL_GATEWAY_SECRET = $secret
$env:GATEWAY_HOST = (Get-DotEnvValue $gatewayEnv 'GATEWAY_HOST')
$env:GATEWAY_PORT = (Get-DotEnvValue $gatewayEnv 'GATEWAY_PORT')
$env:CONTROL_PLANE_BASE_URL = (Get-DotEnvValue $gatewayEnv 'CONTROL_PLANE_BASE_URL')
$env:GATEWAY_RATE_STORE = (Get-DotEnvValue $gatewayEnv 'GATEWAY_RATE_STORE')

if (-not $env:GATEWAY_HOST) { $env:GATEWAY_HOST = '127.0.0.1' }
if (-not $env:GATEWAY_PORT) { $env:GATEWAY_PORT = '3010' }
if (-not $env:CONTROL_PLANE_BASE_URL -or $env:CONTROL_PLANE_BASE_URL -in @('http://127.0.0.1:8000','http://localhost:8000')) { $env:CONTROL_PLANE_BASE_URL = 'http://127.0.0.1:8001' }
if (-not $env:GATEWAY_RATE_STORE) { $env:GATEWAY_RATE_STORE = 'memory' }

Set-Location $gateway

Write-Host "Starting SP Cambo inference gateway..." -ForegroundColor Cyan
Write-Host "HTTP: http://127.0.0.1:3010" -ForegroundColor Green
Write-Host "Claude Code base: http://127.0.0.1:3010  (NO /v1)" -ForegroundColor Yellow
Write-Host "OpenAI/Codex base: http://127.0.0.1:3010/v1" -ForegroundColor Yellow
Write-Host "Internal secret loaded: $($secret.Length) chars (hidden)" -ForegroundColor DarkGray
Write-Host "Control plane: $env:CONTROL_PLANE_BASE_URL" -ForegroundColor DarkGray
Write-Host "Rate store: $env:GATEWAY_RATE_STORE" -ForegroundColor DarkGray

npx pnpm@11.22.0 dev
