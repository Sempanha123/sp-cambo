# SP Cambo V2 — Progress 2 (2026-08-24)

This checkpoint continues `SPCAMBO-2026-08-24-FULL-V2-PROGRESS-1` and is **not** the final production package.

## Completed in this pass

### Tenant / purchase / entitlement / API-key ownership

- Added `tenant_id` ownership to `users`, `orders`, `entitlement_lots`, and `api_keys` through migration `2026_08_24_000047_enforce_tenant_commerce_ownership.php`.
- Existing users are backfilled with one tenant and existing commerce/security rows inherit that tenant.
- New email/password and Google-created accounts now receive a tenant at account creation.
- Orders, entitlements, and new API keys now write tenant ownership explicitly.
- Order fulfillment verifies the order tenant matches the purchasing user before granting access.
- Fulfillment-claim list/claim endpoints are tenant-scoped and return 404 for cross-tenant claims.

### Existing-vs-new SP Cambo key choice

- Fulfillment claims now support `mode=NEW` or `mode=EXISTING`.
- Existing-key mode requires an ACTIVE key owned by the same tenant/user and adds purchased model scopes without revealing or replacing its secret.
- New-key mode creates a new one-time-reveal key.
- Fixed a pre-existing claim bug where public alias strings were passed directly to the API-key pivot as IDs; aliases are now resolved to actual `model_aliases.id` values first.
- Existing keys with an earlier explicit expiry are extended to the purchased claim expiry; non-expiring keys remain non-expiring.
- Rebuilt `/dashboard/claim-key` so the customer can choose existing vs new key before activation.

## Validation performed

- PHP syntax validation passed for all 144 PHP files under `backend/app`, `backend/routes`, and `backend/database/migrations`.
- Full Laravel test execution is still unavailable in this runner because `backend/vendor` is not bundled.
- Full Nuxt typecheck/test/build is still unavailable because `frontend/node_modules` is not bundled.
- Gateway runtime tests remain unavailable in this runner (dependency tree not bundled; project also targets a newer Node runtime than the runner).

## Still required before FINAL

- Playground daily-free quota and server-side Playground execution path.
- Redeem/free-token code issuance/redemption and quota precedence.
- Paid-plan upgrade UX/semantics beyond entitlement lot stacking.
- Admin-selected OmniRoute route mapping verification end-to-end.
- Telegram bot plan -> KHQR payment verification -> entitlement -> API key/base URL/model delivery.
- Collapsed-sidebar final polish/visual acceptance.
- Full Laravel + Nuxt + gateway automated validation and real integration smoke tests.

Do not label this checkpoint production-ready or final.
