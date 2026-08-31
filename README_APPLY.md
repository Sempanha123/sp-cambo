# SP Cambo Telegram Store UI R2

This ZIP keeps repository-relative paths. Copy/merge the `backend/` folder into the SP Cambo repository.

Included changes:
- Home navigation is now an inline keyboard attached to the Home message.
- The full navigation menu appears only on Home; it does not stay pinned under other screens.
- Removed the Home `Updates` button to reduce clutter. The existing `/updates` command remains available for compatibility.
- Home layout is compact: 3 columns, then 3 columns, then 2 columns.
- A one-time cleanup removes the old persistent ReplyKeyboard from users who saw the previous bot layout.
- Store/product/wallet/checkout/KHQR screens keep their own message-attached inline buttons.
- Keeps the previous real PromotionService checkout integration.
- Keeps compact 3-column wallet top-up amounts.
- Keeps KHQR real-expiry countdown and automatic expired-QR deletion.

After copying files:

```bash
cd backend
php artisan migrate
php artisan optimize:clear
php artisan test
```

Production queue note: delayed KHQR deletion requires a real queue driver such as database or redis. Do not use `sync` for the production queue if you want expiry cleanup to run automatically.
