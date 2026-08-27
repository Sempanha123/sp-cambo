[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
$Frontend = Join-Path $Root 'frontend'

Write-Host 'SP Cambo frontend dependency recovery' -ForegroundColor Cyan
Write-Host "Frontend: $Frontend"

if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'node was not found in PATH.' }
if (-not (Get-Command npm -ErrorAction SilentlyContinue)) { throw 'npm was not found in PATH.' }

$raw = (node --version).TrimStart('v')
$parts = $raw.Split('.')
$major = [int]$parts[0]
$minor = [int]$parts[1]
if (-not ((($major -eq 24) -and ($minor -ge 15)) -or ($major -ge 26))) {
    throw "Unsupported Node $(node --version). Use Node 24.15+ or Node 26+."
}

if ($env:OS -eq 'Windows_NT') {
    $normalized = [IO.Path]::GetFullPath($Frontend).TrimEnd('\')
    $matches = @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $command = [string]$_.CommandLine
        $command -and $command.IndexOf($normalized, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and
            ($command -match '(?i)nuxt(.mjs|\\bin\\nuxt)?\s+dev|nuxi(.mjs)?\s+dev')
    })
    foreach ($process in $matches) {
        Write-Host "Stopping Nuxt dev process PID $($process.ProcessId)..." -ForegroundColor Yellow
        Stop-Process -Id $process.ProcessId -Force
    }
    if ($matches.Count -gt 0) { Start-Sleep -Milliseconds 1500 }
}

Push-Location $Frontend
try {
    if (Test-Path 'node_modules') {
        Write-Host 'Removing the partial node_modules tree...' -ForegroundColor Yellow
        $removed = $false
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                Remove-Item 'node_modules' -Recurse -Force -ErrorAction Stop
                $removed = $true
                break
            }
            catch {
                if ($attempt -eq 3) { throw }
                Write-Host "node_modules is still locked; retrying cleanup ($attempt/3)..." -ForegroundColor Yellow
                Start-Sleep -Seconds 2
            }
        }
        if (-not $removed -and (Test-Path 'node_modules')) {
            throw 'Could not remove frontend/node_modules after stopping the project Nuxt process.'
        }
    }
    npm cache verify
    if ($LASTEXITCODE -ne 0) { throw "npm cache verify failed with exit code $LASTEXITCODE" }
    npm ci --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw "npm ci failed with exit code $LASTEXITCODE" }
    node -e "require.resolve('jiti'); console.log('jiti resolution OK')"
    if ($LASTEXITCODE -ne 0) { throw 'Frontend dependency sanity failed.' }
    Write-Host 'Frontend dependencies recovered. You can run npm run dev now.' -ForegroundColor Green
} finally {
    Pop-Location
}
