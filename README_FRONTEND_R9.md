# SP Cambo Frontend Contract R9

R8.3 fixed the shared Vue runtime problem: the component suite is now actually
running application assertions. The latest result is much healthier:

```text
23 component files
15 passed
8 failed

273 component tests
251 passed
22 failed
```

The remaining failures are no longer one global Vue crash.

## R9 fixes actual application regressions

- Playground localStorage access is now safe when storage is unavailable.
- Playground shows the selected customer protocol again.
- Public Models shows the complete credit-pricing disclosure, not only three
  compact rows.
- Reasoning fallback billing explicitly says it uses the output rate and is not free.
- Cache read/write keep their exact billing labels.
- Stated-false API surfaces remain visible as unsupported.
- A model with no stated protocol shows a `model_unavailable` warning.
- Chat Completions is labeled `Chat Completions API`.
- API key re-copy text explains that the encrypted secret can be securely fetched.
- Pending entitlement lots explicitly say `Not active yet`.
- Admin pricing keeps SP reference-cost wording while also naming upstream cost.

## R9 also updates two stale test contracts

These are intentional production changes, not bugs:

- API-key 30-day usage is lazy-loaded instead of blocking first paint.
- Playground streaming now receives a third `AbortSignal` argument so Stop can
  cancel the active request.
- The Playground empty-state headline is now `What can I help you build?`.

## Apply

Extract this ZIP over the project root, then run with Node (not PowerShell):

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo"

node .\FIX_FRONTEND_R9.mjs
```

Expected:

```text
[PASS] R9 source/test contract fixes applied.
```

## Verify

```powershell
node .\VERIFY_FRONTEND_R9.mjs
```

Verification starts only with the directly fixed test files, then runs the whole
Nuxt component project, unit project, lint, typecheck and build.

If verification stops, send only the first failing test block.
