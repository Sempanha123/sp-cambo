SP Cambo — recovery for interrupted SP Credits patch

WHY THIS EXISTS
===============
The previous APPLY_SP_CREDITS_FIX.py stopped on:

  Expected one target in frontend/app/pages/dashboard/entitlements.vue, found 3

Because that old script copied SpCredits.php and the migration only AFTER all
text replacements, the stop happened before those critical files were copied.
It also re-added an already-present import/display line, causing duplicates.

DO NOT RUN THE OLD APPLY SCRIPT AGAIN.

This recovery script is designed for the exact partial state shown in the server
output. It does these steps in the safe order:

1. Copies the missing SpCredits.php helper FIRST.
2. Copies the missing migration FIRST.
3. Copies the corrected reseller Sales History controller/types/page.
4. Removes duplicate SpCredits imports and duplicate display fields.
5. Completes Entitlements using all matching safe render occurrences.
6. Finishes API-key Token-vs-Credit separation.
7. Finishes Usage page Credit presentation.
8. Keeps existing package economics and raw integer settlement.

INSTALL
=======
Extract this recovery ZIP into:

  /var/www/sp-cambo

Then:

  cd /var/www/sp-cambo
  python3 RECOVER_SP_CREDITS.py

VERIFY FILES
============
  ls -l backend/app/Support/SpCredits.php
  ls -l backend/database/migrations/2026_09_24_040000_normalize_sp_credit_labels.php

  grep -n "SpCredits" backend/app/Http/Controllers/Api/V1/ResellerInventoryController.php
  grep -n "display_units" backend/app/Http/Controllers/Api/V1/ResellerCustomerController.php
  grep -n "display_units" "frontend/app/pages/reseller/customers/[id].vue"

The ResellerInventoryController grep should show ONE import and ONE display line,
not duplicates.

BACKEND CHECK
=============
  cd /var/www/sp-cambo/backend

  php -l app/Support/SpCredits.php
  php -l app/Http/Controllers/Api/V1/ResellerInventoryController.php
  php -l app/Http/Controllers/Api/V1/ResellerCustomerController.php
  php -l app/Http/Controllers/Api/V1/ResellerCustomerReportingController.php
  php -l app/Http/Controllers/Api/V1/ApiKeyController.php

  php artisan test --filter=SpCreditsTest
  php artisan migrate
  php artisan optimize:clear

Expected migration:
  2026_09_24_040000_normalize_sp_credit_labels ... DONE

FRONTEND
========
  cd /var/www/sp-cambo/frontend
  pnpm install
  pnpm build

Then restart your normal frontend process/service.

EXPECTED RESULT
===============
For the current Codex Credit package:

  Before purchase/sale display:
    50 Credits

  If reseller sells 10:
    Seller remaining: 40 Credits
    Customer sold:    10 Credits

  If customer consumes 68 internal settlement units:
    Used:      0.00068 Credits
    Remaining: 9.99932 Credits

The raw values such as 4,000,000 can still exist in API compatibility fields,
but the website uses the new display object and shows Credits.

IMPORTANT
=========
SP Credits remain quota-backed TOKEN_QUOTA internally on purpose.
They are not USD wallet CREDIT_BALANCE. This preserves the current cheap package
pricing and existing settlement/margin behavior.
