# SP Cambo — Latest merged local UI build

Merged into the full Connection UI project:
- Connection-based provider UI and hidden historical connections
- Playground small-screen responsive controls
- API Key Checker auto-refresh, roomier balance cards, softer request hover, numeric request status
- Corrected light-mode index without whole-page fog/cover
- Register keeps original/default compact style; only unwanted form background removed
- Clean AuthCard.vue (no malformed style block)
- Google-like global UButton styling across the project

## Local run
```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo\frontend"
Remove-Item -Recurse -Force .nuxt -ErrorAction SilentlyContinue
pnpm dev
```
