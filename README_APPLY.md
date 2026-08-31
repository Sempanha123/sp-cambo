# SP Cambo Global UI R4

R4 is the first package that applies the new SP Cambo visual system to the
shared layouts, not only the homepage.

## What is global now

### Every route
Mounted once from `app.vue`:
- moving aurora
- neural/network lines
- particles
- technical grid
- moving light beams
- reduced-motion support

### Public pages
Through `default.vue` and `public.vue`:
- glass header/footer
- moving header accent
- global grid/orbs
- glass elevated surfaces
- improved cards/hover depth
- consistent technical lighting

This covers pages such as Home, Pricing, Models, Docs, Status, Key Checker and
other routes using those shared layouts.

### Auth
Through `auth.vue`:
- global animated background
- animated auth showcase
- glass sign-in/register/recovery area
- moving scan texture
- floating ambient orbs

### Dashboard / Admin / Reseller
Through `dashboard.vue`:
- calmer global motion
- glass sidebar
- highlighted active navigation
- global technical grid
- subtle cards/tables/focus effects
- Playground keeps a calmer readable surface

Admin and reseller are included because their navigation is already hosted by
the shared dashboard layout.

## Files

- `frontend/app/app.vue`
- `frontend/app/components/SpGlobalMotionBackground.vue`
- `frontend/app/assets/css/sp-global-r4.css`
- `frontend/app/layouts/default.vue`
- `frontend/app/layouts/public.vue`
- `frontend/app/layouts/auth.vue`
- `frontend/app/layouts/dashboard.vue`
- `frontend/app/pages/index.vue`

## Apply

Extract the ZIP over the SP Cambo project root and allow overwrite.

Then:

```powershell
cd frontend
npm run typecheck
npm run build
```

Recommended test routes:

```text
/
/pricing
/models
/docs
/status
/public/key-checker
/login
/register
/dashboard
/dashboard/buy
/dashboard/api-keys
/dashboard/playground
/admin
/reseller
```

R4 intentionally uses stronger motion on public/auth pages and a calmer visual
layer on dashboard/admin/reseller so tables, forms and chat remain readable.
