SP Cambo — Full source update R6 (2026-08-26)
=============================================

This full source package preserves the working R5 provider/discovery/package fixes and adds:
- SP Cambo-owned customer Playground workspace with live request monitor
- richer authenticated Usage + no-login Key Checker telemetry
- standalone Telegram Store persistent menu (Store/Balance/Models/Orders/Updates/Language)
- automatic new-model/new-package/package-update Telegram announcements
- admin Telegram Store monitor + manual broadcast page
- durable Telegram announcement outbox/delivery tracking

IMPORTANT AFTER REPLACING YOUR PROJECT:

1) Backend migration (R6 adds Telegram storefront tables/fields)
   cd backend
   composer install
   php artisan migrate
   php artisan optimize:clear

2) Frontend dependencies
   cd ..\frontend
   npm install
   npm run postinstall

3) Start the native Windows stack (Docker is not required)
   cd ..
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\START_ALL.ps1

4) Telegram Store
   - Set TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME / TELEGRAM_WEBHOOK_SECRET in backend/.env
   - Set NUXT_PUBLIC_TELEGRAM_BOT_USERNAME (without @) for the frontend
   - Register the public HTTPS webhook with scripts\SET_TELEGRAM_WEBHOOK.ps1
   - Keep the Laravel scheduler running
   - telegram:reconcile-purchases verifies payment + delivers API keys
   - telegram:broadcast-announcements delivers opt-in model/package updates

5) Live Usage / Key Checker
   - Gateway reports best-effort RESERVED -> CONNECTING -> STREAMING -> SETTLED states
   - Running requests show elapsed time and reserved-unit estimate
   - Exact input/output/charge appears after settlement
   - Prompt/completion text is never returned by these telemetry endpoints

6) To make a model sell-ready
   - Probe provider connection
   - Enable Commercial resale verified only when your upstream terms allow resale
   - Enable + Customer visible on the public alias
   - Configure supported protocol(s) and pricing/cost verification
   - Publish the package

R6 details:
  docs\audit\SPCAMBO_R6_STORE_PLAYGROUND_LIVE_USAGE_2026-08-26.md

Run the full local acceptance gate on the normal Node v26.7.0 Windows environment:
  powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\FINAL_ACCEPTANCE.ps1 -ContinueOnFailure -FixLint -SkipDocker
