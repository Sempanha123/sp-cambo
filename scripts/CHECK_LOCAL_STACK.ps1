param(
  [string]$ApiKey = '',
  [string]$Model = 'claude-opus-5'
)

$ErrorActionPreference = 'Continue'

function Check-Url([string]$Name, [string]$Url) {
  try {
    $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 $Url
    Write-Host "[OK] $Name -> HTTP $($r.StatusCode)" -ForegroundColor Green
    return $true
  } catch {
    Write-Host "[FAIL] $Name -> $($_.Exception.Message)" -ForegroundColor Red
    return $false
  }
}

$laravelOk = Check-Url 'Laravel' 'http://127.0.0.1:8000/api/v1/health'
$gatewayOk = Check-Url 'Inference gateway' 'http://127.0.0.1:3010/health'
$khqrOk = Check-Url 'KHQR generator' 'http://127.0.0.1:3011/health'

if (-not $laravelOk) {
    Write-Host "  Start: .\scripts\START_LARAVEL.ps1" -ForegroundColor Yellow
}
if (-not $gatewayOk) {
    Write-Host "  Start: .\scripts\START_LOCAL_GATEWAY.ps1" -ForegroundColor Yellow
}
if (-not $khqrOk) {
    Write-Host "  Start: .\scripts\START_KHQR.ps1" -ForegroundColor Yellow
}


if ($laravelOk) {
  try {
    $status = Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:8000/api/v1/status' -TimeoutSec 5
    $control = $status.data.components | Where-Object { $_.key -eq 'control_plane' } | Select-Object -First 1
    if ($control -and $control.status -eq 'operational') {
      Write-Host "[OK] Scheduler/control plane heartbeat is healthy" -ForegroundColor Green
    } else {
      Write-Host "[WARN] Scheduler/control plane is degraded. Keep .\scripts\START_SCHEDULER.ps1 running for automatic payment and Telegram delivery." -ForegroundColor Yellow
    }
  } catch {
    Write-Host "[WARN] Could not read public system status: $($_.Exception.Message)" -ForegroundColor Yellow
  }
}

if ($ApiKey -and $gatewayOk) {
  try {
    $headers = @{ 'x-api-key' = $ApiKey }
    $status = Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:3010/v1/key/status' -Headers $headers -TimeoutSec 10
    Write-Host "[OK] SP Cambo API key accepted by gateway" -ForegroundColor Green
    $status | ConvertTo-Json -Depth 8
  } catch {
    Write-Host "[FAIL] API key check -> $($_.Exception.Message)" -ForegroundColor Red
  }
}

Write-Host "`nClaude Code local template:" -ForegroundColor Cyan
Write-Host '$env:ANTHROPIC_BASE_URL = "http://127.0.0.1:3010"'
Write-Host '$env:ANTHROPIC_AUTH_TOKEN = "sk-spc-YOUR-KEY"'
Write-Host ('$env:ANTHROPIC_MODEL = "' + $Model + '"')
Write-Host 'claude'
