# SP Cambo V2 Progress 6 — 2026-08-24

This pass continued directly from `SPCAMBO-2026-08-24-FULL-V2-PROGRESS-5` and preserves the completed V2 work: Playground daily quota, redeem codes, additive paid upgrades, existing-vs-new API-key fulfillment, tenant ownership, admin-selected provider/private-model routing, collapsed-sidebar baseline, automatic website payment activation, and Telegram purchase/payment/entitlement/key delivery.

## Added/fixed in Progress 6

### Provider connection revision Edit/Delete UI

The backend and API client already supported update/delete, but the Admin Provider page still exposed only Probe, Set active, and Update status. Progress 6 closes that UI gap:

- Added **Edit** for unused `PENDING` revisions.
- Active/non-pending revisions are visibly protected in the UI and still protected authoritatively by Laravel.
- Edit form supports route version, origin, connection type, timeout and policy version.
- Stored provider credentials are never read back into the browser. Leaving the replacement credential blank sends no new secret value and preserves the encrypted credential server-side.
- Added **Delete** confirmation for non-active revisions.
- Laravel remains authoritative and refuses deletion when a revision is active or has request history.
- Added a distinct frontend update input type so edit can legally omit/blank `credential` while create still requires it.

### Playground test contract repaired

`PlaygroundPage.spec.ts` still mocked the pre-free-quota API and asserted old copy saying SP Cambo did not run the request. The production page now runs bounded requests server-side, so the stale test would fail as soon as dependency-backed CI was enabled.

Progress 6 updates the test contract to mock:

- `playgroundQuota()`
- `runPlayground()`
- server-side Playground/no-browser-secret copy
- the **Run free test** action

### Repository-root production CI

The previous workflow was stored under `frontend/.github/workflows`, which GitHub does not execute for a monorepo whose repository root is above `frontend/`.

Progress 6 moves CI to `.github/workflows/ci.yml` and validates all three production services:

- Laravel on PHP 8.4: Composer validation/install, PHP syntax and `php artisan test`.
- Nuxt on Node 22: install, Nuxt prepare, lint, typecheck, Vitest and production build.
- Gateway on Node 24: frozen pnpm install, typecheck, Vitest and TypeScript build.

CI runs for pushes and pull requests targeting `main` or `develop`.

### Safe GitHub push helper

`PUSH_TO_GITHUB.ps1` now fetches `origin/main` before committing. For a freshly extracted Progress ZIP it resets only Git metadata/index to the remote base while preserving the extracted working files, then commits the real V6 delta and performs a normal fast-forward push. This avoids the unrelated-history / `fetch first` problem seen with a newly initialized folder, without using force push.

## Validation completed in this runner

- PHP syntax: **222/222 backend PHP files passed `php -l`**.
- TypeScript/Vue script syntax: **171 TypeScript/Vue script blocks parsed with zero syntax diagnostics** using the installed TypeScript compiler.
- The modified Provider page has balanced counts for its major Vue structural tags (`UModal`, `UForm`, `UButton`, `UFormField`, `template`).
- GitHub Actions YAML parses successfully.
- No real `.env` file, SQLite database, `vendor/`, `node_modules/`, build output or credential file is intended for the package/repository because the root `.gitignore` excludes them.

## Still required before FINAL

Progress 6 is intentionally **not** labelled FINAL yet.

The runner could not complete dependency installation reliably; the frontend install stalled long enough to destabilize the disposable container. Therefore the dependency-backed commands are delegated to the new repository-root CI and remain acceptance gates:

- Laravel Composer install + PHPUnit/feature suite.
- Nuxt lint + typecheck + Vitest + production build.
- Gateway Node 24 frozen install + typecheck + tests + build.
- Live Telegram Bot API webhook/send-message test.
- Live Bakong KHQR payment -> verification -> entitlement -> Telegram delivery test.
- Live private OmniRoute route + Claude Code/Codex smoke test.

Do not call this production-final until those checks pass. If repository CI passes, the remaining blockers are the live external-service acceptance checks.
