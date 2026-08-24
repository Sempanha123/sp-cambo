# SP Cambo V2 Progress 7 — 2026-08-24

Progress 7 continues from `SPCAMBO-2026-08-24-FULL-V2-PROGRESS-6` and preserves all previously completed V2 work: Playground daily quota, redeem codes, additive paid upgrades, existing-vs-new API-key fulfillment, tenant ownership, admin-selected routing, Telegram purchase/payment/entitlement/key delivery, provider revision UI, collapsed-sidebar work, and repository-root CI.

## Production fixes added in Progress 7

### 1. Playground daily-free quota is now credential-isolated

A production billing gap was found in the preflight path: entitlement selection was user-scoped, so `PLAYGROUND_DAILY` lots and paid lots were visible to the same user's API keys. That created two incorrect cases:

- a normal customer API key could potentially consume the daily Playground lot;
- a Playground request near the end of its free balance could fall through to a paid entitlement group instead of failing at the free limit.

Progress 7 closes both paths:

- `InferenceBillingService` selects only `PLAYGROUND_DAILY` lots for the system-managed Playground key and excludes those lots for normal customer keys.
- `ReservationService` repeats the same restriction as a defense-in-depth check so another caller cannot accidentally bypass the preflight policy.
- gateway key inspection reports balances from the same credential-specific lot pool.
- the public key checker now reports spendable units from the credential-specific pool and subtracts reserved units instead of showing capacity that is already reserved.

Result: **Playground can consume only the daily free lot; customer keys can consume only non-Playground entitlements. Free quota can never silently spend paid balance.**

### 2. Provider revision Edit is now actually compatible with model immutability

Progress 6 added the Admin Edit UI and an API update action for unused `PENDING` revisions, but the Eloquent model still rejected every routing-field change unconditionally.

Progress 7 aligns the model-level guard with the intended production contract:

- routing fields may change only while the revision was and remains `PENDING`;
- the revision must not be the provider's active route;
- the revision must not have reservation/request history;
- READY/DRAINING/REVOKED, active, or used revisions remain immutable and require rotation to a new revision.

The controller continues to enforce the same policy before mutation. The model now provides the same protection for writes performed outside the controller.

### 3. Regression coverage expanded

Added/updated backend tests for:

- customer API keys cannot spend `PLAYGROUND_DAILY` lots;
- Playground keys cannot fall through to paid entitlements when free quota is insufficient;
- gateway inspection exposes only the balance the credential can actually spend;
- unused PENDING provider revisions can change route/origin/credential/timeout/policy;
- READY and active revisions reject routing-field mutation;
- Admin revision Edit preserves the encrypted provider credential when the replacement field is blank;
- Admin revision Delete removes an unused, non-active revision;
- internal gateway test fixtures now create a real READY active provider connection revision so the tests match production route requirements.

## Validation completed in this runner

- PHP syntax: **224/224 backend PHP files pass `php -l`**.
- TypeScript/Vue parser validation: **173/173 frontend and gateway TypeScript/Vue script blocks parse with zero syntax diagnostics** using TypeScript 5.8.3.
- Repository-root GitHub Actions YAML parses successfully with PyYAML.
- High-confidence source credential scan reports **0 actual secrets**, and packaging checks exclude real `.env`, SQLite/database files, `vendor`, `node_modules`, Nuxt output, gateway `dist`, and other runtime dependency/build directories.
- The original 26 MB FULL-V2 checkpoint was inspected for reusable dependencies. It contains gateway `node_modules`, but they are a Windows pnpm/junction layout. Restoring them into this Linux runner does not reconstruct pnpm links correctly, so dependency-backed gateway tests cannot be treated as valid here. The original checkpoint contains no Laravel `vendor` and no frontend `node_modules`.

## Remaining acceptance gates before FINAL

Progress 7 is still intentionally **not FINAL**. The following must pass before a production-final claim:

- GitHub Actions / dependency-complete Laravel Composer install and full `php artisan test` suite.
- Nuxt install, lint, typecheck, Vitest, and production build.
- Gateway on Node 24 with frozen pnpm install, typecheck, Vitest, and build.
- Live Telegram private-chat webhook/send-message acceptance.
- Live Bakong KHQR payment -> verification -> entitlement -> Telegram delivery acceptance.
- Live private OmniRoute route smoke test through the SP Cambo gateway, including Claude Code and OpenAI/Codex-compatible base URLs.

Do not label the project FINAL until these dependency-backed and live-service gates pass.
