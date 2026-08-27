param(
    [switch]$Fresh
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$EnvFile = Join-Path $Backend '.env'

if (-not (Test-Path $EnvFile)) {
    Write-Host '[ERROR] backend/.env is missing. Copy backend/.env.example first and keep real credentials only in backend/.env.' -ForegroundColor Red
    exit 1
}

# Laravel Dotenv rejects unquoted values containing spaces. OmniRoute model names
# such as OpenAI Codex are valid, so normalize only the known model variables.
$envLines = Get-Content -LiteralPath $EnvFile
$changed = $false
$modelKeys = @('OMNIROUTE_MODEL', 'SP_CAMBO_DEMO_UPSTREAM_MODEL', 'ANTHROPIC_MODEL')
for ($i = 0; $i -lt $envLines.Count; $i++) {
    $line = $envLines[$i]
    foreach ($key in $modelKeys) {
        if ($line -match ('^' + [regex]::Escape($key) + '=(.+)$')) {
            $value = $Matches[1].Trim()
            if ($value -match '\s' -and -not (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $safe = $value.Replace('"', '\"')
                $envLines[$i] = $key + '="' + $safe + '"'
                $changed = $true
                Write-Host "[FIX] Quoted $key because its OmniRoute model name contains spaces." -ForegroundColor Yellow
            }
        }
    }
}
if ($changed) {
    Set-Content -LiteralPath $EnvFile -Value $envLines -Encoding UTF8
}

Push-Location $Backend
try {
    php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    if ($Fresh) {
        Write-Host '[WARNING] -Fresh deletes the local database schema/data before rebuilding the demo.' -ForegroundColor Yellow
        php artisan migrate:fresh --seed --force
    }
    else {
        php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        php artisan db:seed --force
    }

    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    php artisan system:check-access-allocation-schema
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    php artisan demo:status
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
