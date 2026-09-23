SP Cambo reseller multi-model package fix

WHAT CHANGES
============
1. Seller can sell from one exact reseller inventory PACKAGE lot.
2. The customer receives one shared balance across every model included in that package.
3. Existing legacy single-model allocation still works.
4. /dashboard/entitlements shows reseller inventory separately from personal balance.
5. Reseller inventory remains isolated from the seller's own normal API keys.

NO DATABASE MIGRATION
=====================
No migration is required. The existing reseller_transfers.public_model_alias column
uses the internal value __PACKAGE__ for package-level transfers. The real model list
lives on the target customer entitlement, where billing already reads it.

FILES TO REPLACE
================
backend/app/Services/OrderFulfillmentService.php
backend/app/Services/ResellerStockService.php
backend/app/Services/ResellerAllocationService.php
backend/app/Http/Controllers/Api/V1/ResellerInventoryController.php
backend/app/Http/Controllers/Api/V1/ResellerCustomerController.php
backend/app/Http/Controllers/Api/V1/ResellerCustomerReportingController.php
frontend/app/types/reseller.ts
frontend/app/pages/dashboard/entitlements.vue

The first three auto-stock files are included again so this ZIP is self-contained
and does not depend on an earlier patch being present.

ONE SMALL FRONTEND EDIT
=======================
See:
frontend/app/composables/useSpApi.reseller.patch.txt

This is intentionally a tiny edit instead of replacing the whole large useSpApi.ts,
so unrelated recent API work is not overwritten.

DEPLOY
======
Backend:
  cd /var/www/sp-cambo/backend
  php artisan optimize:clear

Frontend:
  cd /var/www/sp-cambo/frontend
  npm run build

Then restart the frontend process using your normal production service/PM2 command.

NEW PACKAGE-LEVEL REQUEST
=========================
POST /api/v1/reseller-management/customers/{customerId}/allocations

Body:
{
  "inventory_lot_id": "01m37ptxbtmfs7z58ce11w2mvs",
  "units": 1000000,
  "idempotency_key": "seller-order-10001",
  "reason": "Customer purchased one million Claude tokens."
}

The response includes:
- package_name
- allowed_model_aliases[]
- expires_at
- units

The customer receives ONE shared 1M pool across all aliases copied from that package.
