# SP Cambo V2 Progress 5 — 2026-08-24

This continuation pass resumed from the newest sanitized source snapshot built from the SP Cambo V2 checkpoint and later AutoPayment/KeyActivation/Gateway fixes.

## Confirmed preserved work

- Playground server-side execution and daily free token quota.
- Redeem/free-token code backend and customer redemption flow.
- Tenant ownership across users/orders/entitlements/API keys and fulfillment claims.
- Existing-vs-new SP Cambo API-key activation choice for purchased packages.
- Provider connection revision backend edit/delete controls.
- Admin-selected provider connection/private-model routing pinning through gateway preflight.
- Automatic website payment verification and API-key activation claim flow.
- Collapsed dashboard/sidebar UI refresh baseline.

## Added in this pass

### Telegram bot commerce

- Added secure one-time dashboard → Telegram account linking.
- Link codes are HMAC-digested at rest, expire after 10 minutes, and are single use.
- Telegram linking/purchasing is restricted to private bot chats so API-key delivery cannot accidentally target a group.
- Added bot commands:
  - `/plans`
  - `/buy PLAN_SLUG`
  - `/check`
- Telegram purchases reuse the normal `OrderService`, Bakong `PaymentService`, entitlement fulfillment, tenant ownership, and fulfillment claims.
- Added a one-minute server-side Telegram purchase reconciliation command/schedule.
- After server-side Bakong verification, Telegram-originated purchases can issue and deliver a new one-time SP Cambo API key plus Anthropic/OpenAI base URLs and purchased model aliases.
- If Telegram delivery fails after a one-time secret is issued, that key is revoked and the claim is restored to PENDING so a later retry creates a fresh secret instead of silently losing the customer's key.
- Added Telegram dashboard page to create/revoke the account link and explain the purchase flow.

### Redeem-code admin UI

- Added `Admin → Redeem codes` navigation and page.
- Operators can issue codes, choose model scope, units, lifetime, limits and schedule, and copy the plaintext code once at issuance.
- Existing codes expose masked values and can update enabled/schedule/redemption limits without revealing the secret again.

### Paid-plan upgrade UX

- Dashboard purchase CTA now explicitly presents repeat purchase as an upgrade/add-quota action.
- Backend fulfillment remains additive: every paid package creates new immutable entitlement lots, preserving existing value and FEFO settlement behavior.

### Frontend API contract coverage

- Added customer Telegram account/link APIs.
- Added admin redeem-code APIs.
- Added client methods for provider connection revision update/delete to match the backend routes already present.

## Configuration added

Backend `.env` values:

```dotenv
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_WEBHOOK_SECRET=
TELEGRAM_LINK_SECRET=
TELEGRAM_HTTP_TIMEOUT_SECONDS=15
SP_CAMBO_PUBLIC_GATEWAY_BASE_URL=https://api.example.com
```

Register the Telegram webhook against:

```text
POST /api/v1/telegram/webhook
```

and configure Telegram's `secret_token` to the same high-entropy value as `TELEGRAM_WEBHOOK_SECRET` so the controller can verify `X-Telegram-Bot-Api-Secret-Token`.

## Validation completed in this runner

- PHP syntax: 222/222 PHP files passed `php -l`.
- Frontend parser check: 116 TypeScript/Vue `<script setup>` blocks parsed successfully through TypeScript transpilation.
- Static secret scan found no committed customer/provider key material; environment examples remain placeholders.

## Validation still required before FINAL

This checkpoint is intentionally **not** labelled final/production-ready because dependency-backed and external integration acceptance remains outstanding:

- Composer/Laravel PHPUnit + migrations against a real MySQL test database.
- Nuxt dependency install, lint, typecheck, Vitest and production build.
- Node 24+ gateway typecheck/tests/build.
- Real Telegram Bot API webhook/send-message test.
- Real Bakong KHQR payment → Telegram fulfillment test.
- Real private OmniRoute model routing and Claude Code/Codex smoke tests.
- Provider revision edit/delete UI acceptance remains to be completed even though backend routes and client methods exist.

Do not claim production completion until those checks pass.
