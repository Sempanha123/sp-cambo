# SP Cambo Multi-Route Production R3

This package is a standalone upgrade built for the current
`Sempanha123/sp-cambo` main architecture. It does not require Route Pool R1 to
have been applied first.

## Goal

Keep one customer-facing model name while SP Cambo scales horizontally across:

- OmniRoute 1
- OmniRoute 2
- OmniRoute 3+
- multiple READY connection revisions
- multiple enabled providers
- multiple resale-verified private models

Customer requests remain:

```json
{
  "model": "claude-sonnet"
}
```

The customer never receives private provider names, origins, credentials,
revision IDs, or internal model IDs.

## Production routing

R3 implements:

1. Weighted least-connections
2. Any number of routes (admin API currently caps one pool at 50)
3. Cross-provider private model mapping
4. Per-public-model global concurrency
5. Per-route concurrency
6. Per-route weights
7. Route priorities
8. Pre-stream failover
9. Global route circuit breaker
10. Circuit cooldown and operator reset
11. Route health in `/admin/system-health`
12. Full routing management in `/admin/route-pools`
13. Legacy active-revision fallback while a pool is disabled
14. Existing SP Cambo reservation/billing remains the source of truth

## Safe failover rule

Automatic failover is allowed only before public output starts.

Retryable route failures:

- connection failure
- timeout before a usable response
- HTTP 408
- HTTP 429
- HTTP 500
- HTTP 502
- HTTP 503
- HTTP 504

After a successful response is accepted and streaming can begin, SP Cambo pins
the request to that route until completion/disconnect. It never stitches output
from two different providers.

## Circuit breaker

Each provider connection revision has shared route health.

Example defaults:

- failure threshold: 3
- cooldown: 30 seconds
- failover attempts per request: 2

If a route repeatedly fails, it is temporarily removed from selection. A later
successful request closes the circuit and clears the failure count.

## Scaling later

You do not redesign the website when traffic grows.

Example:

```text
Month 1
Public alias
  - OR1 max 10
  - OR2 max 10

Month 3
Public alias
  - OR1 max 20
  - OR2 max 20
  - OR3 max 20

Later
Public alias
  - Provider A / OR1
  - Provider A / OR2
  - Provider B / OR3
  - Provider C / OR4
```

The public model alias and customer API keys remain unchanged.

A new provider route must use:
- enabled provider
- enabled private model
- commercial resale verified
- READY connection revision
- successful connection probe

## Recommended first production values

For two independent routes:

```text
Weight                 100 / 100
Route concurrency      10 / 10
Global model cap       18
Failover attempts      1 or 2
Circuit threshold      3
Circuit cooldown       30 seconds
```

Leave headroom instead of setting global capacity to the exact sum.

## Apply locally

Extract over the project root, then:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_MULTI_ROUTE_R3.ps1
```

Backend:

```powershell
cd backend

php artisan migrate
php artisan optimize:clear
php artisan route:list --path=model-route-pools
php artisan route:list --path=internal/gateway
php artisan test
```

Gateway:

```powershell
cd ..\gateway

pnpm install --frozen-lockfile
pnpm run typecheck
pnpm run test
pnpm run build
```

Frontend:

```powershell
cd ..\frontend

npm run lint
npm run typecheck
npm run test
npm run build
```

Do not deploy until all three sections pass.

## Website smoke test before adding production OmniRoute nodes

Verify:

1. Register using email verification
2. Sign out / sign in
3. Buy a package
4. KHQR payment detection
5. API key delivery
6. API key status / model list
7. Playground inference
8. External API inference
9. Streaming response
10. Usage settlement / remaining balance
11. Telegram Store purchase
12. Admin provider probe
13. `/admin/route-pools` saves/reloads configuration
14. `/admin/system-health` reports routing health
15. Public `/status` does not expose private provider details

## Production deployment after tests pass

Deploy the tested Git commit first. Then:

```bash
cd /var/www/sp-cambo/backend
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Build/restart gateway using the existing SP Cambo gateway deployment process,
then:

```bash
cd /var/www/sp-cambo/frontend
npm run build
sudo systemctl restart sp-cambo-frontend
sudo systemctl status sp-cambo-frontend --no-pager
```

## Before enabling a route pool

Keep the pool disabled while adding infrastructure.

For each new route:
1. add provider/private model if needed
2. add connection revision
3. probe it
4. confirm READY + SUCCESS
5. add it to `/admin/route-pools`
6. set conservative concurrency
7. save with pool still disabled
8. test the route directly through admin probe
9. enable the pool
10. run a controlled concurrency test
11. watch `/admin/system-health`

## Important

More IP addresses do not automatically create more upstream quota. Capacity
increases only when the upstream service allows the independent provider
accounts/projects/routes you configure.

R3 is designed to use additional legitimate capacity safely, not to bypass an
upstream provider's limits.
