param([string]$ProjectRoot = "")
$ErrorActionPreference = "Continue"
$failed = $false

function Check-Url {
    param([string]$Name,[string]$Url,[int]$TimeoutSec)
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec $TimeoutSec
        if ([int]$r.StatusCode -ge 500) {
            Write-Host "[FAIL] $Name -> HTTP $($r.StatusCode)" -ForegroundColor Red
            $script:failed = $true
            return
        }
        Write-Host "[OK]   $Name -> HTTP $($r.StatusCode)" -ForegroundColor Green
    } catch {
        if ($_.Exception.Response) {
            try {
                $s = [int]$_.Exception.Response.StatusCode
                if ($s -lt 500) {
                    Write-Host "[OK]   $Name -> HTTP $s (reachable)" -ForegroundColor Green
                    return
                }
                Write-Host "[FAIL] $Name -> HTTP $s" -ForegroundColor Red
                $script:failed = $true
                return
            } catch {}
        }
        Write-Host "[FAIL] $Name -> $($_.Exception.Message)" -ForegroundColor Red
        $script:failed = $true
    }
}

Write-Host ""
Write-Host "SP Cambo local service check" -ForegroundColor Cyan
Write-Host "----------------------------"
Check-Url "Frontend" "http://127.0.0.1:3000/" 20
Check-Url "Laravel" "http://127.0.0.1:8000/api/v1/health" 5
Check-Url "Control Plane" "http://127.0.0.1:8001/api/v1/health" 5
Check-Url "Gateway" "http://127.0.0.1:3010/health" 5
try {
    $gatewayHealth = Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:3010/health' -TimeoutSec 5
    if ($gatewayHealth.data.model_routing -eq 'database_internal_model_id' -and $gatewayHealth.data.build -eq 'fix28') {
        Write-Host '[OK]   Gateway database-model routing marker' -ForegroundColor Green
    } else {
        Write-Host '[FAIL] Gateway is stale/unknown. Restart it from this Fix25 source tree.' -ForegroundColor Red
        $script:failed = $true
    }
} catch {
    Write-Host '[FAIL] Could not verify Gateway build marker.' -ForegroundColor Red
    $script:failed = $true
}
Check-Url "Gateway readiness" "http://127.0.0.1:3010/ready" 5
Check-Url "KHQR" "http://127.0.0.1:3011/health" 5


$backend = Join-Path $ProjectRoot 'backend'
if ((Test-Path (Join-Path $backend 'artisan')) -and (Test-Path (Join-Path $backend 'vendor\autoload.php'))) {
    Push-Location $backend
    try {
        & php artisan system:check-access-allocation-schema
        if ($LASTEXITCODE -ne 0) {
            Write-Host '[FAIL] Database access-allocation schema is incomplete.' -ForegroundColor Red
            $script:failed = $true
        }
        else {
            Write-Host '[OK]   Database access-allocation schema' -ForegroundColor Green
        }
    }
    finally { Pop-Location }
}

Write-Host ""
Write-Host "Claude Code:" -ForegroundColor Cyan
Write-Host '$env:ANTHROPIC_BASE_URL="http://127.0.0.1:3010"'
Write-Host '$env:ANTHROPIC_AUTH_TOKEN="sk-spc-YOUR-KEY"'
Write-Host '$env:ANTHROPIC_MODEL="YOUR_PUBLIC_ALIAS"'
Write-Host 'claude'

if ($failed) { exit 1 }
exit 0
