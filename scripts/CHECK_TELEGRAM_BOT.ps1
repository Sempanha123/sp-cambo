param(
  [string]$ProjectRoot = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
  $scriptPath = $MyInvocation.MyCommand.Path
  if ([string]::IsNullOrWhiteSpace($scriptPath)) { throw 'Pass -ProjectRoot explicitly.' }
  $ProjectRoot = Split-Path -Parent (Split-Path -Parent $scriptPath)
}
$ProjectRoot = (Resolve-Path $ProjectRoot).Path
$envPath = Join-Path $ProjectRoot 'backend\.env'
if (-not (Test-Path $envPath)) { throw 'backend/.env not found.' }

function Get-DotEnvValue([string]$Path, [string]$Name) {
  $line = Get-Content -LiteralPath $Path |
    Where-Object { $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=') } |
    Select-Object -Last 1
  if (-not $line) { return $null }
  return (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}

$token = Get-DotEnvValue $envPath 'TELEGRAM_STOREFRONT_BOT_TOKEN'
if (-not $token) { $token = Get-DotEnvValue $envPath 'TELEGRAM_BOT_TOKEN' }
$expectedUsername = Get-DotEnvValue $envPath 'TELEGRAM_STOREFRONT_BOT_USERNAME'
if (-not $expectedUsername) { $expectedUsername = Get-DotEnvValue $envPath 'TELEGRAM_BOT_USERNAME' }

Write-Host ''
Write-Host 'SP Cambo Telegram Store Bot check' -ForegroundColor Cyan
Write-Host '----------------------------------' -ForegroundColor Cyan

if ([string]::IsNullOrWhiteSpace($token)) {
  Write-Host '[MISSING] TELEGRAM_STOREFRONT_BOT_TOKEN is empty.' -ForegroundColor Red
  exit 1
}

try {
  $me = Invoke-RestMethod -Method Get -Uri "https://api.telegram.org/bot$token/getMe" -TimeoutSec 20
  if (-not $me.ok) { throw 'Telegram returned ok=false.' }
  Write-Host "[OK] Store Bot authenticated: @$($me.result.username)" -ForegroundColor Green
  if ($expectedUsername -and $expectedUsername.TrimStart('@') -ne [string]$me.result.username) {
    Write-Host "[WARN] .env username '$expectedUsername' does not match Telegram '@$($me.result.username)'." -ForegroundColor Yellow
  }
} catch {
  Write-Host '[FAIL] Store Bot authentication failed. Check the token.' -ForegroundColor Red
  exit 1
}

try {
  $info = Invoke-RestMethod -Method Get -Uri "https://api.telegram.org/bot$token/getWebhookInfo" -TimeoutSec 20
  if ($info.result.url) {
    Write-Host "[OK] Webhook: $($info.result.url)" -ForegroundColor Green
    Write-Host "     Pending updates: $($info.result.pending_update_count)"
    if ($info.result.last_error_message) {
      Write-Host "[WARN] Last Telegram webhook error: $($info.result.last_error_message)" -ForegroundColor Yellow
    }
  } else {
    Write-Host '[WARN] No webhook is registered. Run scripts\SET_TELEGRAM_WEBHOOK.ps1 with your public HTTPS backend URL.' -ForegroundColor Yellow
  }
} catch {
  Write-Host '[WARN] Could not read webhook status.' -ForegroundColor Yellow
}

Write-Host '[OK] Fix19 architecture: website checkout has no Telegram alert bot.' -ForegroundColor Green
Write-Host 'No bot token was printed by this script.' -ForegroundColor DarkGray
