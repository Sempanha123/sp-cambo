# AI1 Status

## Current checkpoint
- Base: SP Cambo R6.1
- Branch: `ai1/production-finish`
- State: PLAYGROUND ISOLATION IMPLEMENTED; NEXT BATCH IS AI2 P1-001 STREAMING TIMEOUT

## Current task
- Highest-priority remaining OPEN item from `AI2_AUDIT.md`: **P1-001** streaming responses bypass the upstream timeout after headers arrive.
- Previous batch (strict Playground billing isolation) is implemented and focused-verified. Status: **FIXED**, not VERIFIED.

## Last implementation batch
Restore strict Playground billing isolation so hosted Playground credentials can spend only `PLAYGROUND_DAILY` lots, and ordinary customer keys can spend only non-Playground lots. Free quota can never silently spend paid, redeemed, promotional, transferred, or admin-granted balance.

Defense in depth:
- `InferenceBillingService` source split before billing-mode grouping
- `ReservationService` independent source split when `apiKeyId` is present
- Gateway inspect filters lots by source pool and aliases attached to the presented key
- Public key-check and authenticated key status use the same two-sided filter
- Hosted quota/run require `PLAYGROUND_DAILY`; exhaustion is HTTP 402 `playground_quota_exhausted`
- Compatibility fallback fields remain present but are hard-coded 0/false
- Customer Playground UI eligibility is daily-only; paid fallback cards and send path are gone
- Admin Playground copy documents the isolated daily lot

Also in this checkpoint:
- Telegram storefront migration `2026_08_26_000060` is replay-safe: create-if-missing, in-place repair, never drop `telegram_announcement_deliveries`
- `TestCase::publishPackage()` builds a real provider → READY revision → model → published alias graph so catalog/order/payment tests no longer weaken `Package::published()`

## Files changed
- `backend/app/Services/InferenceBillingService.php`
- `backend/app/Services/ReservationService.php`
- `backend/app/Services/PlaygroundService.php`
- `backend/app/Http/Controllers/Api/V1/Internal/GatewayBillingController.php`
- `backend/app/Http/Controllers/Api/V1/ApiKeyController.php`
- `backend/database/migrations/2026_08_26_000060_upgrade_telegram_storefront.php`
- `backend/tests/TestCase.php`
- `backend/tests/Feature/Feature/Api/V1/PlaygroundIsolationTest.php` (new)
- `backend/tests/Feature/Feature/Api/V1/InternalGatewayBillingTest.php`
- `backend/tests/Feature/Feature/Api/V1/OrderPromotionTest.php`
- `backend/tests/Feature/Feature/Api/V1/PaymentLifecycleTest.php`
- `backend/tests/Unit/ApiKeyCheckTest.php`
- `backend/tests/Unit/MigrationIdentifierTest.php`
- `frontend/app/pages/dashboard/playground.vue`
- `frontend/app/pages/admin/playground.vue`
- `frontend/tests/component/PlaygroundPage.spec.ts`
- `docs/ai/AI1_STATUS.md`
- `docs/ai/PARALLEL_BOARD.md`

Intentionally not staged:
- Pre-existing uncommitted user frontend work (`AuthCard.vue`, layouts, login/register, dashboard account/api-keys/claim-key/entitlements/telegram/usage, admin providers/redeem-codes/telegram, Google callback)
- Accidental untracked root `pnpm-lock.yaml`
- `frontend/pnpm-workspace.yaml` (restored to HEAD; ignored pnpm builds remain unapproved)

## Verification run
Backend focused isolation (prior continuation, still valid):
- `ApiKeyCheckTest` + `InternalGatewayBillingTest` + `ReservationTest` + `PlaygroundIsolationTest`
- `{"tool":"phpunit","result":"passed","tests":36,"passed":36,"assertions":225}`

Telegram migration identifiers:
- 9 tests, 95 assertions passed

Package/order fixtures after `publishPackage()`:
- Payment Lifecycle 8 tests / 61 assertions
- Order Promotion 14 tests / 97 assertions
- Package Catalog 2 tests / 9 assertions

Frontend:
- `PlaygroundPage.spec.ts`: 1 file, 4 tests passed (includes no-silent-paid-spend)
- Full Vitest: 41 files, 634 tests passed
- `./node_modules/.bin/eslint .`: pass
- `./node_modules/.bin/nuxt typecheck`: pass
- `./node_modules/.bin/nuxt build`: `Build complete!` (Vue 3.5.40)

Environmental note:
- Mixed npm/pnpm Vue runtimes previously broke `currentRenderingInstance` (`Cannot read properties of null (reading 'ce')`).
- Repair: `pnpm install --frozen-lockfile` without approving ignored builds (`@parcel/watcher`, `unrs-resolver`, `vue-demi`).
- `pnpm exec` re-triggers ignored-build failure; use `./node_modules/.bin/*`.

## Known remaining issue
- **P1-001 OPEN** — streaming responses bypass the upstream timeout after headers arrive (`gateway/src/app.ts`). Next implementation batch.
- Full `php artisan test` still exits 139. Do not claim full backend success; keep using split suites.
- Telegram link-conflict wording: service throws `already has an active SP Cambo storefront account`; test still asserts substring `already linked`. Identity ownership protection must not be weakened.
- Playground credentials migration `2026_08_24_000048` is still an unconditional `Schema::create`.
- Playground settings migration `2026_08_24_000052` `updateOrInsert` can reset operator settings if the migration row is missing but the singleton exists.
- Required production docs still absent (`README.md`, `docs/PRODUCTION_DEPLOYMENT.md`, `PRODUCTION_CHECKLIST.md`, `ARCHITECTURE.md`, `BILLING_AND_METERING.md`, `PAYMENT_FLOW.md`, `TELEGRAM_STOREFRONT.md`, `PLAYGROUND.md`, `API_COMPATIBILITY.md`, `ADMIN_OPERATIONS.md`, `BACKUP_RESTORE.md`, `TROUBLESHOOTING.md`, `SECURITY.md`, `RELEASE_NOTES.md`).
- Real provider / Bakong / Telegram / customer-key / scheduler / backup / staging acceptance still unproven.
- AI2 release decision remains BLOCKED. AI1 marks FIXED only; only AI2 marks VERIFIED.

## Latest checkpoint commit
- Pending immediately after this status write on `ai1/production-finish`.
