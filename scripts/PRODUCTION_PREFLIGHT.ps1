[CmdletBinding()]
param(
    [string]$BackendEnv = '',
    [string]$FrontendEnv = '',
    [string]$GatewayEnv = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
if ([string]::IsNullOrWhiteSpace($BackendEnv)) { $BackendEnv = Join-Path $Root 'backend\.env' }
if ([string]::IsNullOrWhiteSpace($FrontendEnv)) { $FrontendEnv = Join-Path $Root 'frontend\.env' }
if ([string]::IsNullOrWhiteSpace($GatewayEnv)) { $GatewayEnv = Join-Path $Root 'gateway\.env' }

$Failures = [System.Collections.Generic.List[string]]::new()
$Warnings = [System.Collections.Generic.List[string]]::new()

function Read-DotEnv([string]$Path) {
    $map = @{}
    if (-not (Test-Path $Path)) { return $map }
    foreach ($line in Get-Content $Path) {
        $t = $line.Trim()
        if ($t -eq '' -or $t.StartsWith('#') -or -not $t.Contains('=')) { continue }
        $p = $t.Split('=', 2)
        $map[$p[0].Trim()] = $p[1].Trim().Trim('"').Trim("'")
    }
    return $map
}

function Value([hashtable]$Map, [string]$Name) {
    if (-not $Map.ContainsKey($Name)) { return '' }
    return [string]$Map[$Name]
}

function Require-Value([hashtable]$Map, [string]$Name, [string]$Scope) {
    $value = Value $Map $Name
    if ([string]::IsNullOrWhiteSpace($value)) { $Failures.Add("$Scope missing $Name") }
    else { Write-Host "OK: $Scope $Name configured" -ForegroundColor Green }
}

function Require-StrongSecret([hashtable]$Map, [string]$Name, [string]$Scope, [int]$MinimumLength = 32) {
    $value = Value $Map $Name
    if ([string]::IsNullOrWhiteSpace($value)) {
        $Failures.Add("$Scope missing $Name")
        return
    }
    $placeholder = $value.ToLowerInvariant()
    if ($value.Length -lt $MinimumLength -or $placeholder.Contains('changeme') -or $placeholder.Contains('replace-me') -or $placeholder.Contains('example-secret')) {
        $Failures.Add("$Scope $Name must be a non-placeholder secret with at least $MinimumLength characters")
        return
    }
    Write-Host "OK: $Scope $Name has production-length secret material" -ForegroundColor Green
}

function Require-Exact([hashtable]$Map, [string]$Name, [string]$Expected, [string]$Scope) {
    $value = Value $Map $Name
    if ($value.ToLowerInvariant() -ne $Expected.ToLowerInvariant()) { $Failures.Add("$Scope $Name must be $Expected") }
    else { Write-Host "OK: $Scope $Name=$Expected" -ForegroundColor Green }
}

function Require-Https([hashtable]$Map, [string]$Name, [string]$Scope) {
    $value = Value $Map $Name
    $uri = $null
    if ([string]::IsNullOrWhiteSpace($value) -or -not [Uri]::TryCreate($value, [UriKind]::Absolute, [ref]$uri) -or $uri.Scheme -ne 'https') {
        $Failures.Add("$Scope $Name must be an absolute HTTPS URL")
    } else {
        Write-Host "OK: $Scope $Name uses HTTPS" -ForegroundColor Green
    }
}

function Require-CorsOrigins([hashtable]$Map) {
    $raw = Value $Map 'CORS_ALLOWED_ORIGINS'
    if ([string]::IsNullOrWhiteSpace($raw)) {
        $Failures.Add('backend missing CORS_ALLOWED_ORIGINS')
        return
    }
    $origins = @($raw.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
    if ($origins.Count -eq 0 -or $origins -contains '*') {
        $Failures.Add('backend CORS_ALLOWED_ORIGINS must contain explicit HTTPS origins and must not contain *')
        return
    }
    foreach ($origin in $origins) {
        $uri = $null
        if (-not [Uri]::TryCreate($origin, [UriKind]::Absolute, [ref]$uri) -or $uri.Scheme -ne 'https' -or $uri.PathAndQuery -ne '/') {
            $Failures.Add("backend CORS_ALLOWED_ORIGINS contains a non-origin/non-HTTPS value: $origin")
        }
    }
    if ($Failures | Where-Object { $_ -like 'backend CORS_ALLOWED_ORIGINS*' }) { return }
    Write-Host 'OK: backend CORS_ALLOWED_ORIGINS contains explicit HTTPS origin(s)' -ForegroundColor Green
}

function Require-SameSecret([hashtable]$Left, [string]$LeftName, [string]$LeftScope, [hashtable]$Right, [string]$RightName, [string]$RightScope) {
    $a = Value $Left $LeftName
    $b = Value $Right $RightName
    if ([string]::IsNullOrWhiteSpace($a) -or [string]::IsNullOrWhiteSpace($b)) { return }
    if ($a -cne $b) { $Failures.Add("$LeftScope $LeftName must exactly match $RightScope $RightName") }
    else { Write-Host "OK: $LeftScope/$RightScope shared $LeftName matches" -ForegroundColor Green }
}

$backend = Read-DotEnv $BackendEnv
$frontend = Read-DotEnv $FrontendEnv
$gateway = Read-DotEnv $GatewayEnv

Write-Host '=== SP Cambo production configuration preflight ===' -ForegroundColor Cyan
Write-Host 'Secret values are never printed by this script.' -ForegroundColor DarkGray

if ($backend.Count -eq 0) { $Failures.Add("Backend environment file not found/empty: $BackendEnv") }
if ($frontend.Count -eq 0) { $Failures.Add("Frontend environment file not found/empty: $FrontendEnv") }
if ($gateway.Count -eq 0) { $Failures.Add("Gateway environment file not found/empty: $GatewayEnv") }

# Laravel/control plane
Require-Exact $backend 'APP_ENV' 'production' 'backend'
Require-Exact $backend 'APP_DEBUG' 'false' 'backend'
Require-Https $backend 'APP_URL' 'backend'
Require-Https $backend 'FRONTEND_URL' 'backend'
Require-Value $backend 'APP_KEY' 'backend'
Require-Value $backend 'DB_DATABASE' 'backend'
Require-Value $backend 'DB_USERNAME' 'backend'
Require-StrongSecret $backend 'SP_CAMBO_API_KEY_LOOKUP_SECRET' 'backend'
Require-StrongSecret $backend 'SP_CAMBO_MANAGEMENT_KEY_LOOKUP_SECRET' 'backend'
Require-StrongSecret $backend 'SP_CAMBO_REDEEM_CODE_LOOKUP_SECRET' 'backend'
Require-StrongSecret $backend 'SP_CAMBO_INTERNAL_GATEWAY_SECRET' 'backend'
Require-Https $backend 'BAKONG_BASE_URL' 'backend'
Require-Value $backend 'BAKONG_TOKEN' 'backend'
Require-Value $backend 'BAKONG_ACCOUNT_ID' 'backend'
Require-Value $backend 'BAKONG_MERCHANT_NAME' 'backend'
Require-Value $backend 'BAKONG_KHQR_GENERATOR_URL' 'backend'
Require-StrongSecret $backend 'BAKONG_KHQR_GENERATOR_SECRET' 'backend'
Require-Value $backend 'TELEGRAM_BOT_TOKEN' 'backend'
Require-Value $backend 'TELEGRAM_BOT_USERNAME' 'backend'
Require-StrongSecret $backend 'TELEGRAM_WEBHOOK_SECRET' 'backend'
Require-StrongSecret $backend 'TELEGRAM_LINK_SECRET' 'backend'
$purchaseFeedEnabled = (Value $backend 'TELEGRAM_PURCHASE_FEED_ENABLED').ToLowerInvariant()
$purchaseFeedSubscribersEnabled = (Value $backend 'TELEGRAM_PURCHASE_FEED_SUBSCRIBERS_ENABLED').ToLowerInvariant()
$feedOn = $purchaseFeedEnabled -in @('', 'true', '1', 'yes', 'on')
$subscriberFeedOn = $purchaseFeedSubscribersEnabled -in @('', 'true', '1', 'yes', 'on')
$feedTargets = @((Value $backend 'TELEGRAM_PURCHASE_FEED_CHAT_IDS').Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })

if ($feedTargets.Count -gt 0) {
    $badFeedTargets = @($feedTargets | Where-Object { $_ -notmatch '^(@[A-Za-z0-9_]{5,}|-?[0-9]+)$' })
    if ($badFeedTargets.Count -gt 0) {
        $Failures.Add('backend TELEGRAM_PURCHASE_FEED_CHAT_IDS must use numeric Telegram chat/channel IDs or @channelusername values')
    } else {
        Write-Host "OK: Telegram purchase activity has $($feedTargets.Count) configured channel/group/feed target(s)" -ForegroundColor Green
    }
}

if ($feedOn -and $feedTargets.Count -eq 0 -and -not $subscriberFeedOn) {
    $Failures.Add('Telegram purchase activity is enabled but has no destination. Configure TELEGRAM_PURCHASE_FEED_CHAT_IDS or enable TELEGRAM_PURCHASE_FEED_SUBSCRIBERS_ENABLED')
} elseif ($subscriberFeedOn) {
    Write-Host 'OK: opted-in Telegram Store users will receive masked NEW ORDER activity messages' -ForegroundColor Green
} elseif (-not $feedOn) {
    $Warnings.Add('Telegram public purchase activity feed is disabled.')
}

$feedLocale = (Value $backend 'TELEGRAM_PURCHASE_FEED_LOCALE').ToLowerInvariant()
if (-not [string]::IsNullOrWhiteSpace($feedLocale) -and $feedLocale -notin @('en', 'km', 'both')) {
    $Failures.Add('backend TELEGRAM_PURCHASE_FEED_LOCALE must be en, km, or both')
}
Require-Https $backend 'SP_CAMBO_PUBLIC_GATEWAY_BASE_URL' 'backend'
Require-Exact $backend 'SESSION_ENCRYPT' 'true' 'backend'
Require-Exact $backend 'SESSION_SECURE_COOKIE' 'true' 'backend'
Require-Exact $backend 'SESSION_HTTP_ONLY' 'true' 'backend'
Require-CorsOrigins $backend

$sessionSameSite = (Value $backend 'SESSION_SAME_SITE').ToLowerInvariant()
if ($sessionSameSite -notin @('lax', 'strict')) { $Failures.Add('backend SESSION_SAME_SITE must be lax or strict in production') } else { Write-Host "OK: backend SESSION_SAME_SITE=$sessionSameSite" -ForegroundColor Green }
$queueConnection = (Value $backend 'QUEUE_CONNECTION').ToLowerInvariant()
if ([string]::IsNullOrWhiteSpace($queueConnection) -or $queueConnection -eq 'sync') { $Failures.Add('backend QUEUE_CONNECTION must use a durable asynchronous driver in production') } else { Write-Host "OK: backend QUEUE_CONNECTION=$queueConnection" -ForegroundColor Green }
$logLevel = (Value $backend 'LOG_LEVEL').ToLowerInvariant()
if ($logLevel -eq 'debug' -or [string]::IsNullOrWhiteSpace($logLevel)) { $Failures.Add('backend LOG_LEVEL must not be debug/empty in production') } else { Write-Host "OK: backend LOG_LEVEL=$logLevel" -ForegroundColor Green }
$mailer = (Value $backend 'MAIL_MAILER').ToLowerInvariant()
if ([string]::IsNullOrWhiteSpace($mailer) -or $mailer -in @('log', 'array')) { $Failures.Add('backend MAIL_MAILER must deliver real password-reset mail in production (not log/array)') } else { Write-Host "OK: backend MAIL_MAILER=$mailer" -ForegroundColor Green }
Require-Value $backend 'MAIL_FROM_ADDRESS' 'backend'

$googleClient = Value $backend 'GOOGLE_CLIENT_ID'
$googleSecret = Value $backend 'GOOGLE_CLIENT_SECRET'
if (-not [string]::IsNullOrWhiteSpace($googleClient) -or -not [string]::IsNullOrWhiteSpace($googleSecret)) {
    Require-Value $backend 'GOOGLE_CLIENT_ID' 'backend'
    Require-StrongSecret $backend 'GOOGLE_CLIENT_SECRET' 'backend' 16
    Require-Https $backend 'GOOGLE_REDIRECT_URI' 'backend'
} else {
    $Warnings.Add('Google OAuth credentials are not configured. Google sign-in will remain unavailable until both credentials and an HTTPS redirect URI are supplied.')
}

$releaseFile = Join-Path $Root 'VERSION'
if (Test-Path $releaseFile) {
    $expectedRelease = (Get-Content $releaseFile -Raw).Trim()
    $configuredRelease = Value $backend 'SP_CAMBO_RELEASE'
    if ($configuredRelease -ne $expectedRelease) { $Failures.Add("backend SP_CAMBO_RELEASE must match VERSION ($expectedRelease)") }
    else { Write-Host "OK: backend SP_CAMBO_RELEASE matches VERSION" -ForegroundColor Green }
}

# Nuxt public runtime configuration. This is intentionally the actual variable
# consumed by nuxt.config.ts, not the obsolete NUXT_PUBLIC_API_BASE spelling.
Require-Https $frontend 'NUXT_PUBLIC_API_BASE_URL' 'frontend'
Require-Https $frontend 'NUXT_PUBLIC_INFERENCE_ROOT_URL' 'frontend'
Require-Https $frontend 'NUXT_PUBLIC_SITE_URL' 'frontend'
Require-Value $frontend 'NUXT_PUBLIC_TELEGRAM_BOT_USERNAME' 'frontend'
$supportUrl = Value $frontend 'NUXT_PUBLIC_SUPPORT_URL'
if (-not [string]::IsNullOrWhiteSpace($supportUrl)) {
    $supportLooksLikeEmail = $supportUrl -match '^[^\s@]+@[^\s@]+\.[^\s@]+$'
    $supportLooksLikeMailto = $supportUrl.ToLowerInvariant().StartsWith('mailto:')
    $supportLooksLikeHttps = $supportUrl.ToLowerInvariant().StartsWith('https://')
    if (-not ($supportLooksLikeEmail -or $supportLooksLikeMailto -or $supportLooksLikeHttps)) {
        $Failures.Add('frontend NUXT_PUBLIC_SUPPORT_URL must be an email, mailto:, or HTTPS URL in production')
    } else { Write-Host 'OK: frontend support channel is publishable' -ForegroundColor Green }
} else {
    $Warnings.Add('NUXT_PUBLIC_SUPPORT_URL is not configured; customers will have docs/status self-service but no published human-support link.')
}

$frontendSite = (Value $frontend 'NUXT_PUBLIC_SITE_URL').TrimEnd('/')
$backendFrontend = (Value $backend 'FRONTEND_URL').TrimEnd('/')
$corsOrigins = @((Value $backend 'CORS_ALLOWED_ORIGINS').Split(',') | ForEach-Object { $_.Trim().TrimEnd('/') } | Where-Object { $_ -ne '' })
if ($frontendSite -ne '' -and $corsOrigins -notcontains $frontendSite) { $Failures.Add('backend CORS_ALLOWED_ORIGINS must include frontend NUXT_PUBLIC_SITE_URL') }
elseif ($frontendSite -ne '') { Write-Host 'OK: frontend site origin is allowed by backend CORS' -ForegroundColor Green }
if ($frontendSite -ne '' -and $backendFrontend -ne '' -and $frontendSite -cne $backendFrontend) {
    $Failures.Add('backend FRONTEND_URL must exactly match frontend NUXT_PUBLIC_SITE_URL')
} elseif ($frontendSite -ne '') { Write-Host 'OK: backend/frontend public site origin matches' -ForegroundColor Green }

$publicGateway = (Value $backend 'SP_CAMBO_PUBLIC_GATEWAY_BASE_URL').TrimEnd('/')
$frontendGateway = (Value $frontend 'NUXT_PUBLIC_INFERENCE_ROOT_URL').TrimEnd('/')
if ($publicGateway -ne '' -and $frontendGateway -ne '' -and $publicGateway -cne $frontendGateway) {
    $Failures.Add('backend SP_CAMBO_PUBLIC_GATEWAY_BASE_URL must exactly match frontend NUXT_PUBLIC_INFERENCE_ROOT_URL')
} elseif ($publicGateway -ne '') { Write-Host 'OK: backend/frontend public inference origin matches' -ForegroundColor Green }

# Inference gateway
Require-StrongSecret $gateway 'SP_CAMBO_INTERNAL_GATEWAY_SECRET' 'gateway'
Require-Value $gateway 'CONTROL_PLANE_BASE_URL' 'gateway'
Require-Exact $gateway 'GATEWAY_RATE_STORE' 'redis' 'gateway'
Require-Value $gateway 'REDIS_URL' 'gateway'
Require-StrongSecret $gateway 'BAKONG_KHQR_GENERATOR_SECRET' 'gateway'

$gatewayHost = Value $gateway 'GATEWAY_HOST'
if ([string]::IsNullOrWhiteSpace($gatewayHost)) {
    $Failures.Add('gateway missing GATEWAY_HOST')
} elseif ($gatewayHost -notin @('127.0.0.1', 'localhost', '0.0.0.0', '::1')) {
    $Warnings.Add('Gateway GATEWAY_HOST is unusual; verify the binding is intentionally protected by your reverse proxy/firewall.')
} else {
    Write-Host "OK: gateway GATEWAY_HOST=$gatewayHost" -ForegroundColor Green
}

Require-SameSecret $backend 'SP_CAMBO_INTERNAL_GATEWAY_SECRET' 'backend' $gateway 'SP_CAMBO_INTERNAL_GATEWAY_SECRET' 'gateway'
Require-SameSecret $backend 'BAKONG_KHQR_GENERATOR_SECRET' 'backend' $gateway 'BAKONG_KHQR_GENERATOR_SECRET' 'gateway'

foreach ($warning in $Warnings) { Write-Host "WARN: $warning" -ForegroundColor Yellow }

if ($Failures.Count -gt 0) {
    Write-Host "`nProduction preflight FAILED ($($Failures.Count)):" -ForegroundColor Red
    $Failures | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
    exit 1
}

Write-Host "`nProduction configuration preflight PASS. No external transaction was performed." -ForegroundColor Green
exit 0
