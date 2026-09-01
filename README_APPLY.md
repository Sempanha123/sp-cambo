# SP Cambo Telegram Custom Alerts R12

Built against the current `Sempanha123/sp-cambo` main branch inspected on 2026-09-01.

## What R12 adds

### `/admin/telegram`
- persistent automatic-notification settings
- multiple saved Telegram alert channels/groups
- enable/disable each channel independently
- Test button for each channel
- routing per event:
  - Off
  - Bot only
  - Channels only
  - Bot + channels
- saved routing for:
  - new package
  - package update
  - stock/restock
  - new public model
  - public model update
  - promotion create/update
  - verified Telegram purchase activity
- manual update target selector: Bot, Channels, or Both

“Bot” means the existing opted-in SP Cambo Store Bot subscribers.
“Channels” means all enabled channel destinations configured in Admin.

### Live Bakong KHQR countdown
Existing QR photo messages are edited in place using Telegram `editMessageCaption`.

Example:

```text
💳✨ BAKONG KHQR

📦 Claude 10M Tokens
💵 Amount: $0.79
🧾 Order: #ABC123

⏳ Time remaining: 04:30
🔄 Countdown updates automatically.
```

- 10 / 15 / 30 / 60 second update interval
- 15 seconds recommended
- no extra countdown spam messages
- stops when payment is verified
- stops when QR expires/deletes
- works for package purchases and Store Wallet top-ups
- existing expiry-delete job remains authoritative

Telegram does not provide a client-side live timer inside messages, so this is a
real server-updated countdown. Updating every second would create unnecessary
Telegram API traffic/rate-limit pressure; the configurable 10–60 second interval
is the production-safe design.

## Important safety/behavior

- automatic alerts are asynchronous; Telegram network failure does not roll back
  package/model/promotion admin writes
- channel delivery jobs retry automatically
- public model alerts remove provider/OmniRoute disclosure
- no `.env` changes or bot-token changes are included
- a channel must allow the SP Cambo bot to post messages

## New database tables

- `telegram_notification_settings`
- `telegram_alert_channels`

Existing QR tracking columns are reused. R12 does not replace the existing
`telegram_qr_message_id`, `telegram_qr_expires_at`, or expiry-delete mechanism.

## Local apply/test

Extract over the project root.

Backend:

```powershell
cd backend
php artisan migrate
php artisan optimize:clear
php artisan route:list --path=telegram-store
php artisan test
```

Frontend:

```powershell
cd ..\frontend
npm run typecheck
npm run build
```

Keep a queue worker running because channel delivery and QR countdown edits are
queued jobs.

## Production

After the tested commit is deployed:

```bash
cd /var/www/sp-cambo/backend
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart

cd /var/www/sp-cambo/frontend
npm run build
sudo systemctl restart sp-cambo-frontend
sudo systemctl status sp-cambo-frontend --no-pager
```

Also confirm your existing Laravel queue worker is running. The QR countdown
depends on delayed queue jobs.
