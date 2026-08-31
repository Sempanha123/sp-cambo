# SP Cambo Dashboard UI R11

R11 fixes the R10 collapsed-sidebar UX and makes the interaction much smoother.

## Main behavior

### Collapsed
- real sidebar width is 0
- a thin glowing left-edge rail remains visible
- a compact floating `Menu` handle remains visible

### Hover
- hovering the far-left edge opens a separate overlay preview
- the real `sidebarCollapsed` state DOES NOT change
- main content never shifts or jumps
- moving away closes the preview smoothly

### Click
- clicking the edge handle pins the real sidebar open
- the preview disappears

### Collapse again
- a dedicated `panel-left-close` button is always visible in the expanded sidebar header
- click it to collapse again
- no dependency on Nuxt UI's built-in collapse action

## Smoothness

- custom Vue transition
- opacity + slide + tiny scale
- dedicated cubic-bezier timing
- no layout mutation during hover preview
- actual pinned sidebar also has width transitions
- reduced-motion fallback

## Padding

R10's explicit shared page padding remains included:
- phone: 16px
- tablet: 20px
- desktop: 24px
- consistent direct-section vertical gaps
- safe-area bottom padding

## Full package

This ZIP carries forward R9 + R10 and replaces the dashboard layout/CSS with R11 behavior.

Primary changed files:
- frontend/app/layouts/dashboard.vue
- frontend/app/assets/css/sp-dashboard-r9.css

Also included:
- frontend/app/components/SpDashboardPage.vue
- frontend/app/components/SpMetric.vue
- frontend/app/components/SpPublicAliasIcon.vue
- frontend/app/pages/dashboard/index.vue
- frontend/app/pages/dashboard/usage.vue
- frontend/public/model-alias-icons/*.gif

## Apply

Extract over the project root, then:

```powershell
cd frontend
npm run typecheck
npm run build
```

Desktop test:
1. Open `/dashboard`
2. Click the sidebar collapse button
3. Sidebar becomes fully hidden
4. Hover the far-left edge — preview should slide in without moving content
5. Move away — preview closes
6. Hover again and click `Menu` — sidebar pins open
7. Click the `panel-left-close` button in its header — sidebar collapses again
