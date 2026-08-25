[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

function Need([string]$Command) {
    if (-not (Get-Command $Command -ErrorAction SilentlyContinue)) {
        throw "Required command '$Command' was not found in PATH."
    }
}

Need node
Need npm
Need pnpm
Need php
Need composer

$nodeRaw = (node --version).TrimStart('v')
$parts = $nodeRaw.Split('.')
$major = [int]$parts[0]
$minor = [int]$parts[1]
$nodeSupported = (($major -eq 24) -and ($minor -ge 15)) -or ($major -ge 26)

Write-Host "Node:     $(node --version)" -ForegroundColor $(if ($nodeSupported) { 'Green' } else { 'Red' })
Write-Host "npm:      $(npm --version)"
Write-Host "pnpm:     $(pnpm --version)"
Write-Host "PHP:      $(php -r 'echo PHP_VERSION;')"
Write-Host "Composer: $(composer --version --no-ansi)"

if (-not $nodeSupported) {
    throw "Unsupported Node version. SP Cambo's current dependency tree needs Node 24.15+ or Node 26+. Do not use Node 22 or Node 25."
}

Write-Host 'Toolchain version gate passed.' -ForegroundColor Green
