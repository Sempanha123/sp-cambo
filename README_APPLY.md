# SP Cambo Telegram Checkout Upgrade

This ZIP preserves repository-relative paths. Copy/merge the `backend/` folder into your SP Cambo repo.

Included changes:
- Real PromotionService-backed promo code entry at Telegram checkout.
- Discount preview and final server-side revalidation through OrderService.
- FREE/100% promo checkout without KHQR or wallet debit.
- Compact 3-column Telegram home keyboard.
- Compact 3-column top-up amount buttons.
- Compact KHQR buttons and real expiry countdown text.
- Telegram QR message tracking.
- Delayed queue job that removes the KHQR message at its real expiry time.
- QR is also removed immediately when a top-up is confirmed, or when a checked purchase is delivered.

After copying files:

```bash
cd backend
php artisan migrate
php artisan optimize:clear
php artisan test
```

Production queue note: automatic expiry deletion uses Laravel's queue delay. Your production queue must not use the `sync` driver. The code intentionally does not dispatch the delayed deletion job when `queue.default=sync`, because sync would delete the QR immediately.
