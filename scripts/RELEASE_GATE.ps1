[CmdletBinding()]
param(
    [switch]$SkipDocker,
    [switch]$SkipInstall,
    [switch]$FixLint,
    [switch]$SkipProductionPreflight
)
$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

& (Join-Path $ScriptDir 'FINAL_ACCEPTANCE.ps1') -SkipDocker:$SkipDocker -SkipInstall:$SkipInstall -FixLint:$FixLint -SkipLive
if ($LASTEXITCODE -ne 0) { throw 'Automated acceptance failed.' }

if (-not $SkipProductionPreflight) {
    & (Join-Path $ScriptDir 'PRODUCTION_PREFLIGHT.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Production configuration preflight failed.' }
}

Write-Host "`nRELEASE CANDIDATE GATE PASSED." -ForegroundColor Green
Write-Host 'Do NOT label the deployment Production Ready until scripts/REAL_ACCEPTANCE.ps1 and the controlled real payment/provider/Telegram checklist are completed.' -ForegroundColor Yellow
exit 0
