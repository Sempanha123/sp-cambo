param([string]$ProjectRoot = "")
$ErrorActionPreference = "Continue"

$ScriptRoot=$PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ScriptRoot)) { $ScriptRoot=Split-Path -Parent $MyInvocation.MyCommand.Path }
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) { $ProjectRoot=Split-Path -Parent $ScriptRoot }
$ProjectRoot=(Resolve-Path -LiteralPath $ProjectRoot).Path

foreach ($port in @(3000,8000,3010,3011)) {
    $lines=@(netstat -ano | Select-String ":$port\s+.*LISTENING\s+(\d+)\s*$")
    foreach ($line in $lines) {
        $m=[regex]::Match($line.Line,"LISTENING\s+(\d+)\s*$")
        if ($m.Success) {
            $pidToStop=[int]$m.Groups[1].Value
            try {
                Stop-Process -Id $pidToStop -Force -ErrorAction Stop
                Write-Host "[STOPPED] port $port PID $pidToStop" -ForegroundColor Green
            } catch {}
        }
    }
}

try {
    Get-CimInstance Win32_Process |
        Where-Object { $_.CommandLine -and $_.CommandLine -match 'artisan\s+schedule:work' } |
        ForEach-Object {
            try {
                Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
                Write-Host "[STOPPED] Scheduler PID $($_.ProcessId)" -ForegroundColor Green
            } catch {}
        }
} catch {}

Write-Host "Done." -ForegroundColor Green
