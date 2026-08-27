param([string]$ProjectRoot = '')
$script = Join-Path $PSScriptRoot 'CHECK_TELEGRAM_BOT.ps1'
Write-Host '[INFO] Fix19 uses one Telegram Store Bot. Forwarding to CHECK_TELEGRAM_BOT.ps1.' -ForegroundColor Cyan
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) { & $script } else { & $script -ProjectRoot $ProjectRoot }
exit $LASTEXITCODE
