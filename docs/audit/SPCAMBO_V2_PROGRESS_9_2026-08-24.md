# SP Cambo V2 Progress 9 — 2026-08-24

Progress 9 continues directly from `SPCAMBO-2026-08-24-FULL-V2-PROGRESS-8` and preserves all previously completed V2 work: Playground daily-free isolation, redeem codes, additive paid upgrades, existing-vs-new API-key fulfillment, tenant ownership, provider revision edit/delete, admin-selected OmniRoute routing, collapsed-sidebar polish, Telegram commerce, exact API-key balance/usage reporting, and repository-root CI.

## Production fixes added in Progress 9

### 1. Background website payment reconciliation now matches KHQR lifetime

The default KHQR attempt lifetime is five minutes (`BAKONG_PAYMENT_ATTEMPT_TTL_SECONDS=300`), but the server-side `payments:reconcile-pending` scheduler was running only every fifteen minutes. That meant a payment attempt created just after a scheduler run could expire before the next background verification. The browser checkout page performs its own safe verification polling while open, but true server-side automatic activation was not reliable when the customer closed the page.

Progress 9 changes the background reconciler so that:

- the scheduler runs every minute;
- an individual attempt is still rate-limited by `BAKONG_RECONCILE_INTERVAL_SECONDS` (default 60 seconds);
- recently expired attempts remain eligible for a bounded recovery window using `BAKONG_RECONCILE_EXPIRED_GRACE_SECONDS` (default 900 seconds);
- `PaymentService::verify()` continues to verify real Bakong evidence and remains the only component allowed to mark a payment as paid;
- transaction-hash replay protection, amount/currency/account matching, idempotent fulfillment, and the manual verification button remain unchanged.

The expired grace window is intentional: a customer may have completed a valid transfer shortly before QR expiry while SP Cambo temporarily could not reach Bakong. The verifier can now recover that payment automatically after local expiry instead of requiring the customer to keep the checkout page open.

### 2. Telegram unlink/relink no longer destroys purchase history

The previous Telegram link implementation handled an already-used Telegram identity by deleting another user's `telegram_accounts` row. Because `telegram_purchases.telegram_account_id` uses `cascadeOnDelete`, this could erase historical Telegram purchase/delivery records.

Progress 9 replaces deletion with a history-safe identity-release model:

- an **active** Telegram identity cannot be silently moved to another SP Cambo account;
- the user must unlink the old account first;
- unlinking revokes the account row but preserves it for audit/history;
- the real Telegram `user_id` and `chat_id` unique values are replaced with a non-routable `rvk:<ULID>` tombstone, freeing the real Telegram identity for a future link;
- existing `telegram_purchases` continue to reference the original account row, tenant, and user;
- legacy revoked rows that still own a real Telegram identity are safely tombstoned on a later link instead of being deleted;
- link tokens remain one-time, HMAC-protected and transactionally consumed only after a successful link.

This prevents both purchase-history loss and accidental cross-account history reassignment.

## Regression coverage added

`PaymentLifecycleTest` now covers:

- no duplicate verification inside the configured reconciliation interval;
- automatic re-check after the configured interval while the KHQR attempt is still live;
- recovery of a paid attempt that recently crossed local expiry;
- order fulfillment after recovered Bakong evidence.

`TelegramLinkLifecycleTest` now covers:

- blocking silent transfer of an active Telegram identity;
- unlinking while preserving the original account row and Telegram purchase history;
- releasing the unique Telegram identity using tombstone identifiers;
- linking that released Telegram identity to another SP Cambo account without reassigning the old purchase history;
- migration compatibility for legacy revoked rows that still contain real Telegram identifiers.

## Configuration added

Backend and Docker/infra example environment files now expose:

```env
BAKONG_RECONCILE_INTERVAL_SECONDS=60
BAKONG_RECONCILE_EXPIRED_GRACE_SECONDS=900
```

These values control verification cadence and the bounded post-expiry recovery window without changing KHQR's own payment expiry.

## Validation completed in this runner

- PHP syntax: **225/225 backend PHP files pass `php -l`**.
- TypeScript/Vue parser validation: **174/174 frontend/gateway TypeScript/Vue script blocks parse with zero syntax diagnostics** using TypeScript 5.8.3.
- Repository-root GitHub Actions YAML parses successfully.
- Payment reconciliation and Telegram identity-release structural assertions pass.
- Gateway dependency cache from the original checkpoint was reconstructed only for validation:
  - gateway TypeScript **typecheck passes**;
  - gateway TypeScript **production build passes**;
  - gateway Vitest could not execute because the old dependency cache was produced on Windows and does not contain Rollup's Linux native optional binary. This is an environment/cache limitation and is not reported as a test pass.
- No restored `node_modules` or generated gateway `dist` is included in the release package.

## Remaining acceptance gates before FINAL

Progress 9 is intentionally **not FINAL** until the dependency-complete and live-service gates pass:

- Laravel Composer install and full `php artisan test` suite, including the new payment and Telegram link regression tests.
- Nuxt dependency install, lint, typecheck, Vitest and production build.
- Gateway on clean Linux Node 24 with frozen pnpm install, typecheck, Vitest and build.
- Live Telegram private-chat webhook/send-message acceptance.
- Live Bakong KHQR payment -> background/manual verification -> entitlement -> Telegram delivery acceptance.
- Live private OmniRoute route smoke test through SP Cambo, including Claude Code and OpenAI/Codex-compatible base URLs.

Do not label the project production-final until those gates pass.
