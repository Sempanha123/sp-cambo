# SP Cambo

SP Cambo is a prepaid AI access platform with a Laravel control plane, Nuxt
customer/admin application, and a TypeScript inference gateway. The public API
uses stable model aliases while private provider models and credentials remain
inside the control plane.

## Multi-route production routing

One public model can route across multiple READY OmniRoute/provider revisions
using weighted least-connections, per-route and global concurrency limits,
circuit breaking, and failover before public output begins. Customers keep the
same model name in both requests and response metadata.

See [README_APPLY.md](README_APPLY.md) for configuration, rollout, rollback,
security, and smoke-test guidance.

## Applications

- `backend/` — Laravel API, billing reservations, route selection, and admin API
- `gateway/` — customer inference edge, protocol translation, and streaming
- `frontend/` — Nuxt customer and admin UI
- `infra/` — production Docker Compose and Nginx configuration

Run the backend, gateway, and frontend checks from their respective directories
before deployment. The same checks run in `.github/workflows/ci.yml` for pull
requests to `main`.
