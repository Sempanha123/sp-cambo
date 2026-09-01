# SP Cambo Route Pool R1

This package starts the multi-OmniRoute production architecture for the current
`Sempanha123/sp-cambo` main branch.

## What R1 does now

A customer still sends the same public model alias:

```json
{ "model": "claude-sonnet" }
```

SP Cambo can privately select between multiple READY provider connection
revisions for that alias:

```text
public alias
    |
    +-- Revision 1 / OmniRoute 1
    +-- Revision 2 / OmniRoute 2
```

The selection strategy is **weighted least connections**.

R1 adds:
- one route pool per public model alias
- many provider revisions per route pool
- per-route enable/disable
- per-route weight
- per-route concurrency cap
- global per-model concurrency cap
- DB-transaction locking so simultaneous preflights cannot all pick the same
  stale "least loaded" route
- legacy fallback to the provider's current active revision until a pool is
  explicitly enabled
- 429 when every enabled route is currently at capacity
- new admin page: `/admin/route-pools`

The existing gateway contract does not change in R1. Laravel still returns one
chosen private revision to the gateway, so customer model names, response format,
local metering and billing behavior stay unchanged.

## Why this helps

If OmniRoute 1 and OmniRoute 2 have independent authorized upstream capacity,
SP Cambo can spread simultaneous customer traffic between them instead of
sending every request to one connection revision.

A second IP by itself does not guarantee more provider quota. The real benefit
comes from genuinely independent capacity allowed by the upstream service.

## Apply safely

1. Extract this ZIP over the SP Cambo project root.
2. Run the merge script from the project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\APPLY_ROUTE_POOL_R1.ps1
```

The script patches only two small existing areas:
- `InferenceBillingService.php`
- `bootstrap/providers.php`

It stops with an error if your local file no longer matches the GitHub main
structure used to build this package.

3. Backend:

```powershell
cd backend
php artisan migrate
php artisan optimize:clear
php artisan route:list --path=model-route-pools
php artisan test
```

4. Frontend:

```powershell
cd ..\frontend
npm run typecheck
npm run build
```

## Configure

1. Create/probe Revision 1 and Revision 2 under the same provider.
2. Both revisions must show:
   - lifecycle: `READY`
   - last probe: `SUCCESS`
3. Open:
   - `/admin/route-pools`
4. Choose the public alias.
5. Enable both revisions.
6. Start with:
   - Weight: 100 / 100
   - Route concurrency: 10 / 10
   - Global model concurrency: 18
7. Save and test with concurrent requests.

The global cap slightly below the combined route caps leaves headroom for health
checks, admin probes and transient overlap.

## Production

After local tests/build pass and the tested commit is deployed:

```bash
cd /var/www/sp-cambo/backend
php artisan migrate --force
php artisan optimize:clear

cd /var/www/sp-cambo/frontend
npm run build
sudo systemctl restart sp-cambo-frontend
```

Restart the backend/PHP service used by your VPS as appropriate.

## R2 (next architecture step)

R1 performs live load balancing and capacity protection.

The next step is pre-stream failover + circuit breaker:
- if selected route returns 429/502/503/504 before response bytes, try another
  healthy route once
- temporarily open a route circuit after repeated failures
- never switch routes after streaming has started

That part should be added only after R1's route selection and reservation
behavior pass the existing backend/gateway test suites.
