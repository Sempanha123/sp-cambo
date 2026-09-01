# SP Cambo Vue Runtime Cleanup R8

## Root cause confirmed

The failing stack contains **two different Vue runtime-core locations**:

```text
../node_modules/@vue/runtime-core/dist/runtime-core.cjs.js
frontend/node_modules/.pnpm/@vue+runtime-core@3.5.40/...
```

From a test running in `frontend`, `../node_modules` means the repository-root
`node_modules`.

GitHub confirms why that directory exists:

- root `package.json` installs `@pinia/nuxt`
- root `package-lock.json` resolves Vue 3.5.41
- frontend's pnpm tree is using Vue 3.5.40
- frontend already declares `@pinia/nuxt`
- CI and the frontend Docker image already use pnpm

Vue render context is module-instance-local. If a slot is created with one Vue
runtime and rendered with another, Vue's current rendering instance can be null,
which produces errors such as:

```text
Cannot read properties of null (reading 'ce')
```

inside `renderSlot()`.

This is why even the tiny ClaudeCodePage test fails inside Nuxt UI's Container
component before its own assertions execute.

## R8 cleanup

R8 makes frontend dependency ownership unambiguous:

1. frontend is pnpm-only
2. removes repository-root package.json/package-lock.json
3. removes root node_modules
4. removes frontend/package-lock.json
5. updates START_ALL.ps1 to use pnpm for frontend
6. cleans frontend/node_modules + frontend/.nuxt
7. installs from frontend/pnpm-lock.yaml only
8. regenerates Nuxt
9. verifies Vue resolution before running component tests

## Apply

Close running SP Cambo frontend/Vitest processes first.

Then extract R8 over the project root and run:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_VUE_RUNTIME_CLEANUP_R8.ps1
```

If Windows reports EBUSY, close the Node/Vitest process that owns the file and
rerun the same R8 script.

## Verify

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_RUNTIME_CLEANUP_R8.ps1
```

The first real test is still ClaudeCodePage. If the duplicate Vue diagnosis is
correct, the `renderSlot ... reading 'ce'` error disappears before we touch any
component test assertions.

## Commit

After verification is green:

```powershell
git status --short
git add -A
git commit -m "fix: use one pnpm Vue runtime for frontend"
git push origin main
```

Do not restore the deleted root `package.json`, root `package-lock.json`, or
`frontend/package-lock.json`.
