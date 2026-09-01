# SP Cambo Login / Registration Session Fix R1

## What this fixes

The repository currently has two different browser-session expectations:

- `frontend/nuxt.config.ts` says **bearer** is the currently implemented default.
- `frontend/.env.example` uses **bearer**.
- `infra/.env.example` and `infra/compose.yaml` use **cookie** for production.
- `LoginController` and `RegisterController` previously decided whether to create
  a cookie session by checking `$request->attributes->get('sanctum') === true`.

That attribute is not a reliable transport signal for this application. In cookie
mode the backend could return a bearer token instead of creating the Laravel web
session. Nuxt cookie mode ignores that bearer token, so the user can appear to
click Sign in successfully and then remain/bounce back to `/login`.

R1 detects cookie mode from the request Nuxt already sends:
`X-XSRF-TOKEN` + a real session + Sanctum's stateful-origin check.

Bearer mode continues to receive a personal-access token.

## Files

Replace these two files in the project:

- `backend/app/Http/Controllers/Api/V1/Auth/LoginController.php`
- `backend/app/Http/Controllers/Api/V1/Auth/RegisterController.php`

## After applying on local machine

```powershell
cd "C:\Users\Rg Gear\Desktop\SP Cambo\backend"
php artisan optimize:clear
php artisan test
```

Then test:

1. Create/register a test account.
2. Sign out.
3. Sign in again.
4. Confirm the browser reaches `/dashboard`.
5. Refresh `/dashboard` and confirm it stays signed in.

## Production

After the code is deployed:

```bash
cd /var/www/sp-cambo/backend
php artisan optimize:clear
sudo systemctl restart sp-cambo-frontend
```

Also restart the PHP/backend service used by your VPS (PHP-FPM/container/etc.).

If production uses cookie mode, verify the non-secret values are aligned with
your actual domain:

- `NUXT_PUBLIC_SESSION_MODE=cookie`
- `SANCTUM_STATEFUL_DOMAINS` includes the frontend host (include port only when non-standard).
- `SESSION_DOMAIN` is valid for the frontend/API host arrangement.
- HTTPS is used when `SESSION_SECURE_COOKIE=true`.

Do not paste `.env` secrets into chat.

## Quick temporary workaround

If you need an immediate recovery before deploying this backend fix, change the
frontend to `NUXT_PUBLIC_SESSION_MODE=bearer`, restart the Nuxt service, and test
again. The existing backend already issues bearer tokens correctly.
