# SP Cambo Vue Direct Dependencies R8.3

R8.2 got through the cleanup and pnpm installation, then stopped only at:

```text
Could not resolve Vue from the frontend dependency tree.
```

That check exposed a second real package issue: `frontend/package.json` does not
declare `vue` directly.

Nuxt's minimal package.json includes:

- `nuxt`
- `vue`
- `vue-router`

SP Cambo component tests also import from `vue` directly, so making Vue a direct
frontend dependency is the correct ownership model with pnpm's strict
dependency layout.

R8.3 adds exact stable versions:

```text
vue        3.5.42
vue-router 5.3.0
```

and updates `frontend/pnpm-lock.yaml`.

## Apply

Do not rerun R8/R8.1/R8.2.

Run:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_VUE_DIRECT_DEPS_R8_3.ps1
```

Then:

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_DIRECT_DEPS_R8_3.ps1
```

The first important test remains `ClaudeCodePage.spec.ts`.
