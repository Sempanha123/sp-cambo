SP Cambo — Full source update (2026-08-25)
==========================================

This package contains the full SP Cambo source with the provider sell-readiness,
standalone Telegram Store, live no-login key checker and chat Playground fixes.

IMPORTANT AFTER REPLACING YOUR PROJECT:

1) Backend migration
   cd backend
   composer install
   php artisan migrate
   php artisan optimize:clear

2) Frontend dependencies
   cd ..\frontend
   npm install
   npm run postinstall

3) Start the local stack
   cd ..
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\START_ALL.ps1

4) Telegram Store
   - Set TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME / TELEGRAM_WEBHOOK_SECRET in backend/.env
   - Set NUXT_PUBLIC_TELEGRAM_BOT_USERNAME (without @) in frontend/.env / deployment env
   - Register the public HTTPS webhook with scripts\SET_TELEGRAM_WEBHOOK.ps1
   - Keep the scheduler running for automatic payment verification + key delivery

5) To make a model sell-ready
   - Probe provider connection (first READY route auto-activates when none exists)
   - Enable Commercial resale verified only when your upstream terms allow resale
   - Enable + Customer visible on the public alias
   - Configure supported protocol(s) and pricing/cost verification
   - Then publish the package

Full details and validation notes:
  docs\audit\SPCAMBO_FULL_FIX_2026-08-25.md

Run the full local acceptance gate:
  powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\FINAL_ACCEPTANCE.ps1
