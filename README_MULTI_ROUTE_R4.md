# SP Cambo Multi-Route Production R4

This finalizer is built for the current `Sempanha123/sp-cambo` main branch.

It fixes the remaining blockers in the Multi-Route R3 work:

- R3 implementation files existed, but the integration script had not been applied to the live source.
- `2026_09_01_170000_create_model_route_pools.php` and
  `2026_09_01_180000_create_scalable_model_route_pools.php` both attempted to
  create the same tables on a fresh database.
- The route-pool selector test still used the older service signature and did
  not provide `ai_model_id`.
- Frontend CI used Node 22 while the frontend package requires Node 24.15+.
- The final verification flow now uses pnpm consistently for gateway/frontend.

## What R4 keeps

Customers still use one public model alias. Private provider names, credentials,
origins, revisions and internal model IDs remain internal.

Routing supports:

- weighted least-connections
- multiple providers
- multiple private models
- multiple READY connection revisions
- per-route concurrency
- public-model global concurrency
- pre-stream failover
- circuit breaking and cooldown
- operator circuit reset
- route health reporting
- legacy active-revision fallback when a pool is disabled

Automatic route switching is still limited to the period before public output
starts. A streaming response is never stitched together from multiple upstream
providers.

## Apply

Extract this ZIP over the SP Cambo project root so these files replace the
matching migration/test files and the two PowerShell scripts appear at the root.

Then run:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_MULTI_ROUTE_R4.ps1
```

The R4 script calls the existing `APPLY_MULTI_ROUTE_R3.ps1`, verifies that the
billing/gateway/provider/navigation changes are present, aligns CI with Node 24,
and clears Laravel caches.

## Verify before deployment

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_MULTI_ROUTE_R4.ps1
```

The verification script checks:

1. Laravel migration plan
2. admin model-route-pool routes
3. internal gateway reroute routes
4. focused route-pool tests
5. full backend tests
6. gateway typecheck/tests/build
7. frontend lint/typecheck/tests/build

Do not deploy until this script finishes with `[PASS]`.

## Commit after verification

```powershell
git status --short
git add .
git commit -m "fix: complete scalable multi-route production routing"
git push origin main
```

If push is rejected because the remote moved, do not force-push. Pull/rebase
carefully and rerun verification.

## VPS after the tested commit is on main

```bash
cd /var/www/sp-cambo
git pull origin main

cd backend
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart

cd ../gateway
pnpm install --frozen-lockfile
pnpm run build

cd ../frontend
pnpm install --frozen-lockfile
pnpm run build
sudo systemctl restart sp-cambo-frontend
sudo systemctl status sp-cambo-frontend --no-pager
```

Restart the gateway using the existing SP Cambo gateway service/process after
its build succeeds.

## Important

R4 does not add, change, or require any `.env` secret.

The migration fix is intentionally upgrade-oriented: the first migration creates
the base route-pool tables only when missing, and the second migration upgrades
those tables instead of creating them again.
