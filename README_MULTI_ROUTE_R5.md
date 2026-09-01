# SP Cambo Multi-Route Production R5

R5 is the recovery/finalizer for the partially-applied R3/R4 state.

## What the GitHub check found

Current `main` already contains the new billing route selector and the
`ModelRoutePoolServiceProvider`, but two live integration points were still
missing:

1. Admin navigation did not expose `/admin/route-pools`.
2. `gateway/src/app.ts` still made only one upstream attempt.

The frontend pnpm configuration also contained literal placeholder values:

```yaml
'@parcel/watcher': set this to true or false
unrs-resolver: set this to true or false
vue-demi: set this to true or false
```

Those placeholders are the reason pnpm reported:

```text
ERR_PNPM_IGNORED_BUILDS
```

R5 replaces them with explicit build permissions for the Nuxt tooling
dependencies already present in the lockfile.

## Why the previous script stopped

R3 expected one exact navigation text block. Your local navigation file had
already drifted from that exact text, so the script intentionally stopped before
the gateway patch. That is why R4 later reported:

```text
gateway pre-stream reroute support
```

missing.

R5 no longer depends on the R3 script and inserts the navigation item by locating
the Promotions item boundary instead.

## Apply

Extract R5 over:

```text
C:\Users\Rg Gear\Desktop\SP Cambo
```

Then:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_MULTI_ROUTE_R5.ps1
```

The script is safe against your partial state. Existing billing/provider changes
are detected and kept.

## Fix the EBUSY / node_modules lock

Your EBUSY error means Windows has `frontend\node_modules\.bin\vitest.ps1`
open in another process.

Close:

- `npm run dev` / `pnpm dev`
- Vitest watch terminals
- VS Code test runners using this project

Do not run R3/R4 again.

Then prepare dependencies:

```powershell
powershell -ExecutionPolicy Bypass -File .\PREPARE_NODE_DEPS_R5.ps1
```

If EBUSY still appears, close the locking process and run a clean frontend
install:

```powershell
powershell -ExecutionPolicy Bypass -File .\PREPARE_NODE_DEPS_R5.ps1 -CleanFrontend
```

The helper does not kill Node processes automatically.

## Verify

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_MULTI_ROUTE_R5.ps1
```

It does **not** reinstall dependencies, so it will not repeatedly hit the
`vitest.ps1` EBUSY problem.

It verifies:

- Laravel migration plan
- admin route-pool routes
- internal gateway reroute routes
- route-pool selector tests
- full Laravel tests
- gateway typecheck/tests/build
- explicit failover tests
- frontend lint/typecheck/tests/build

Do not deploy until it ends with:

```text
[PASS] SP Cambo Multi-Route R5 verification completed.
```

## Expected routing after R5

```text
customer public alias
        |
        v
Laravel route pool
weighted least-connections
        |
   +----+----+------+
   |         |      |
  OR1       OR2    OR3+
   |         |      |
private model/provider routes
```

On connection failure or HTTP 408/429/500/502/503/504 before public output,
the gateway can request another healthy route. Once streaming starts, the route
is pinned for the rest of that response.

## Git

After verification:

```powershell
git status --short
git add .
git commit -m "fix: complete multi-route production failover"
git push origin main
```

Do not force-push if the remote has moved.
