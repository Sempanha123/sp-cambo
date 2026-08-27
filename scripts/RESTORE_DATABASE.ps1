[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$BackupFile,
    [string]$EnvFile = '',
    [Parameter(Mandatory=$true)][ValidateSet('RESTORE')][string]$ConfirmRestore
)
$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
if ([string]::IsNullOrWhiteSpace($EnvFile)) { $EnvFile = Join-Path $Root 'backend\.env' }
if (-not (Get-Command mysql -ErrorAction SilentlyContinue)) { throw 'mysql client was not found in PATH.' }
if (-not (Test-Path $BackupFile)) { throw "Backup file not found: $BackupFile" }
if (-not (Test-Path $EnvFile)) { throw "Environment file not found: $EnvFile" }
function Read-DotEnv([string]$Path) { $m=@{}; foreach($line in Get-Content $Path){ $t=$line.Trim(); if($t -eq '' -or $t.StartsWith('#') -or -not $t.Contains('=')){continue}; $p=$t.Split('=',2); $m[$p[0].Trim()]=$p[1].Trim().Trim('"').Trim("'") }; return $m }
$e=Read-DotEnv $EnvFile; $db=[string]$e['DB_DATABASE']; $user=[string]$e['DB_USERNAME']; $pass=[string]$e['DB_PASSWORD']; $host=[string]$e['DB_HOST']; $port=[string]$e['DB_PORT']
if ([string]::IsNullOrWhiteSpace($db) -or [string]::IsNullOrWhiteSpace($user)) { throw 'DB_DATABASE and DB_USERNAME are required.' }
if ([string]::IsNullOrWhiteSpace($host)){ $host='127.0.0.1' }; if ([string]::IsNullOrWhiteSpace($port)){ $port='3306' }
Write-Host "Restoring into database '$db'. Test restores on staging/non-production first." -ForegroundColor Yellow
$old=$env:MYSQL_PWD
try {
    $env:MYSQL_PWD=$pass
    Get-Content -Raw $BackupFile | & mysql --host=$host --port=$port --user=$user --default-character-set=utf8mb4 $db
    if ($LASTEXITCODE -ne 0) { throw "mysql restore failed with exit code $LASTEXITCODE" }
} finally { $env:MYSQL_PWD=$old }
Write-Host 'Restore command completed.' -ForegroundColor Green
