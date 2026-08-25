# syntax=docker/dockerfile:1.7
FROM node:24.10-alpine AS build
RUN corepack enable && corepack prepare pnpm@11.22.0 --activate
WORKDIR /app
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY . .
# Public endpoints are baked into the client bundle at build time, so each name
# must match its Nuxt runtime-config property exactly: Nuxt silently ignores a
# near-miss and keeps the loopback development default. SP_CAMBO_STRICT_PUBLIC_CONFIG
# makes `nuxt build` fail rather than ship an unreachable customer endpoint.
ARG NUXT_PUBLIC_API_BASE_URL=https://api.spcambo.com/api/v1
ARG NUXT_PUBLIC_INFERENCE_ROOT_URL=https://api.spcambo.com
ARG NUXT_PUBLIC_SITE_URL=https://spcambo.com
ARG NUXT_PUBLIC_SESSION_MODE=cookie
# Optional support channel: an email address or an http(s) URL. Empty by default, and
# empty is a valid deployment — the surfaces that mention support simply show no
# link. A value that is neither is rejected by the strict check below rather than
# silently showing nothing. Unlike the endpoints above this is not baked into any
# customer instruction, so it can also be changed by setting the same variable on the
# running container: Nitro reads NUXT_PUBLIC_* when the server boots.
ARG NUXT_PUBLIC_SUPPORT_URL=
ARG NUXT_PUBLIC_TELEGRAM_BOT_USERNAME=
ENV NUXT_PUBLIC_API_BASE_URL=$NUXT_PUBLIC_API_BASE_URL
ENV NUXT_PUBLIC_INFERENCE_ROOT_URL=$NUXT_PUBLIC_INFERENCE_ROOT_URL
ENV NUXT_PUBLIC_SITE_URL=$NUXT_PUBLIC_SITE_URL
ENV NUXT_PUBLIC_SESSION_MODE=$NUXT_PUBLIC_SESSION_MODE
ENV NUXT_PUBLIC_SUPPORT_URL=$NUXT_PUBLIC_SUPPORT_URL
ENV NUXT_PUBLIC_TELEGRAM_BOT_USERNAME=$NUXT_PUBLIC_TELEGRAM_BOT_USERNAME
ENV SP_CAMBO_STRICT_PUBLIC_CONFIG=1
RUN pnpm build

FROM node:24.10-alpine
ENV NODE_ENV=production
WORKDIR /app
COPY --from=build --chown=node:node /app/.output ./.output
USER node
EXPOSE 3000
# NUXT_INTERNAL_API_BASE_URL is read at runtime, not baked in: server rendering
# uses it to reach the control plane over the private network.
CMD ["node", ".output/server/index.mjs"]
