param([string]$ProjectRoot = "")
$ErrorActionPreference = "Stop"

$ScriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ScriptRoot)) { $ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path }
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) { $ProjectRoot = Split-Path -Parent $ScriptRoot }
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path

try {
    $r = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:3000/" -TimeoutSec 5
    Write-Host "[OK] Frontend is already running (HTTP $($r.StatusCode))." -ForegroundColor Green
    exit 0
} catch {
    if ($_.Exception.Response) {
        Write-Host "[OK] Frontend port is already serving HTTP." -ForegroundColor Green
        exit 0
    }
}

$dir = Join-Path $ProjectRoot "frontend"
if (-not (Test-Path -LiteralPath (Join-Path $dir "package.json"))) {
    throw "Frontend package.json not found: $dir"
}

Set-Location -LiteralPath $dir

Write-Host "==========================================" -ForegroundColor Magenta
Write-Host "SP CAMBO FRONTEND" -ForegroundColor Magenta
Write-Host "http://127.0.0.1:3000" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Magenta

if (-not (Test-Path -LiteralPath ".\node_modules")) {
    npm install
    if ($LASTEXITCODE -ne 0) { throw "Frontend npm install failed." }
}

npm run dev -- --host 127.0.0.1 --port 3000
