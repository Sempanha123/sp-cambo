SP CAMBO - AUTO RESELLER STOCK (COMPLETE)

WHAT THIS DOES
==============
You keep ONE normal package catalog.

Normal customer buys a normal package:
    -> normal personal entitlement

ACTIVE reseller buys the SAME normal package:
    -> RESELLER_STOCK automatically
    -> access_scope=RESELLER
    -> immediately available to /reseller-management/inventory
    -> seller can distribute it through the reseller API

NO reseller package is required.
NO checkout option is required.
NO manual convert command is required.

EXISTING OLD PURCHASES
======================
The reseller inventory endpoint and allocation service now automatically adopt
historical paid purchases when ALL of these are true:

- owner has reseller.manage
- reseller_profiles.status = ACTIVE
- source_type = ORDER
- status = ACTIVE
- remaining_units == original_units (completely unused)
- reserved_units = 0
- not expired
- scope is ACCOUNT, API_KEY, UNASSIGNED, or null

That means an existing untouched "Claude 10M Tokens" purchase can appear as
reseller inventory simply by calling GET /reseller-management/inventory.

The process is idempotent. Once adopted, source_type becomes RESELLER_STOCK and
it no longer matches the adoption query.

IMPORTANT:
- partially used purchases are NOT moved
- promotion/redeem/referral/Playground lots are NOT moved
- expired lots are NOT moved
- immutable credit_ledger history is NOT rewritten
- the adoption is recorded in audit_logs
- package expiry is preserved, so customer allocations inherit the same expiry

FILES TO REPLACE
================
1. backend/app/Services/OrderFulfillmentService.php
2. backend/app/Services/ResellerStockService.php
3. backend/app/Http/Controllers/Api/V1/ResellerInventoryController.php
4. backend/app/Services/ResellerAllocationService.php

Optional test helper:
5. scripts/Test-ResellerAuto.ps1

DEPLOY
======
On production:

    cd /var/www/sp-cambo

Replace the four backend files above, then:

    cd /var/www/sp-cambo/backend
    php artisan optimize:clear

No migration is required.

TEST EXISTING 10M
=================
From Windows PowerShell, use a FRESH management key:

    $env:SP_RESELLER_KEY="sk-spm-YOUR-NEW-KEY"

Then:

    Invoke-RestMethod `
      -Uri "https://sp-cambo.store/api/v1/reseller-management/inventory" `
      -Headers @{
        Authorization = "Bearer $env:SP_RESELLER_KEY"
        Accept = "application/json"
      } | ConvertTo-Json -Depth 10

The first inventory request automatically adopts an eligible old purchase.

For your already-created demo customer ID 2:

    .\scripts\Test-ResellerAuto.ps1

The script allocates 1,000,000 tokens using:
    demo-auto-customer-2-1m-v1

Re-running the same request is safe because allocation is idempotent.

FUTURE FLOW
===========
Seller buys normal package
    -> payment verified
    -> OrderFulfillmentService detects ACTIVE reseller
    -> paid units become RESELLER_STOCK
    -> seller backend uses sk-spm-
    -> create/manage customer
    -> allocate any amount
    -> create customer sk- inference key

SECURITY
========
A management key previously pasted into chat should be revoked after testing.
Do not save a real sk-spm secret in GitHub, .env.example, frontend code, README,
or PowerShell script committed to source control.
