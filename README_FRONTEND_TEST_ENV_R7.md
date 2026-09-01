# SP Cambo Frontend Test Environment R7

The test summary:

```text
23 failed | 19 passed
```

matches SP Cambo's split test layout: component tests use the Nuxt runtime, while
unit tests use plain Node.

The current repository still uses one hybrid Vitest config with `environment:
'node'` as the default and per-file `// @vitest-environment nuxt` comments for
component tests.

Nuxt Test Utils v4 now recommends Vitest projects: one plain Node project for
unit tests and one `defineVitestProject()` project for Nuxt-runtime tests.

R7 moves SP Cambo to that structure without changing application behavior.

It also deletes only `frontend/.nuxt` and runs `nuxt prepare`, because stale
generated Nuxt test metadata can survive dependency/config changes.

## Apply

Extract over the project root:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_FRONTEND_TEST_ENV_R7.ps1
```

## Verify

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_FRONTEND_TEST_ENV_R7.ps1
```

Verification intentionally starts with one very small component test and then
`ResellerAllocation.spec.ts`. If either fails, its verbose output will contain
the actual root error instead of only the 654-test summary.

Do not edit hundreds of component assertions based only on the suite summary.
