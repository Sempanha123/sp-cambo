[CmdletBinding()]
param(
    [string]$BackendEnv = '',
    [switch]$RequireLiveCredentials
)
$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($BackendEnv)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) { throw 'Could not resolve script path. Pass -BackendEnv explicitly.' }
    $scriptDir = Split-Path -Parent $scriptPath
    $BackendEnv = Join-Path (Split-Path -Parent $scriptDir) 'backend\.env'
}
$Failures = [System.Collections.Generic.List[string]]::new()
function Read-DotEnv([string]$Path) {
    $map = @{}
    if (-not (Test-Path $Path)) { throw "Environment file not found: $Path" }
    foreach ($line in Get-Content $Path) {
        $t = $line.Trim(); if ($t -eq '' -or $t.StartsWith('#') -or -not $t.Contains('=')) { continue }
        $p = $t.Split('=',2); $map[$p[0].Trim()] = $p[1].Trim().Trim('"').Trim("'")
    }
    return $map
}
function Check([hashtable]$Env, [string]$Name, [bool]$Secret=$false) {
    $value = [string]$Env[$Name]
    if ([string]::IsNullOrWhiteSpace($value)) { $Failures.Add("Missing $Name"); Write-Host "MISSING: $Name" -ForegroundColor Red; return }
    if ($Secret) { Write-Host "OK: $Name is configured (redacted)" -ForegroundColor Green }
    else { Write-Host "OK: $Name=$value" -ForegroundColor Green }
}
$envMap = Read-DotEnv (Resolve-Path $BackendEnv)
Write-Host "=== SP Cambo live acceptance preflight ===" -ForegroundColor Cyan
Check $envMap 'APP_URL'
Check $envMap 'ANTHROPIC_BASE_URL'
Check $envMap 'ANTHROPIC_AUTH_TOKEN' $true
Check $envMap 'BAKONG_BASE_URL'
Check $envMap 'BAKONG_TOKEN' $true
Check $envMap 'BAKONG_ACCOUNT_ID'
Check $envMap 'BAKONG_MERCHANT_NAME'
Check $envMap 'TELEGRAM_BOT_TOKEN' $true
Check $envMap 'TELEGRAM_BOT_USERNAME'
Check $envMap 'TELEGRAM_WEBHOOK_SECRET' $true
Check $envMap 'TELEGRAM_LINK_SECRET' $true

Write-Host "`nLive test order:" -ForegroundColor Cyan
Write-Host '  1. Run scripts/FINAL_ACCEPTANCE.ps1 and require all local gates to pass.'
Write-Host '  2. Create a real low-value order and KHQR; verify unpaid state before payment.'
Write-Host '  3. Pay once; verify Bakong reconciliation fulfills exactly once and credits exactly once.'
Write-Host '  4. In Telegram private chat: /plans -> /buy <slug> -> pay -> /check; confirm key delivery once.'
Write-Host '  5. Route a smoke request through the admin-selected OmniRoute provider/private-model mapping.'
Write-Host '  6. Disable/unavailable the selected upstream in a controlled test; confirm safe error/failover policy and no double charge.'
Write-Host '  7. Smoke-test the issued SP Cambo key with Claude Code and OpenAI/Codex-compatible endpoints.'

if ($Failures.Count -gt 0) {
    Write-Host "`nPreflight found $($Failures.Count) missing setting(s)." -ForegroundColor Yellow
    $Failures | ForEach-Object { Write-Host " - $_" -ForegroundColor Yellow }
    if ($RequireLiveCredentials) { exit 1 }
    Write-Host 'No live calls were made and no secrets were printed.' -ForegroundColor Yellow
    exit 0
}
Write-Host "`nLive credential preflight PASS. No live calls were made and no secrets were printed." -ForegroundColor Green
exit 0
