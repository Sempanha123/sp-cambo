param(
  [string]$ProjectRoot = ".",
  [string]$RemoteUrl = "https://github.com/Sempanha123/sp-cambo.git",
  [string]$CommitMessage = "V9: harden automatic payment recovery and Telegram relinking"
)

$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path $ProjectRoot)

function Run-Git {
  param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Args)
  & git @Args
  if ($LASTEXITCODE -ne 0) {
    throw "git $($Args -join ' ') failed with exit code $LASTEXITCODE"
  }
}

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
  throw 'Git is not installed or not available on PATH.'
}

$freshRepo = -not (Test-Path '.git')

if ($freshRepo) {
  Run-Git init
}

$remotes = @(& git remote 2>$null)
if ($remotes -contains 'origin') {
  Run-Git remote set-url origin $RemoteUrl
} else {
  Run-Git remote add origin $RemoteUrl
}

# Fetch first so a newly extracted Progress ZIP is based on the current remote
# history instead of creating an unrelated root commit that GitHub rejects.
Run-Git fetch origin main

if ($freshRepo) {
  # Move HEAD/index to the current remote base without touching the extracted
  # Progress working files. `git add -A` below then records only the real delta.
  Run-Git reset --mixed origin/main
}

Run-Git branch -M main
Run-Git add -A

$changes = & git status --porcelain
if ($LASTEXITCODE -ne 0) {
  throw 'Unable to inspect Git working tree.'
}

if ($changes) {
  Run-Git commit -m $CommitMessage
} else {
  Write-Host 'No local changes to commit.'
}

# --force-with-lease is intentionally NOT used here. Progress updates should
# fast-forward the existing remote history. If somebody pushed after our fetch,
# Git refuses rather than overwriting their work.
Run-Git push -u origin main

Write-Host ''
Write-Host 'SP Cambo pushed successfully.' -ForegroundColor Green
Run-Git status --short --branch
