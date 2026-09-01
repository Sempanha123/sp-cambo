# SP Cambo Frontend Contract R9.1

R9.1 fixes the R9 apply failure:

```text
Could not find expected source for Playground safe localStorage helper
```

The issue was the patcher: R9 used exact multiline matching, so Windows CRLF /
small local drift caused it to stop before applying any real changes.

R9.1 uses regex/marker-based matching and normalizes line endings internally.
It is idempotent and safe after the previous partial R8/R9 work.

## Apply

Extract over the SP Cambo project root, then:

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

node .\FIX_FRONTEND_R9_1.mjs
```

Expected:

```text
[PASS] R9.1 source/test contract fixes applied.
```

## Verify

```powershell
node .\VERIFY_FRONTEND_R9_1.mjs
```

The verifier starts with the exact files R9.1 changes and only then runs all
component tests, unit tests, lint, typecheck and build.
