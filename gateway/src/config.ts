import type { GatewayConfig } from "./types.js";

export function loadConfig(env: NodeJS.ProcessEnv = process.env): GatewayConfig {
  return {
    host: env.GATEWAY_HOST ?? "127.0.0.1",
    port: integer(env.GATEWAY_PORT ?? "3010", "GATEWAY_PORT", 1, 65_535),
    controlPlaneBaseUrl: privateUrl(required(env.CONTROL_PLANE_BASE_URL, "CONTROL_PLANE_BASE_URL"), "CONTROL_PLANE_BASE_URL"),
    internalSecret: secret(env.SP_CAMBO_INTERNAL_GATEWAY_SECRET, "SP_CAMBO_INTERNAL_GATEWAY_SECRET"),
    rateStore: rateStore(env.GATEWAY_RATE_STORE ?? "redis"),
    redisUrl: (env.GATEWAY_RATE_STORE ?? "redis").toLowerCase() === "memory"
      ? null
      : privateUrl(required(env.REDIS_URL, "REDIS_URL"), "REDIS_URL", ["redis:", "rediss:"]),
    maxBodyBytes: integer(env.GATEWAY_MAX_BODY_BYTES ?? "1048576", "GATEWAY_MAX_BODY_BYTES", 1024, 16_777_216),
    defaultMaxOutputTokens: integer(env.GATEWAY_DEFAULT_MAX_OUTPUT_TOKENS ?? "4096", "GATEWAY_DEFAULT_MAX_OUTPUT_TOKENS", 1, 1_000_000),
    upstreamTimeoutMs: integer(env.GATEWAY_UPSTREAM_TIMEOUT_MS ?? "120000", "GATEWAY_UPSTREAM_TIMEOUT_MS", 1000, 600_000),
    controlPlaneTimeoutMs: integer(env.GATEWAY_CONTROL_PLANE_TIMEOUT_MS ?? "5000", "GATEWAY_CONTROL_PLANE_TIMEOUT_MS", 500, 30_000),
  };
}

function required(value: string | undefined, name: string): string {
  if (value?.trim() === "") value = undefined;
  if (!value) throw new Error(`${name} is required.`);
  return value;
}

function secret(value: string | undefined, name: string): string {
  const configured = required(value, name);
  if (configured.length < 32) throw new Error(`${name} must contain at least 32 characters.`);
  return configured;
}

function integer(value: string, name: string, min: number, max: number): number {
  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed < min || parsed > max) throw new Error(`${name} is outside the supported range.`);
  return parsed;
}

function privateUrl(value: string, name: string, protocols: string[] = ["http:", "https:"]): string {
  const url = new URL(value);
  if (!protocols.includes(url.protocol)) throw new Error(`${name} uses an unsupported protocol.`);
  if (url.username || url.password || url.search || url.hash) throw new Error(`${name} must not contain credentials, query, or fragment.`);
  if (url.pathname !== "/" && url.pathname !== "") throw new Error(`${name} must be an origin without a path.`);
  if (!isPrivateHost(url.hostname)) throw new Error(`${name} must resolve through a private service-network host.`);
  return protocols.some((protocol) => protocol === "redis:" || protocol === "rediss:")
    ? url.toString().replace(/\/$/, "")
    : url.origin;
}

function isPrivateHost(host: string): boolean {
  const normalized = host.replace(/^\[|\]$/g, "").toLowerCase();
  if (normalized === "localhost" || normalized === "::1" || normalized.endsWith(".internal")) return true;
  if (/^10\./.test(normalized) || /^127\./.test(normalized) || /^192\.168\./.test(normalized)) return true;
  const match = normalized.match(/^172\.(\d+)\./);
  if (match && Number(match[1]) >= 16 && Number(match[1]) <= 31) return true;
  return /^[a-z0-9][a-z0-9-]{0,62}$/.test(normalized) && !normalized.includes(".");
}

function rateStore(value: string): "memory" | "redis" {
  const normalized = value.trim().toLowerCase();
  if (normalized !== "memory" && normalized !== "redis") throw new Error("GATEWAY_RATE_STORE must be memory or redis.");
  return normalized;
}
