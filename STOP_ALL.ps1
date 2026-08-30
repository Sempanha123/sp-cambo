$ErrorActionPreference = 'Continue'

Write-Host 'Stopping SP Cambo local services...' -ForegroundColor Cyan

foreach ($port in @(3000, 8000, 8001, 3010, 3011)) {
    $matches = @(netstat -ano | Select-String ":$port\s+.*LISTENING\s+(\d+)\s*$")
    foreach ($match in $matches) {
        $pidMatch = [regex]::Match($match.Line, 'LISTENING\s+(\d+)\s*$')
        if (-not $pidMatch.Success) { continue }
        $pidToStop = [int]$pidMatch.Groups[1].Value
        try {
            Stop-Process -Id $pidToStop -Force -ErrorAction Stop
            Write-Host "[STOPPED] port $port PID $pidToStop" -ForegroundColor Green
        } catch {}
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

Write-Host 'SP Cambo local services stopped.' -ForegroundColor Green
