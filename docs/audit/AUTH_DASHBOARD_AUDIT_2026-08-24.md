# SP Cambo Authentication & Dashboard Audit — 2026-08-24

## Scope

Focused audit of the reported failures:

- local account registration/login
- Google login and Google account linking
- dashboard account/settings visual inconsistency
- navigation/back behavior around account pages

## Critical findings fixed

### P0 — Google redirect response did not match the frontend API envelope

`useSpApi()` unwraps Laravel responses from `{ data: ... }`, but the Google redirect controller returned `{ url: ... }` directly. `GoogleLoginButton` therefore received `undefined` instead of `{ url }`.

**Fix:** Google redirect now returns `{ data: { url } }`.

### P0 — Google callback method/flow was incompatible with the frontend

The frontend posted `code` + `state` to `POST /api/v1/auth/google/callback`, but Laravel exposed the callback as GET and expected a server-side web session. In bearer mode the cross-origin redirect bootstrap did not establish a dependable Laravel browser session.

**Fix:** OAuth now uses a short-lived encrypted state payload. Google redirects to the Nuxt callback page, which posts `code` + `state` to Laravel. No SP Cambo bearer token is placed in the redirect URL.

### P0 — Google users were always treated as inactive

`User.status` is cast to `AccountStatus`, but Google login compared it with the string `'ACTIVE'`. The enum/string comparison was always unequal.

**Fix:** compare with `AccountStatus::Active`.

### P0 — Google-created users attempted to store a null password

The `users.password` column is non-null, while the Google controller created users with `password => null`.

**Fix:** Google-created users receive a random local password. They can establish their own known password later through the password-reset flow.

### P0 — Google link route pointed to a method that did not exist

`POST /api/v1/auth/google/link` referenced `GoogleAuthController::link`, but that method was missing.

**Fix:** implemented authenticated link exchange and made the frontend callback choose login vs link safely using non-sensitive sessionStorage intent.

### P0 — Registration could 500 when CUSTOMER role was not seeded

Registration required a pre-existing `CUSTOMER` role and intentionally returned 500 if the seed had not run.

**Fix:** registration now safely bootstraps the canonical `CUSTOMER / Customer` role on a migrated database. Full permission seeders are still required for admin/reseller roles.

## Dashboard/UI findings fixed

### P1 — `/dashboard/account` did not declare dashboard layout/auth middleware

It was the only dashboard page without `definePageMeta({ layout: 'dashboard', middleware: ['auth'] })`. This explains why Account looked different from the rest of the dashboard and could destabilize navigation when moving back and forth.

**Fix:** Account now uses the same dashboard shell and page frame as every other dashboard page.

### P1 — Account page linked to routes that do not exist

The page linked to:

- `/dashboard/account/password`
- `/dashboard/account/sessions`

Neither page exists.

**Fix:** password change and active-session management are now implemented directly on `/dashboard/account`.

### P1 — Connected identities were never displayed

`useSpApi()` already unwraps `{ data }`, but the account page attempted `result.data`. It therefore discarded the returned identity array.

**Fix:** consume the unwrapped array directly.

### P1 — `/dashboard/settings` used a different page structure and fake Google actions

Settings did not use `SpDashboardPage` and contained simulated Google link/unlink success paths.

**Fix:** settings now uses the standard dashboard page frame and real profile update API. Google/security actions are centralized on Account & security.

### P1 — Two other dashboard API calls double-unwrapped `data`

The API-key usage summary and claim-key page destructured `{ data }` even though `useSpApi()` already returns the inner value.

**Fix:** both now use the returned value directly.

## Google OAuth configuration required

The code now expects Google to redirect to the frontend callback:

`http://localhost:3000/auth/google/callback`

For local development, add this exact URI to the Google Cloud OAuth client's **Authorized redirect URIs**.

The backend environment now uses:

`GOOGLE_REDIRECT_URI=http://localhost:3000/auth/google/callback`

For production, replace localhost with the real HTTPS frontend origin and register that exact URI in Google Cloud.

## Database readiness required

Before testing registration/login against the configured database, run from `backend/`:

```powershell
php artisan optimize:clear
php artisan migrate --seed
```

Then verify:

```powershell
php artisan migrate:status
php artisan route:list --path=api/v1/auth
```

The SQLite file bundled in the uploaded project contains only the three baseline Laravel migrations, while the supplied `.env` points to MySQL. The SQLite file therefore appears stale/non-authoritative; the configured MySQL database still needs `migrate:status` checked on the user's machine.

## Local acceptance checklist

1. Open `http://localhost:3000/register` and create a new email/password account.
2. Confirm redirect to `/dashboard` and refresh the page; session should remain valid.
3. Sign out, sign back in with that account, then use browser Back/Forward between dashboard pages.
4. Open `/dashboard/account`; it should retain the dashboard sidebar/navbar and no longer navigate to missing password/session routes.
5. On the login page choose Continue with Google.
6. Google should return to `http://localhost:3000/auth/google/callback`, then SP Cambo should exchange the code and enter `/dashboard`.
7. From `/dashboard/account`, link Google to an existing local account and confirm the identity appears after return.
8. From `/dashboard/settings`, update the profile name and confirm the dashboard account menu updates.

## Verification performed in this audit

- PHP syntax check passed for the modified Google controller, registration controller, and API routes.
- Static dashboard scan confirms every `/dashboard/**` page now declares page metadata and uses the common `SpDashboardPage` frame.
- Static scan confirms the known `result.data` / `const { data } = await api...` double-unwrapping mistakes were removed.
- Full Laravel/PHPUnit execution was not possible in this sandbox because `backend/vendor` is not present.
- Full Nuxt typecheck/build was not possible because `frontend/node_modules` is not present and the sandbox cannot download pnpm packages.

## Remaining external verification

- Google Cloud OAuth client configuration must be updated with the new redirect URI.
- The configured MySQL database must have all migrations/seeders applied.
- Real Google OAuth and real browser back/forward behavior must be tested on the user's running local stack.
