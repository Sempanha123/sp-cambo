# SP Cambo UI Refresh — 2026-08-24

## Scope

This refresh was applied on top of `SPCambo_Auth_Dashboard_Fixed_2026-08-24` and intentionally preserves the authentication/dashboard fixes from that build.

## Updated experience

- Reworked the public header into a glassy product shell with an explicit **Check API key** action.
- Removed API Key Checker from the authentication/login shell.
- Added the checker to the public homepage hero, homepage feature area, public header/mobile navigation, and footer.
- Rebuilt the authentication shell as a two-column desktop experience with a compact mobile layout.
- Added subtle Cambodian character with a warm-gold accent, Khmer copy, and lightweight geometric motifs while keeping the technical indigo/cyan identity.
- All decorative page backgrounds use normal scrolling (`background-attachment: scroll`) rather than fixed backgrounds.
- Refined dashboard shell/sidebar, dashboard page introductions, metric cards, account identity surface, and consistent page atmosphere.
- Refreshed public API Key Checker to use the same site design system instead of a separate light/gray page.
- Changed the public checker to accept the plaintext API key only in a POST form. The page no longer reads a key from URL query parameters or auto-checks from the URL.
- Changed dashboard "checker link" actions to share only `/public/key-checker`; keys/masked keys are not embedded into URLs.
- Kept every `/dashboard/**` page on the `dashboard` layout with `auth` middleware, which reduces the account-page/back-navigation layout mismatch that previously existed.

## Visual direction

The refresh uses:

- deep navy/slate application surfaces,
- SP Cambo indigo for primary actions,
- cyan for technical highlights,
- restrained warm gold as the Cambodian accent,
- CSS-only Khmer-inspired geometric patterning,
- Khmer microcopy such as `កម្ពុជា` and `បច្ចេកវិទ្យា AI សម្រាប់កម្ពុជា` as supporting identity rather than the main UI language.

No external image asset or custom font file is required. Khmer text falls back to the user's installed Khmer/system font.

## Static safety checks performed

- Confirmed all files under `app/pages/dashboard/**` declare `layout: 'dashboard'` and `middleware: ['auth']`.
- Confirmed the auth layout contains no API Key Checker link.
- Confirmed no `public/key-checker?key=...` URLs remain in the frontend source.
- Confirmed the checker page no longer reads `route.query.key`.
- Confirmed no fixed page background attachment was introduced.

## Local verification recommended

From `frontend/` with dependencies installed:

```powershell
pnpm typecheck
pnpm lint
pnpm test
pnpm build
pnpm dev
```

Then manually verify:

1. `/` desktop + mobile header and hero.
2. `/login` and `/register` in dark/light mode.
3. Browser Back/Forward between `/dashboard`, `/dashboard/account`, and `/dashboard/settings`.
4. Sidebar collapsed and expanded states.
5. `/public/key-checker` with a real test key.
6. Mobile navigation; ensure there is no floating/fixed Key Checker button.
