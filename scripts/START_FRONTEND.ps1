param([string]$ProjectRoot = "")
$ErrorActionPreference = "Stop"

$ScriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ScriptRoot)) { $ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path }
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) { $ProjectRoot = Split-Path -Parent $ScriptRoot }
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path

try {
    $r = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:3000/" -TimeoutSec 5
    if ([int]$r.StatusCode -lt 500) {
        Write-Host "[OK] Frontend is already running (HTTP $($r.StatusCode))." -ForegroundColor Green
        exit 0
    }
    Write-Host "[WARN] Frontend answered HTTP $($r.StatusCode); restarting is recommended." -ForegroundColor Yellow
} catch {
    if ($_.Exception.Response) {
        try {
            $s = [int]$_.Exception.Response.StatusCode
            if ($s -lt 500) {
                Write-Host "[OK] Frontend is already serving HTTP $s." -ForegroundColor Green
                exit 0
            }
            Write-Host "[WARN] Frontend is reachable but unhealthy (HTTP $s)." -ForegroundColor Yellow
        } catch {}
    }
}

$dir = Join-Path $ProjectRoot "frontend"
if (-not (Test-Path -LiteralPath (Join-Path $dir "package.json"))) { throw "Frontend package.json not found: $dir" }
Set-Location -LiteralPath $dir

Write-Host "==========================================" -ForegroundColor Magenta
Write-Host "SP CAMBO FRONTEND" -ForegroundColor Magenta
Write-Host "http://127.0.0.1:3000" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Magenta

if (-not (Test-Path -LiteralPath ".\node_modules")) {
    if (-not (Test-Path -LiteralPath ".\package-lock.json")) { throw "frontend/package-lock.json is required for reproducible npm install." }
    npm ci --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw "Frontend npm ci failed." }
}

npm run dev -- --host 127.0.0.1 --port 3000
