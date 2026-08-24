# npm + frontend typecheck fix — 2026-08-24

## Package-manager issue
The frontend archive previously declared pnpm and included pnpm lock/workspace files. A local installation created with pnpm leaves `node_modules/.pnpm`, and running `npm install` over that mixed tree can fail inside npm/arborist with `Cannot read properties of null (reading 'matches')`.

The frontend is now npm-neutral (pnpm metadata removed). On an existing checkout, remove `node_modules`, `.nuxt`, `.output`, `pnpm-lock.yaml`, and `pnpm-workspace.yaml`, then run `npm cache verify` and `npm install`.

A convenience PowerShell script is included at `frontend/reset-npm.ps1`.

## TypeScript fixes
- Added missing provider alias/model/input type imports in `app/pages/admin/providers/[id].vue`.
- Corrected checkout money formatting in `app/pages/checkout/success.vue`.
- Added missing `UsageSummary` type import in `app/pages/dashboard/api-keys.vue`.
- Moved clipboard access into a client-safe script function in `app/pages/dashboard/claim-key.vue`.
- Removed unsupported `nitro.proxy` config; the app already uses `NUXT_PUBLIC_API_BASE_URL` directly.

## Verification
Source-level checks confirm all names reported in the user's 16-error typecheck output are now imported/referenced correctly. Full Nuxt typecheck must be run after npm dependencies are installed.
