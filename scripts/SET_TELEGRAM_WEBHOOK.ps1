param(
  [Parameter(Mandatory=$true)][string]$PublicBackendBaseUrl,
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
$envPath = Join-Path $ProjectRoot 'backend\.env'
if (-not (Test-Path $envPath)) { throw "backend/.env not found." }

function Get-DotEnvValue([string]$Path, [string]$Name) {
  $line = Get-Content -LiteralPath $Path |
    Where-Object { $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=') } |
    Select-Object -Last 1
  if (-not $line) { return $null }
  return (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}

$token = Get-DotEnvValue $envPath 'TELEGRAM_STOREFRONT_BOT_TOKEN'
if (-not $token) { $token = Get-DotEnvValue $envPath 'TELEGRAM_BOT_TOKEN' }
$secret = Get-DotEnvValue $envPath 'TELEGRAM_STOREFRONT_WEBHOOK_SECRET'
if (-not $secret) { $secret = Get-DotEnvValue $envPath 'TELEGRAM_WEBHOOK_SECRET' }
if (-not $token) { throw "TELEGRAM_STOREFRONT_BOT_TOKEN is empty in backend/.env." }
if (-not $secret) { throw "TELEGRAM_STOREFRONT_WEBHOOK_SECRET is empty in backend/.env." }

$base = $PublicBackendBaseUrl.TrimEnd('/')
if (-not $base.StartsWith('https://')) {
  throw "Telegram requires a public HTTPS webhook URL. Example: https://api.spcambo.com"
}
$webhook = "$base/api/v1/telegram/webhook"

$body = @{
  url = $webhook
  secret_token = $secret
  allowed_updates = @('message', 'callback_query')
  drop_pending_updates = $false
} | ConvertTo-Json -Depth 4

Write-Host "Registering SP Cambo Store Bot webhook: $webhook" -ForegroundColor Cyan
$response = Invoke-RestMethod -Method Post -Uri "https://api.telegram.org/bot$token/setWebhook" -ContentType 'application/json' -Body $body -TimeoutSec 20
if (-not $response.ok) { throw "Telegram rejected the webhook registration." }
Write-Host "[OK] SP Cambo Store Bot webhook registered. Website checkout remains Telegram-silent in Fix19." -ForegroundColor Green

$info = Invoke-RestMethod -Method Get -Uri "https://api.telegram.org/bot$token/getWebhookInfo" -TimeoutSec 20
Write-Host "Webhook URL: $($info.result.url)"
Write-Host "Pending updates: $($info.result.pending_update_count)"
if ($info.result.last_error_message) { Write-Host "Last Telegram error: $($info.result.last_error_message)" -ForegroundColor Yellow }
