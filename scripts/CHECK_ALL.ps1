param([string]$ProjectRoot = "")
$ErrorActionPreference = "Continue"

function Check-Url {
    param([string]$Name,[string]$Url,[int]$TimeoutSec)

    try {
        $r=Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec $TimeoutSec
        Write-Host "[OK]   $Name -> HTTP $($r.StatusCode)" -ForegroundColor Green
    } catch {
        if ($_.Exception.Response) {
            try {
                $s=[int]$_.Exception.Response.StatusCode
                Write-Host "[OK]   $Name -> HTTP $s (reachable)" -ForegroundColor Green
                return
            } catch {}
        }
        Write-Host "[FAIL] $Name -> $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "SP Cambo local service check" -ForegroundColor Cyan
Write-Host "----------------------------"

# Nuxt can need longer during first compile/warm-up.
Check-Url "Frontend" "http://127.0.0.1:3000/" 20
Check-Url "Laravel" "http://127.0.0.1:8000/api/v1/health" 5
Check-Url "Gateway" "http://127.0.0.1:3010/health" 5
Check-Url "KHQR" "http://127.0.0.1:3011/health" 5

Write-Host ""
Write-Host "Claude Code:" -ForegroundColor Cyan
Write-Host '$env:ANTHROPIC_BASE_URL="http://127.0.0.1:3010"'
Write-Host '$env:ANTHROPIC_AUTH_TOKEN="sk-spc-YOUR-KEY"'
Write-Host '$env:ANTHROPIC_MODEL="claude-opus-5"'
Write-Host 'claude'
