param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path

function Require-File {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Required file is missing: $Path"
    }
}

function Read-Text {
    param([string]$Path)

    return [System.IO.File]::ReadAllText($Path)
}

function Assert-Contains {
    param(
        [string]$Content,
        [string]$Needle,
        [string]$Label
    )

    if (-not $Content.Contains($Needle)) {
        throw "R6.2 source verification failed: $Label"
    }

    Write-Host "[OK] $Label"
}

function Assert-NotContains {
    param(
        [string]$Content,
        [string]$Needle,
        [string]$Label
    )

    if ($Content.Contains($Needle)) {
        throw "R6.2 source verification failed: $Label"
    }

    Write-Host "[OK] $Label"
}

$routePools = Join-Path $project 'frontend\app\pages\admin\route-pools.vue'
$apiKeyDetails = Join-Path $project 'frontend\app\pages\dashboard\api-keys\[id].vue'
$playground = Join-Path $project 'frontend\app\pages\dashboard\playground.vue'
$telegramAudit = Join-Path $project 'backend\tests\Feature\Feature\Api\V1\TelegramDeliveryAuditTest.php'
$eslintTarget = Join-Path $project 'frontend\eslint.config.mjs'

foreach ($path in @(
    $routePools,
    $apiKeyDetails,
    $playground,
    $telegramAudit,
    $eslintTarget
)) {
    Require-File $path
}

Write-Host ''
Write-Host '=== SP Cambo CI Fix R6.2 ==='
Write-Host 'R6.2 resumes safely after the R6.1 partial apply.'
Write-Host ''

$routePoolsContent = Read-Text $routePools
$apiKeyContent = Read-Text $apiKeyDetails
$playgroundContent = Read-Text $playground
$telegramContent = Read-Text $telegramAudit
$eslintContent = Read-Text $eslintTarget

Assert-Contains `
    -Content $routePoolsContent `
    -Needle "'model: ' + detail.model.public_alias" `
    -Label 'route-pools parser fix'

Assert-NotContains `
    -Content $apiKeyContent `
    -Needle 'const formatSpCredits =' `
    -Label 'unused formatSpCredits removed'

Assert-NotContains `
    -Content $playgroundContent `
    -Needle 'const selectedBalance =' `
    -Label 'unused selectedBalance removed'

Assert-Contains `
    -Content $telegramContent `
    -Needle 'sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array' `
    -Label 'Telegram fake returns array'

Assert-Contains `
    -Content $telegramContent `
    -Needle "'message_id' => count(`$this->messages)" `
    -Label 'Telegram fake returns message_id'

Assert-Contains `
    -Content $eslintContent `
    -Needle "'vue/max-attributes-per-line': 'off'" `
    -Label 'ESLint max attributes formatting rule disabled'

Assert-Contains `
    -Content $eslintContent `
    -Needle "'vue/singleline-html-element-content-newline': 'off'" `
    -Label 'ESLint single-line content formatting rule disabled'

Assert-Contains `
    -Content $eslintContent `
    -Needle "'@stylistic/no-multiple-empty-lines': 'off'" `
    -Label 'ESLint multiple blank-lines formatting rule disabled'

Write-Host ''
Write-Host '[PASS] R6.2 source verification completed.'
Write-Host 'Nothing else needs to be copied: frontend\eslint.config.mjs is already in its final project location.'
Write-Host ''
Write-Host 'Next run:'
Write-Host '  powershell -ExecutionPolicy Bypass -File .\VERIFY_CI_FIX_R6_2.ps1'
