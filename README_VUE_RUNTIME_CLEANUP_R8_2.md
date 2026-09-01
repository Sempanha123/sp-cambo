# SP Cambo Vue Runtime Cleanup R8.2

R8.2 fixes the PowerShell parser bug in R8.1.

The failing expression used a multiline boolean condition. R8.2 removes that
pattern completely and uses simple nested `if` statements.

It is safe after the earlier partial runs. At this point only
`frontend/package.json` may already contain:

```json
"packageManager": "pnpm@11.22.0"
```

R8.2 detects that and continues.

## Apply

Close SP Cambo frontend/Vitest processes first if possible.

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

powershell -ExecutionPolicy Bypass -File .\APPLY_VUE_RUNTIME_CLEANUP_R8_2.ps1
```

## Verify

```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY_VUE_RUNTIME_CLEANUP_R8_2.ps1
```

The first important result is the Claude Code component smoke test. The old
failure was:

```text
Cannot read properties of null (reading 'ce')
```

inside Vue `renderSlot()` with two different runtime-core locations. R8.2
removes the parent/root Node dependency tree and keeps one frontend pnpm tree.
