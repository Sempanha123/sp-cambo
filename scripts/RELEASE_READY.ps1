[CmdletBinding()]
param([switch]$SkipDocker, [switch]$SkipLiveCredentials)
$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Write-Host 'RELEASE_READY.ps1 is retained for compatibility; it now creates a RELEASE CANDIDATE only.' -ForegroundColor Yellow
& (Join-Path $ScriptDir 'RELEASE_GATE.ps1') -SkipDocker:$SkipDocker -SkipProductionPreflight:$SkipLiveCredentials
exit $LASTEXITCODE
