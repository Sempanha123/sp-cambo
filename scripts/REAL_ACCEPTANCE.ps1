[CmdletBinding()]
param(
    [string]$FrontendUrl = 'http://127.0.0.1:3000/',
    [string]$BackendUrl = 'http://127.0.0.1:8000',
    [string]$GatewayUrl = 'http://127.0.0.1:3010',
    [string]$KhqrUrl = 'http://127.0.0.1:3011',
    [switch]$RunBillableInference,
    [string]$ReportPath = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = (Resolve-Path (Join-Path $ScriptDir '..')).Path
if ([string]::IsNullOrWhiteSpace($ReportPath)) {
    $ReportPath = Join-Path $Root 'real-acceptance-report.json'
}

$Failures = [System.Collections.Generic.List[string]]::new()
$Checks = [System.Collections.Generic.List[object]]::new()

function Add-Check([string]$Name, [string]$State, [string]$Detail) {
    $Checks.Add([pscustomobject]@{ name = $Name; state = $State; detail = $Detail })
    $color = if ($State -eq 'PASS') { 'Green' } elseif ($State -eq 'SKIP') { 'Yellow' } else { 'Red' }
    Write-Host "$State`: $Name - $Detail" -ForegroundColor $color
}

function Probe([string]$Name, [string]$Url) {
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10
        if ([int]$r.StatusCode -ge 500) { throw "HTTP $($r.StatusCode)" }
        Add-Check $Name 'PASS' "HTTP $($r.StatusCode)"
    } catch {
        $status = $null
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) { $status = [int]$_.Exception.Response.StatusCode }
        if ($status -and $status -lt 500) {
            Add-Check $Name 'PASS' "reachable (HTTP $status)"
        } else {
            $Failures.Add("$Name $Url : $($_.Exception.Message)")
            Add-Check $Name 'FAIL' $_.Exception.Message
        }
    }
}

function Invoke-Json([string]$Method, [string]$Url, [hashtable]$Headers, [object]$Body = $null) {
    $params = @{
        Method = $Method
        Uri = $Url
        Headers = $Headers
        UseBasicParsing = $true
        TimeoutSec = 30
    }
    if ($null -ne $Body) {
        $params['ContentType'] = 'application/json'
        $params['Body'] = ($Body | ConvertTo-Json -Depth 20 -Compress)
    }
    return Invoke-WebRequest @params
}

function Require-HttpSuccess([string]$Name, [scriptblock]$Action) {
    try {
        $r = & $Action
        if ([int]$r.StatusCode -lt 200 -or [int]$r.StatusCode -ge 300) { throw "HTTP $($r.StatusCode)" }
        Add-Check $Name 'PASS' "HTTP $($r.StatusCode)"
        return $r
    } catch {
        $status = $null
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) { $status = [int]$_.Exception.Response.StatusCode }
        $detail = if ($status) { "HTTP $status" } else { $_.Exception.Message }
        $Failures.Add("$Name : $detail")
        Add-Check $Name 'FAIL' $detail
        return $null
    }
}

Write-Host '=== SP Cambo running-service acceptance ===' -ForegroundColor Cyan
Probe 'Frontend' $FrontendUrl
Probe 'Backend health' "$($BackendUrl.TrimEnd('/'))/api/v1/status"
Probe 'Gateway health' "$($GatewayUrl.TrimEnd('/'))/health"
Probe 'KHQR health' "$($KhqrUrl.TrimEnd('/'))/health"

# Optional credentialed acceptance. Put the key in an environment variable so it
# does not appear in PowerShell command history:
#   $env:SPCAMBO_ACCEPTANCE_API_KEY = 'sk-spc-...'
# Optional model:
#   $env:SPCAMBO_ACCEPTANCE_MODEL = 'your-public-alias'
$acceptanceKey = [string]$env:SPCAMBO_ACCEPTANCE_API_KEY
$acceptanceModel = [string]$env:SPCAMBO_ACCEPTANCE_MODEL

if (-not [string]::IsNullOrWhiteSpace($acceptanceKey)) {
    $authHeaders = @{ Authorization = "Bearer $acceptanceKey" }

    $modelsResponse = Require-HttpSuccess 'Gateway authenticated model listing' {
        Invoke-Json 'GET' "$($GatewayUrl.TrimEnd('/'))/v1/models" $authHeaders
    }
    Require-HttpSuccess 'Gateway authenticated key status' {
        Invoke-Json 'GET' "$($GatewayUrl.TrimEnd('/'))/v1/key/status" $authHeaders
    } | Out-Null
    $checkerBeforeResponse = Require-HttpSuccess 'Public no-login Key Checker' {
        Invoke-Json 'POST' "$($BackendUrl.TrimEnd('/'))/api/v1/keys/check" @{} @{ api_key = $acceptanceKey }
    }
    $checkerBeforeId = $null
    if ($null -ne $checkerBeforeResponse) {
        try {
            $checkerBefore = $checkerBeforeResponse.Content | ConvertFrom-Json
            if ($checkerBefore.data.recent_requests.Count -gt 0) { $checkerBeforeId = [string]$checkerBefore.data.recent_requests[0].request_id }
        } catch { }
    }

    # A clearly fake key verifies that the gateway rejects invalid credentials.
    try {
        Invoke-Json 'GET' "$($GatewayUrl.TrimEnd('/'))/v1/key/status" @{ Authorization = 'Bearer sk-spc-00000000000000000000000000000000' } | Out-Null
        $Failures.Add('Gateway invalid-key rejection unexpectedly returned success')
        Add-Check 'Gateway rejects invalid API key' 'FAIL' 'unexpected success'
    } catch {
        $status = $null
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) { $status = [int]$_.Exception.Response.StatusCode }
        if ($status -eq 401 -or $status -eq 403) {
            Add-Check 'Gateway rejects invalid API key' 'PASS' "HTTP $status"
        } else {
            $Failures.Add("Gateway invalid-key rejection returned unexpected status: $status")
            Add-Check 'Gateway rejects invalid API key' 'FAIL' "unexpected HTTP $status"
        }
    }

    if ($RunBillableInference) {
        if ([string]::IsNullOrWhiteSpace($acceptanceModel)) {
            $Failures.Add('RunBillableInference requires SPCAMBO_ACCEPTANCE_MODEL')
            Add-Check 'Billable inference' 'FAIL' 'SPCAMBO_ACCEPTANCE_MODEL is not configured'
        } else {
            Write-Host 'NOTICE: the next check may consume entitlement/credit through the configured real provider.' -ForegroundColor Yellow
            $inference = Require-HttpSuccess 'Real low-output streaming gateway inference' {
                Invoke-Json 'POST' "$($GatewayUrl.TrimEnd('/'))/v1/chat/completions" $authHeaders @{
                    model = $acceptanceModel
                    messages = @(@{ role = 'user'; content = 'Reply with only: OK' })
                    max_tokens = 8
                    stream = $true
                    stream_options = @{ include_usage = $true }
                }
            }
            if ($null -ne $inference) {
                if ([string]$inference.Content -match 'data:') {
                    Add-Check 'Streaming response framing' 'PASS' 'received SSE data frames'
                } else {
                    $Failures.Add('Streaming inference returned success without SSE data frames')
                    Add-Check 'Streaming response framing' 'FAIL' 'HTTP succeeded but SSE data frames were not found'
                }

                Require-HttpSuccess 'Key status after inference' {
                    Invoke-Json 'GET' "$($GatewayUrl.TrimEnd('/'))/v1/key/status" $authHeaders
                } | Out-Null

                $settled = $false
                for ($attempt = 1; $attempt -le 5 -and -not $settled; $attempt++) {
                    Start-Sleep -Seconds 2
                    try {
                        $afterResponse = Invoke-Json 'POST' "$($BackendUrl.TrimEnd('/'))/api/v1/keys/check" @{} @{ api_key = $acceptanceKey }
                        $after = $afterResponse.Content | ConvertFrom-Json
                        if ($after.data.recent_requests.Count -gt 0) {
                            $latest = $after.data.recent_requests[0]
                            $newRequest = [string]$latest.request_id -ne [string]$checkerBeforeId
                            $finalState = [string]$latest.state -eq 'settled' -or [string]$latest.status -eq 'success'
                            if ($newRequest -and $finalState -and $null -ne $latest.input_tokens -and $null -ne $latest.output_tokens -and $null -ne $latest.duration_ms) {
                                $settled = $true
                            }
                        }
                    } catch { }
                }
                if ($settled) {
                    Add-Check 'Settled usage visible in Key Checker' 'PASS' 'new request shows final token usage and duration'
                } else {
                    $Failures.Add('New streaming inference did not appear as settled final usage in Key Checker within the acceptance window')
                    Add-Check 'Settled usage visible in Key Checker' 'FAIL' 'no new settled request with final usage/duration after 10 seconds'
                }
            }
        }
    } else {
        Add-Check 'Real billable inference' 'SKIP' 'use -RunBillableInference only when you intentionally want a low-output real-provider request'
    }
} else {
    Add-Check 'Credentialed API acceptance' 'SKIP' 'set SPCAMBO_ACCEPTANCE_API_KEY in this PowerShell process; the script never prints it'
}

$report = [ordered]@{
    release = if (Test-Path (Join-Path $Root 'VERSION')) { (Get-Content (Join-Path $Root 'VERSION') -Raw).Trim() } else { $null }
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    billable_inference_requested = [bool]$RunBillableInference
    checks = @($Checks)
    failures = @($Failures)
    external_manual_acceptance = @(
        'Admin provider probe READY and public alias routes to intended private model',
        'Real low-value package order generates KHQR and remains unpaid before payment',
        'One real Bakong payment fulfills exactly once under refresh/retry/reconciliation',
        'Telegram Store purchase delivers the issued credential exactly once',
        'Streaming inference settles exact usage/charge/duration',
        'Playground daily quota -> explicit balance/redeem fallback works with real routing',
        'Telegram package announcement Buy button, mute and language flows work',
        'Backup and restore are proven against a non-production database'
    )
}
$report | ConvertTo-Json -Depth 20 | Set-Content -Path $ReportPath -Encoding UTF8
Write-Host "Acceptance report: $ReportPath" -ForegroundColor DarkGray

if ($Failures.Count -gt 0) {
    Write-Host "`nRunning-service acceptance FAILED." -ForegroundColor Red
    $Failures | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
    exit 1
}

Write-Host "`nAutomatable running-service acceptance PASS for the checks that were enabled." -ForegroundColor Green
Write-Host 'External money/Telegram/provider acceptance is intentionally not faked by this script.' -ForegroundColor Yellow
exit 0
