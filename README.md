# Playground mobile patch R1.1

The earlier patch was malformed. This version is a standard unified diff with
valid hunk ranges and context lines.

Run from the SP Cambo repository root:

```powershell
git apply --check .\playground-mobile-fix-v2.patch
git apply .\playground-mobile-fix-v2.patch

cd frontend
npm run typecheck
npm run build
```
