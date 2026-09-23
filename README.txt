SP Cambo — Sales History customer remaining balance fix

Replace these 3 files:

1. backend/app/Http/Controllers/Api/V1/ResellerCustomerReportingController.php
2. frontend/app/types/reseller.ts
3. frontend/app/pages/reseller/customers/[id].vue

What changes:
- Sales history now reads the CURRENT target entitlement balance for each sale.
- Each sale shows:
  Sold
  Customer remaining
  Used
  Reserved
  Remaining progress %
  Current status
  Expiry
- It sums all target lots if an older transfer was funded from multiple reseller lots.
- No database migration is required.
- The figures are live values from entitlement_lots, not calculated from usage logs.

Deploy backend:
  cd /var/www/sp-cambo/backend
  php artisan optimize:clear

Deploy frontend:
  cd /var/www/sp-cambo/frontend
  npm run build

Then restart your normal Nuxt frontend process.

Example:
  Sold:               1,000,000
  Customer remaining:   999,930
  Used:                      70
  Reserved:                   0
