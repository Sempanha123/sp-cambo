# SP Cambo Telegram Store UX R3

Copy/merge the `backend/` folder into the SP Cambo repository.

R3 changes:
- Credit packages never show their internal token backing in Store or product detail.
- Credit packages use the existing `billing_rules.package_kind = SP_CREDITS` and
  `billing_rules.display_units`; no fake/hard-coded credit catalog is introduced.
- Store package buttons are compact 2-column rows for clearer buying with less scrolling.
- Promo-code input now has clear Cancel / No Promo / Remove Promo controls.
- Invalid promo codes keep easy Try Another / No Promo / Checkout buttons.
- My Orders defaults to completed successful purchases only.
- Pending orders are separated behind a `Pending (N)` button.
- Pending view shows friendly states and compact per-order Check buttons.
- Keeps R2 Home-only inline navigation, compact wallet top-ups, KHQR real-expiry display,
  and automatic QR cleanup.

No new migration is required beyond the QR-tracking migration already included from R2.

After copying files:

```bash
cd backend
php artisan migrate
php artisan optimize:clear
php artisan test
```

Production queue note:
Delayed KHQR deletion needs a real queue driver such as `database` or `redis`.
