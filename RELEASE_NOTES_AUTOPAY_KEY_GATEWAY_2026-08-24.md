# SP Cambo — Auto Payment + API Activation + Gateway Fix

This source snapshot includes the previously validated tenant/payment/fulfillment repair plus the next customer-flow fixes.

## What changed

- Payment page automatically calls the same server-side Bakong verification used by **I have paid — check now**. The manual button remains available for an immediate re-check.
- The final expiry transition performs one last server-side verification, so a payment at the edge of the QR window is not silently missed.
- Fulfilled orders can show their exact API-key activation claim.
- Packages with **Include API access activation after payment** create a secure activation claim. The customer chooses **reuse existing key** or **create new key**. New packages default to this option enabled.
- The activation page now shows ready-to-copy Claude Code PowerShell and `.claude/settings.json` templates after key activation.
- Local customer inference URL is unified on `http://127.0.0.1:3010`. For Claude Code, do **not** append `/v1`; OpenAI/Codex uses `http://127.0.0.1:3010/v1`.
- Gateway reads `gateway/.env` automatically on Node 24+.
- Local gateway can use `GATEWAY_RATE_STORE=memory`, so Redis/Docker is not required for a single-process local test.
- Provider origin/credential/private model now come from the authenticated Laravel preflight, using the active Admin provider connection revision. The gateway no longer needs a duplicate OmniRoute token in `.env`.
- Added local start/check PowerShell scripts.

## Local processes

1. Laravel: `php artisan serve --host=127.0.0.1 --port=8000`
2. Gateway: `powershell -ExecutionPolicy Bypass -File .\scripts\START_LOCAL_GATEWAY.ps1`
3. KHQR: `powershell -ExecutionPolicy Bypass -File .\scripts\START_KHQR.ps1`
4. Nuxt: your normal frontend dev command.

## Claude Code local template

```powershell
$env:ANTHROPIC_BASE_URL = "http://127.0.0.1:3010"
$env:ANTHROPIC_AUTH_TOKEN = "sk-spc-YOUR-KEY"
$env:ANTHROPIC_MODEL = "claude-opus-5"
claude
```

`ANTHROPIC_BASE_URL=http://127.0.0.1:8787/v1` is wrong for this project: port 8787 was an old frontend default, while the inference gateway listens on 3010, and Claude Code needs the unversioned gateway root.

For real customers, replace loopback with a public HTTPS inference hostname, e.g. `https://api.spcambo.com` for Anthropic and `https://api.spcambo.com/v1` for OpenAI/Codex.
