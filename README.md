# SP Cambo Google auth bearer-mode fix

SP Cambo Google OAuth callback issues a Laravel Sanctum **personal access token** (bearer token).
The frontend must therefore run with `NUXT_PUBLIC_SESSION_MODE=bearer`.

This patch changes unsafe deployment defaults from `cookie` to `bearer` in:

- `infra/compose.yaml`
- `infra/.env.example`
- `frontend/Dockerfile`

`frontend/nuxt.config.ts` and `frontend/.env.example` already default to bearer and do not need changes.

## Production runtime
Even after applying this patch, explicitly set the deployed environment to:

```env
NUXT_PUBLIC_SESSION_MODE=bearer
```

Then rebuild/restart the Nuxt frontend.
