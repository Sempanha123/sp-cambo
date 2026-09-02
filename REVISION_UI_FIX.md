# SP Cambo revision UI fix

This patch changes provider revision management so historical connections are easier to manage without breaking request/audit history.

## Behavior

- REVOKED revisions are hidden from the normal Providers view by default.
- `Show hidden (N)` reveals archived history when needed.
- Removing a revision with request history archives it (REVOKED + removed from route pools) instead of returning a delete error.
- Removing an unused revision still hard-deletes it.
- Active provider revisions cannot be removed.
- Revisions with ACTIVE requests cannot be removed until those requests finish.
- Edit remains available for all revisions. READY/active/historical revisions use the existing safe replacement flow.
- New connections no longer ask the admin to choose a unique revision number. The backend allocates the next immutable audit revision automatically.
- The Providers UI uses reusable `Connection 1`, `Connection 2`, ... names. Internal revision numbers are no longer shown in normal admin UI.
- Model Routing uses the same `Connection 1`, `Connection 2`, ... names in the selector and route cards; `R4`, `R5`, etc. are hidden from operators.
- Technical immutable revision IDs/versions still exist only in the backend for request history, audit, and safe replacement.

## Important audit rule

Database `route_version` values are intentionally NOT reused. Existing reservations and audit history may refer to them. Only the user-facing Connection number is reusable.

## Changed files

- backend/app/Http/Controllers/Api/V1/Admin/ProviderConnectionRevisionController.php
- backend/tests/Feature/Feature/Api/V1/AdminProviderRevisionTest.php
- frontend/app/types/admin.ts
- frontend/app/composables/useSpApi.ts
- frontend/app/pages/admin/providers/[id].vue
- frontend/app/pages/admin/route-pools.vue

## Production deployment

From the project root on the VPS after copying the changed files:

```bash
cd /var/www/sp-cambo/backend
php artisan optimize:clear
sudo systemctl restart sp-cambo-queue

cd /var/www/sp-cambo/frontend
pnpm install --frozen-lockfile
pnpm run build
sudo systemctl restart sp-cambo-frontend
```

If your deployment also runs the gateway, this patch does not change gateway source, so a gateway rebuild is not required for this UI change.
