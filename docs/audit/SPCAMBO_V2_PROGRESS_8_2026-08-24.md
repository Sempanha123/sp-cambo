# SP Cambo V2 Progress 8 — 2026-08-24

Progress 8 continues directly from `SPCAMBO-2026-08-24-FULL-V2-PROGRESS-7` and preserves all completed V2 work: Playground daily quota isolation, redeem codes, additive paid upgrades, existing-vs-new API-key fulfillment, tenant ownership, provider revision edit/delete, admin-selected OmniRoute routing, collapsed-sidebar polish, Telegram purchase/payment/entitlement/key delivery, and repository-root CI.

## Production fixes added in Progress 8

### 1. Public API-key checker now validates real issued keys

A critical digest mismatch was found in `ApiKeyController::check()`:

- `ApiKeySecretService` stores API-key lookup digests using HMAC-SHA256 with the configured SP Cambo lookup secret/app key.
- The public checker was looking up the submitted key using plain SHA-256.
- The old unit test hid the defect by manually overwriting `lookup_digest` with a plain SHA-256 value after issuing the key.

Progress 8 injects `ApiKeySecretService` into the checker and uses the same `digest()` method used by key issuance and the inference gateway. A key created by SP Cambo can therefore be checked without any test-only database mutation.

### 2. Disabled/expired keys are no longer rendered as active in the browser

The public page previously set `valid: true` after every successful HTTP response, ignoring the control plane's `valid` field. Since disabled and expired secrets intentionally return HTTP 200 with an inactive status, the browser could display them as valid.

Progress 8 preserves the backend result. A verified-but-disabled/expired key now displays its real effective state, while an invalid secret still renders the verification error state.

### 3. Zero balance is no longer presented as “Unlimited”

The old checker converted zero spendable units to `null`, and the frontend formatter interpreted `null` as “Unlimited.” Progress 8 preserves exact zero and treats absence of a billing mode as “not applicable.”

### 4. Token quota and credit balance are separated and exact

The old public checker summed every entitlement unit into both `quota_remaining` and `credit_remaining`, even though token quotas and credit balances use different units.

Progress 8 now:

- sums `TOKEN_QUOTA` lots only into token quota;
- sums `CREDIT_BALANCE` lots only into money;
- subtracts currently reserved units from both;
- transports token quantities as decimal strings rather than JavaScript numbers;
- transports credit money as `{ minor, currency, exponent }`, with no binary-float money conversion;
- keeps multiple currency/scale groups separate instead of silently adding incompatible amounts.

### 5. Reported balance now matches the key's model scope

Credential isolation alone was not enough: a user may have entitlements for models that a particular API key cannot call. Progress 8 filters balance lots to those whose `allowed_model_aliases` overlap the API key's published model scope. This prevents a key scoped to model A from advertising quota that is usable only by model B.

### 6. Real metering and recent requests now populate the public checker

The UI already contained “Metered usage,” “Credit charged,” and “Recent requests” sections, but the backend returned a hard-coded `total_spend = 0.00` and no usage/request records.

Progress 8 now returns, for the submitted key only:

- total input/output/overall metered tokens from `usage_records`;
- exact credit charges grouped by currency and exponent;
- up to 10 recent request metadata rows (time, public model, state, input/output tokens and exact credit charge);
- no prompt or response content and no private provider routing material.

The display label is **Credit charged** rather than “Total spend” because this ledger represents inference credit charges, not package purchase prices.

### 7. Dashboard API-key non-billable Test now reports real spendable balance

`GET /me/api-keys/{id}/status` previously returned both balance fields as `null`. It now applies the same credential-source isolation, active reservation subtraction and model-scope filtering as the public checker. The dashboard clearly distinguishes an exact zero from a billing mode that is not applicable.

## Regression coverage added/updated

Backend `ApiKeyCheckTest` now covers:

- a freshly issued HMAC-digested API key checks successfully without rewriting the digest;
- token quota and credit balance are not mixed;
- active reservations reduce the reported spendable balance;
- expired/inactive lots are excluded;
- entitlements outside the API key's model scope are excluded;
- exact zero remains `"0"`;
- expired and disabled keys return `valid=false` with the effective state;
- metered token totals, exact credit charge and recent request metadata come from the real usage tables;
- authenticated key status reports the same non-billable spendable balances.

Frontend coverage now includes `PublicKeyCheckerPage.spec.ts` and updates `ApiKeysPage.spec.ts` for:

- verified disabled keys staying disabled in the browser;
- zero quota rendering as zero rather than Unlimited;
- exact MoneyAmount rendering for credit balance/credit charged;
- dashboard distinction between zero and not-applicable balance modes.

## Validation completed in this runner

- PHP syntax: **224/224 backend PHP files pass `php -l`**.
- TypeScript/Vue parser validation: **174/174 frontend and gateway TypeScript/Vue script blocks parse with zero syntax diagnostics** using the installed TypeScript 5.8.3 parser.
- Repository-root GitHub Actions YAML parses successfully with PyYAML.
- Source scan contains no remaining `TODO`, `FIXME`, `stub`, or `not implemented` markers in production backend/frontend/gateway source.
- Packaging excludes real `.env`, SQLite/database files, `vendor`, `node_modules`, Nuxt output, gateway `dist`, coverage and runtime logs.

Dependency-backed tests still cannot run inside this disposable runner because Composer is not installed, outbound DNS/network access is unavailable, and no Laravel/Nuxt dependency cache is bundled. These are environment limits, not reported as passes.

## Remaining acceptance gates before FINAL

Progress 8 is intentionally **not FINAL** until all of the following pass:

- GitHub Actions / dependency-complete Laravel Composer install and full `php artisan test` suite, including the new checker regression tests.
- Nuxt dependency install, lint, typecheck, Vitest and production build, including the new checker component test.
- Gateway under Node 24 with frozen pnpm install, typecheck, Vitest and build.
- Live Telegram private-chat webhook/send-message acceptance.
- Live Bakong KHQR payment -> verification -> entitlement -> Telegram delivery acceptance.
- Live private OmniRoute route smoke test through SP Cambo, including Claude Code and OpenAI/Codex-compatible base URLs.

Do not label the project production-final until those dependency-backed and live-service gates pass.
