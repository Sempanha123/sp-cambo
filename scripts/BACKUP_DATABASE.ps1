[CmdletBinding()]
param(
    [string]$EnvFile = '',
    [string]$OutputDirectory = ''
)
$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
if ([string]::IsNullOrWhiteSpace($EnvFile)) { $EnvFile = Join-Path $Root 'backend\.env' }
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) { $OutputDirectory = Join-Path $Root 'backups' }
if (-not (Get-Command mysqldump -ErrorAction SilentlyContinue)) { throw 'mysqldump was not found in PATH.' }
if (-not (Test-Path $EnvFile)) { throw "Environment file not found: $EnvFile" }

function Read-DotEnv([string]$Path) {
    $m=@{}; foreach($line in Get-Content $Path){ $t=$line.Trim(); if($t -eq '' -or $t.StartsWith('#') -or -not $t.Contains('=')){continue}; $p=$t.Split('=',2); $m[$p[0].Trim()]=$p[1].Trim().Trim('"').Trim("'") }; return $m
}
$e = Read-DotEnv $EnvFile
$db = [string]$e['DB_DATABASE']; $user = [string]$e['DB_USERNAME']; $pass = [string]$e['DB_PASSWORD']; $host = [string]$e['DB_HOST']; $port = [string]$e['DB_PORT']
if ([string]::IsNullOrWhiteSpace($db) -or [string]::IsNullOrWhiteSpace($user)) { throw 'DB_DATABASE and DB_USERNAME are required.' }
if ([string]::IsNullOrWhiteSpace($host)) { $host='127.0.0.1' }; if ([string]::IsNullOrWhiteSpace($port)) { $port='3306' }
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$out = Join-Path $OutputDirectory "spcambo-$stamp.sql"
$old = $env:MYSQL_PWD
try {
    $env:MYSQL_PWD = $pass
    & mysqldump --host=$host --port=$port --user=$user --single-transaction --routines --triggers --default-character-set=utf8mb4 $db | Set-Content -Encoding utf8 $out
    if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE" }
} finally { $env:MYSQL_PWD = $old }
if (-not (Test-Path $out) -or (Get-Item $out).Length -eq 0) { throw 'Backup file is empty.' }
Write-Host "Backup created: $out" -ForegroundColor Green
