# SP Cambo V2 — Progress 3 (2026-08-24)

This checkpoint continues `SPCAMBO-2026-08-24-FULL-V2-PROGRESS-2` and is **not** the final production package.

## Completed in this pass

### Server-side Playground + daily free quota

- Added authenticated `GET /api/v1/me/playground/quota` and `POST /api/v1/me/playground/run`.
- The Playground browser no longer needs a customer API-key secret to run a test. Laravel keeps a system-managed Playground credential encrypted at rest and calls the same SP Cambo inference gateway used by normal customers.
- Added one idempotent daily `TOKEN_QUOTA` entitlement lot per user, configurable through `SP_CAMBO_PLAYGROUND_DAILY_TOKEN_QUOTA` (default 20,000 tokens) and expiring at the next application-day boundary.
- Playground usage uses the ordinary gateway reservation/settlement path, so it cannot bypass metering, model authorization, route selection, or quota exhaustion.
- Playground execution is non-streaming, output-capped to 2,048 tokens, concurrency-limited, and rate-limited separately from normal customer keys.
- The dashboard Playground now shows remaining daily quota, reset time, a real **Run free test** action, the returned request id, and the real gateway response. Existing cURL/SDK/CLI request-builder functionality is preserved.

### Redeem/free-token codes

- Added `redeem_codes` and `redeem_code_redemptions` with HMAC lookup digests; plaintext redeem secrets are shown only when an operator issues a code.
- Added admin APIs to list, issue, and enable/disable/update redeem codes.
- Added authenticated customer redemption with global limit, per-user limit, start/end windows, idempotency, model scope, duration, and normal entitlement-ledger creation.
- Added a customer redeem-code surface directly on **Dashboard → Entitlements**; successful redemption refreshes balances/lots immediately.
- Redeemed grants are normal entitlement lots (`source_type=REDEEM_CODE`) and therefore use the same FEFO reservation/settlement logic as purchased quota.

### Admin-selected OmniRoute route pinning

- Added the missing `Provider::activeConnectionRevision()` relation.
- Inference preflight now resolves the alias → private model → provider → **admin-selected READY connection revision** before reserving quota.
- Every reservation now pins `provider_connection_revision_id`, `route_version`, and the private `internal_model_id` in its immutable billing snapshot.
- Idempotent retries reuse the pinned route rather than silently following a later admin change.
- The control plane now returns pinned route metadata to the gateway, and the gateway uses the returned private model id and route revision/version headers rather than its previously unused in-memory route cache.
- The gateway still talks only to its configured private OmniRoute origin/key; provider secrets are not returned to the browser or customer.

## Configuration added

Backend `.env` / secret manager:

```dotenv
SP_CAMBO_GATEWAY_BASE_URL=http://127.0.0.1:3010
SP_CAMBO_PLAYGROUND_DAILY_TOKEN_QUOTA=20000
SP_CAMBO_PLAYGROUND_TIMEOUT_SECONDS=90
SP_CAMBO_REDEEM_CODE_LOOKUP_SECRET=
```

Use a separate high-entropy production value for `SP_CAMBO_REDEEM_CODE_LOOKUP_SECRET`.

## Validation performed

- PHP syntax validation passed for every PHP file under `backend/app`, `backend/routes`, and `backend/database/migrations` after these changes.
- TypeScript parser smoke-checks found no syntax/parser errors in the modified Nuxt scripts; full Nuxt typecheck remains unavailable because dependencies are not bundled and dependency installation timed out in this runner.
- Gateway source was statically updated to consume the pinned route returned by the control plane. Full gateway typecheck/tests remain unavailable because dependencies are not bundled and the gateway declares Node >=24 while this runner has Node 22.
- Full Laravel runtime tests remain unavailable because `backend/vendor` is not bundled.

## Still required before FINAL

- Paid-plan upgrade UX/semantics beyond safe FEFO lot stacking.
- Telegram bot plan → KHQR verification → entitlement → SP Cambo key/base URL/model delivery.
- Admin redeem-code management UI (the production API exists; customer redemption UI exists).
- Collapsed-sidebar final visual acceptance/polish.
- Full Laravel, Nuxt, and gateway automated suites on a dependency-complete Node 24+/PHP environment.
- Real integration smoke tests against MySQL/Redis, gateway, Bakong, and private OmniRoute.

Do not label this checkpoint production-ready or final.
