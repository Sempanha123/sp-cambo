$ErrorActionPreference = "Stop"

Write-Host "Resetting frontend from pnpm/mixed install to npm..." -ForegroundColor Cyan

$paths = @("node_modules", ".nuxt", ".output")
foreach ($path in $paths) {
    if (Test-Path $path) {
        Remove-Item -Recurse -Force $path
    }
}

$files = @("pnpm-lock.yaml", "pnpm-workspace.yaml")
foreach ($file in $files) {
    if (Test-Path $file) {
        Remove-Item -Force $file
    }
}

npm cache verify
npm install

Write-Host "npm install complete. Next run: npm run typecheck" -ForegroundColor Green
