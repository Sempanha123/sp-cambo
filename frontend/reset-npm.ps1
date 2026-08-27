$ErrorActionPreference = 'Stop'
Write-Host 'Resetting frontend to the release npm lockfile...' -ForegroundColor Cyan
$paths = @('node_modules', '.nuxt', '.output')
foreach ($path in $paths) { if (Test-Path $path) { Remove-Item -Recurse -Force $path } }
$files = @('pnpm-lock.yaml', 'pnpm-workspace.yaml')
foreach ($file in $files) { if (Test-Path $file) { Remove-Item -Force $file } }
if (-not (Test-Path 'package-lock.json')) { throw 'package-lock.json is required.' }
npm cache verify
npm ci --no-audit --no-fund
npm run postinstall
Write-Host 'Frontend npm reset complete. Next: npm run lint; npm run typecheck; npm test; npm run build' -ForegroundColor Green
