# SP Cambo Inference Gateway

Thin TypeScript/Fastify data plane for the public `/v1/*` inference API. Laravel remains the only billing authority; OmniRoute remains private.

## Runtime

- Node.js 24+
- pnpm 11.22.0
- Redis for production/multi-instance rate limiting (optional in local memory mode)
- reachable private Laravel and provider services

Install and verify:

```bash
npx pnpm@11.22.0 install --frozen-lockfile
npx pnpm@11.22.0 test
npx pnpm@11.22.0 typecheck
npx pnpm@11.22.0 build
```

Required local environment:

```dotenv
GATEWAY_HOST=127.0.0.1
GATEWAY_PORT=3010
CONTROL_PLANE_BASE_URL=http://127.0.0.1:8001
SP_CAMBO_INTERNAL_GATEWAY_SECRET=
GATEWAY_RATE_STORE=memory
REDIS_URL=redis://127.0.0.1:6379
```

Provider origin, credential and private model are selected in SP Cambo Admin. The authenticated Laravel preflight sends that routing material privately to the gateway for each reservation, so customers never receive it and the gateway no longer needs a duplicate OmniRoute token in `.env`.


The service fails closed when credentials are absent or service URLs are not private. Customer authorization headers are parsed by the gateway but are never copied to OmniRoute. Prompts and responses are not logged.

## KHQR private sidecar

`pnpm khqr` runs the backend-only KHQR generator on `127.0.0.1:3011` by default. It wraps the pinned `bakong-khqr` SDK and accepts only `POST /v1/khqr/generate` with the private `BAKONG_KHQR_GENERATOR_SECRET`. Configure Laravel with:

```dotenv
BAKONG_KHQR_GENERATOR_URL=http://127.0.0.1:3011/v1/khqr/generate
BAKONG_KHQR_GENERATOR_SECRET=
```

Never expose the KHQR sidecar to the browser or public network.
