param(
  [string]$ProjectRoot = ".",
  [string]$RemoteUrl = "https://github.com/Sempanha123/sp-cambo.git"
)
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path $ProjectRoot)
if (-not (Test-Path '.git')) { git init }
git branch -M main
$existing = git remote 2>$null
if ($existing -contains 'origin') { git remote set-url origin $RemoteUrl } else { git remote add origin $RemoteUrl }
git add .
if (git status --porcelain) { git commit -m "Import latest SP Cambo V2 source" }
git push -u origin main
