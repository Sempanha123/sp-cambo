# SP Cambo validation fix — 2026-08-26 R2

This round addresses failures reported from Windows Node v26.7.0 acceptance validation.

## Fixed

- `FINAL_ACCEPTANCE.ps1` no longer treats a stale/null `$LASTEXITCODE` as a failure for PowerShell-only tool checks.
- Docker remains optional for local acceptance and Docker-only gates are skipped when unavailable.
- `backend/composer.lock` content hash is synchronized with `composer.json` (`laravel/socialite: ^5.30`).
- `frontend/app/pages/admin/providers/[id].vue`: the edit-alias reset helper is now used after a successful save, fixing the unused-variable lint error.
- `frontend/app/pages/admin/redeem-codes.vue`: `openCreate` is formatted as one statement per line.
- Replaced nonexistent `SpResourceState` usages with the existing `SpAsyncSection` component in Redeem Codes and customer Playground.
- `PlaygroundPage.spec.ts` now waits for client-side resource promises before assertions, making quota exhaustion rendering deterministic.
- `FIX_VALIDATION.ps1` now checks Composer metadata and runs the Playground component spec explicitly before the full frontend suite.

## Validation performed in packaging environment

- PHP syntax: 229 files, 0 failures.
- Composer content-hash recomputed and matched between `composer.json` and `composer.lock`.
- No remaining `SpResourceState` references in frontend source/tests.
- JSON/package metadata inspected; `tsconfig.json` is JSONC and intentionally contains comments/extended syntax.

Full Nuxt/Vitest/Gateway execution still must be run on the Windows project machine because the packaging environment does not contain the project's installed Node dependencies and uses Node 22.
