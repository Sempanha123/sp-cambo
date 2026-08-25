[CmdletBinding()]
param([switch]$SkipDocker, [switch]$SkipLiveCredentials)
$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
Set-Location $Root

Write-Host 'SP Cambo V2 release readiness' -ForegroundColor Cyan
& (Join-Path $ScriptDir 'FINAL_ACCEPTANCE.ps1') -SkipDocker:$SkipDocker -SkipLive
if ($LASTEXITCODE -ne 0) { throw 'FINAL_ACCEPTANCE.ps1 failed.' }

if (-not $SkipLiveCredentials) {
    & (Join-Path $ScriptDir 'LIVE_ACCEPTANCE_PREFLIGHT.ps1') -RequireLiveCredentials
    if ($LASTEXITCODE -ne 0) { throw 'LIVE_ACCEPTANCE_PREFLIGHT.ps1 failed.' }
}

Write-Host 'RELEASE READY: automated gates and requested credential preflight passed.' -ForegroundColor Green
Write-Host 'Complete the controlled live Bakong, Telegram, and OmniRoute smoke transactions before production promotion.' -ForegroundColor Yellow
