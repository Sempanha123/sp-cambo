# SP Cambo Vue Runtime Cleanup R8.1

R8.1 fixes the R8 apply-script failure on `START_ALL.ps1`.

The previous script required one exact multiline text block. The current
`START_ALL.ps1` still uses npm for the frontend, but small formatting differences
made the exact match fail.

R8.1 uses the stable section markers:

```text
[6/7] Nuxt frontend :3000
[7/7] Laravel scheduler
```

and edits only the frontend dependency/start commands inside that section.

It is safe after the partial R8 run. `frontend/package.json` already received
`"packageManager": "pnpm@11.22.0"`; R8.1 detects that and continues.

## Run

Close frontend dev servers and Vitest watchers first.

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_VUE_RUNTIME_CLEANUP_R8_1.ps1
```

Then:

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_RUNTIME_CLEANUP_R8_1.ps1
```

The important first result is `ClaudeCodePage.spec.ts`. If the earlier
`renderSlot -> Cannot read properties of null (reading 'ce')` error disappears,
the duplicate Vue runtime was successfully removed.
