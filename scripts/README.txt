SP Cambo - Easy Idempotent Local Start Scripts
===============================================

These scripts DO NOT modify .env files.

Main improvement:
- START_ALL skips services already running.
- START_GATEWAY exits cleanly if port 3010 health is already OK.
- START_LARAVEL/KHQR/Frontend do the same.
- This prevents EADDRINUSE when you run START_ALL twice.
- Frontend check allows 20 seconds because Nuxt first-request compilation can be slower.

START:
cd "C:\Users\Rg Gear\Desktop\SP Cambo"
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\START_ALL.ps1

CHECK:
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\CHECK_ALL.ps1

STOP:
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\STOP_ALL.ps1

Expected:
Frontend  :3000
Laravel   :8000
Gateway   :3010
KHQR      :3011
Scheduler schedule:work

Claude:
$env:ANTHROPIC_BASE_URL="http://127.0.0.1:3010"
$env:ANTHROPIC_AUTH_TOKEN="sk-spc-YOUR-KEY"
$env:ANTHROPIC_MODEL="claude-opus-5"
claude
