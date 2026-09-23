param(
    [string]$BaseUrl = "https://sp-cambo.store/api/v1",
    [string]$CustomerId = "2",
    [Int64]$Units = 1000000,
    [string]$IdempotencyKey = "demo-auto-customer-2-1m-v1"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($env:SP_RESELLER_KEY)) {
    throw 'Set $env:SP_RESELLER_KEY to a fresh sk-spm- management key first.'
}

$headers = @{
    Authorization = "Bearer $env:SP_RESELLER_KEY"
    Accept = "application/json"
}

Write-Host "`n[1] Inventory (this also auto-adopts old untouched purchases)" -ForegroundColor Cyan
$inventory = Invoke-RestMethod `
    -Uri "$BaseUrl/reseller-management/inventory" `
    -Headers $headers

$inventory | ConvertTo-Json -Depth 20

if (-not $inventory.data -or $inventory.data.Count -eq 0) {
    throw "Inventory is still empty. Confirm the reseller profile is ACTIVE and the paid lot is fully unused and not expired."
}

$lot = $inventory.data |
    Where-Object {
        $_.billing_mode -eq "TOKEN_QUOTA" -and
        ([Int64]$_.remaining_units - [Int64]$_.reserved_units) -ge $Units -and
        $_.allowed_model_aliases.Count -gt 0
    } |
    Select-Object -First 1

if (-not $lot) {
    throw "No TOKEN_QUOTA reseller lot has enough available units."
}

$model = [string]$lot.allowed_model_aliases[0]
Write-Host "`nUsing model: $model"
Write-Host "Available before: $([Int64]$lot.remaining_units - [Int64]$lot.reserved_units)"

Write-Host "`n[2] Customers" -ForegroundColor Cyan
$customers = Invoke-RestMethod `
    -Uri "$BaseUrl/reseller-management/customers" `
    -Headers $headers
$customers | ConvertTo-Json -Depth 10

$customer = $customers.data |
    Where-Object { [string]$_.id -eq $CustomerId } |
    Select-Object -First 1

if (-not $customer) {
    throw "Managed customer ID $CustomerId was not found."
}

Write-Host "`n[3] Allocate $Units tokens to customer $CustomerId" -ForegroundColor Cyan
$body = @{
    billing_mode = "TOKEN_QUOTA"
    public_model_alias = $model
    units = $Units
    idempotency_key = $IdempotencyKey
    reason = "Automatic reseller API demo allocation."
} | ConvertTo-Json

$result = Invoke-RestMethod `
    -Method POST `
    -Uri "$BaseUrl/reseller-management/customers/$CustomerId/allocations" `
    -Headers $headers `
    -ContentType "application/json" `
    -Body $body

$result | ConvertTo-Json -Depth 10

Write-Host "`n[4] Inventory after allocation" -ForegroundColor Cyan
$after = Invoke-RestMethod `
    -Uri "$BaseUrl/reseller-management/inventory" `
    -Headers $headers

$after | ConvertTo-Json -Depth 20

Write-Host "`nDONE" -ForegroundColor Green
Write-Host "Re-running with the same idempotency key does not allocate twice."
