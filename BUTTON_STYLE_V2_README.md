# SP Cambo Button Style V2

This version fixes the previous global-button implementation.

## What changed

- Every Nuxt UI `UButton` receives explicit marker classes from `frontend/app/app.config.ts`.
- The button CSS is imported directly by `frontend/app/app.vue`.
- No plugin-based CSS loading is required.
- Solid buttons use the clean white / near-white style inspired by **Continue with Google**.
- Primary, secondary, success, info, warning and error actions use only a subtle accent rim/glow — no heavy navy/blue fill.
- Outline/soft/subtle buttons use a quiet glass surface.
- Ghost/icon buttons remain lightweight.
- Existing pages/layouts are otherwise unchanged.

## Local test

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo\frontend"
Remove-Item -Recurse -Force .nuxt -ErrorAction SilentlyContinue
pnpm dev
```

Then hard refresh with `Ctrl + Shift + R`.
