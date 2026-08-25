param(
  [string]$ProjectRoot = ''
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
  $scriptPath = $MyInvocation.MyCommand.Path
  $scriptDir = Split-Path -Parent $scriptPath
  $ProjectRoot = Split-Path -Parent $scriptDir
}
$ProjectRoot = (Resolve-Path $ProjectRoot).Path

Write-Host "Windows PowerShell version: $($PSVersionTable.PSVersion)" -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot" -ForegroundColor DarkGray

$repair = Join-Path $ProjectRoot 'scripts\APPLY_LOCAL_STACK_FIX.ps1'
if (-not (Test-Path $repair)) { throw "Missing $repair" }

# Running the repair is the strongest compatibility check and does not print secrets.
& powershell -NoProfile -ExecutionPolicy Bypass -File $repair -ProjectRoot $ProjectRoot
if ($LASTEXITCODE -ne 0) { throw 'Local environment repair failed.' }

Write-Host '[OK] PowerShell 5.1-compatible secret generation and env synchronization passed.' -ForegroundColor Green
Write-Host 'Next: .\scripts\START_LOCAL_STACK.ps1' -ForegroundColor Cyan
