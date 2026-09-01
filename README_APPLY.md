# SP Cambo Telegram Order Fix R4

This package is built on top of the Telegram Store UX R3 files.

## What it fixes

- Maximum **4 open Telegram purchase orders per user**.
- When a 5th payable order is created, SP Cambo immediately soft-cancels the **oldest unpaid** order to keep the active queue at 4.
- **Paid / delivery-retry orders are never deleted** by the limit.
- Unpaid `AWAITING_PAYMENT` orders older than **1 hour** are automatically soft-cancelled by Laravel Scheduler.
- Expired unpaid KHQR messages are deleted when possible and stock reservations are released.
- `PAID` / `DELIVERY_FAILED` QR messages are removed so the customer is not encouraged to pay again.
- Check buttons now retry payment/delivery safely and show a specific paid/retrying message instead of the generic storefront error.
- Pending list is limited to 4 rows and explains the 4-order / 1-hour policy.
- If all 4 slots are paid or protected delivery retries, a new order is blocked instead of deleting paid history.

## Safety behavior

“Delete” is implemented as **soft-cancel** (`CANCELLED`) for unpaid orders. The database order/payment rows are kept for audit and late-payment recovery. Paid evidence always wins and is never cancelled by cleanup.

## Files

Replace/add the included files at the same paths from your SP Cambo repository root:

- `backend/app/Services/TelegramCommerceWalletFeatures.php`
- `backend/app/Services/TelegramStorefrontUiService.php`
- `backend/app/Services/TelegramPendingOrderPolicy.php` (new)
- `backend/app/Exceptions/TelegramPendingOrderLimitException.php` (new)
- `backend/app/Http/Controllers/Api/V1/TelegramWebhookController.php`
- `backend/app/Providers/TelegramOrderRetentionServiceProvider.php` (new)
- `backend/bootstrap/providers.php`

No `.env` changes and no new database migration are required.

## Local verification

From `backend`:

```powershell
php artisan optimize:clear
php artisan schedule:list
php artisan test
```

Your existing Laravel scheduler must still be running in production. The new retention provider registers `telegram:cleanup-expired-unpaid-orders` every minute; each run only cancels unpaid orders that are already at least one hour old.

## Important for existing `Delivery retry pending` rows

Those rows are intentionally preserved. Use their **Check** buttons (or let the existing `telegram:reconcile-purchases` scheduler retry them). When delivery succeeds, the existing commerce service marks them `DELIVERED` and they move to Completed.
