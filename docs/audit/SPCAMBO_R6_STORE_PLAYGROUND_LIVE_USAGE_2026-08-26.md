# SP Cambo R6 — Storefront, Playground and Live Usage

Date: 2026-08-26

## Goal

R6 keeps the working R5 provider/discovery/package fixes and upgrades three customer surfaces without copying the reference products shown in screenshots:

1. a purpose-built SP Cambo Playground,
2. live request observability for authenticated Usage and the no-login Key Checker,
3. a standalone Telegram commerce bot with persistent navigation and opt-in product/model announcements.

## Playground

The customer Playground remains a hosted SP Cambo inference surface. Customers do not paste their API key into the Playground.

- Daily free quota remains first priority.
- Redeem balance and purchased balance continue after free quota is exhausted.
- Model selection respects the admin Playground setting and published model aliases.
- The page now uses a workspace layout with starter prompts, run setup, quick roles, system prompt, max output, optional temperature, balance strip, and last-request telemetry.
- The Playground polls request metadata every ~3 seconds and links to the full Usage page.
- Exact token counts are shown only after settlement. While a request is running, SP Cambo shows request state and the existing reserved-unit estimate rather than inventing a token count.

## Live request states

The gateway now reports best-effort state transitions to the control plane:

`RESERVED -> CONNECTING -> STREAMING -> SETTLED`

Terminal/error states remain `RECONCILING`, `RELEASED`, or `FAILED` where applicable. Observability state writes are intentionally best-effort and can never block inference.

Usage / Key Checker metadata can include:

- request time,
- state/status,
- endpoint,
- public model,
- routed provider/model metadata,
- route version,
- input/output/cache/reasoning/total tokens after settlement,
- reserved-unit estimate while unsettled,
- request duration,
- exact credit charge after settlement,
- safe error code.

No prompt, completion, tool argument, uploaded file content, or provider credential is stored in these request-log responses.

## Telegram Store

Telegram remains a standalone sales channel; `/link` is compatibility-only.

Persistent customer menu:

- Store
- Balance
- Models
- Orders
- Updates
- Language

Store flow:

`Store -> package detail -> Buy -> Bakong KHQR -> automatic payment reconciliation -> API key delivery -> CLI setup + Key Checker`

The bot supports English and Khmer storefront navigation. Customers can opt out of model/package announcements at any time.

### Automatic announcements

R6 adds a durable announcement outbox:

- newly published model,
- new sellable package,
- meaningful update to a sellable package,
- optional manual admin announcement.

When a model is announced and a published package containing it exists, the message receives a Buy button for that package. Package announcements receive Buy and Store buttons. Delivery is batched by the Laravel scheduler and tracked per Telegram account.

Admin page: `/admin/telegram`

It shows bot configuration state, active Telegram customers, update subscribers, queued jobs and recent delivery counts. Admins with `catalog.manage` may queue a short manual update and optionally attach a package Buy button.

## Database migration

R6 adds:

`backend/database/migrations/2026_08_26_000060_upgrade_telegram_storefront.php`

Run:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo\backend"
php artisan migrate
php artisan optimize:clear
```

Then restart the normal native Windows stack:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"
.\scripts\START_ALL.ps1
```

Docker is not required for this local mode.

## Scheduler requirements

Keep Laravel scheduler running. R6 schedules both once per minute:

- `telegram:reconcile-purchases --batch=4`
- `telegram:broadcast-announcements --batch=50`

The first verifies payment and delivers fulfilled access. The second delivers queued store announcements.

## Validation performed in the build environment

- PHP syntax checked across the backend source.
- TypeScript syntax parsing performed for frontend and gateway TypeScript / Vue script blocks.
- R6 archive integrity checked after packaging.

The build environment does not contain this project's `frontend/node_modules`, `gateway/node_modules` or `backend/vendor`, and its Node runtime is v22.16.0. Therefore R6 does **not** claim that the complete Nuxt, Vitest, gateway or Laravel runtime suites were executed here. Run `scripts/FINAL_ACCEPTANCE.ps1` on the normal Windows development machine with the already-supported Node v26.7.0 environment.
