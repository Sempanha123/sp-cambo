// Node 24+ can load .env without an extra dependency. This makes `pnpm dev`
// behave the way local developers expect. Existing process variables still win.
try { process.loadEnvFile?.(".env"); } catch { /* deployment may inject env without a file */ }

import { buildApp } from "./app.js";
import { loadConfig } from "./config.js";
import { HttpControlPlane } from "./control-plane.js";
import { MemoryRateStore, RedisRateStore } from "./rate-store.js";

const config = loadConfig();
const app = buildApp(config, {
  controlPlane: new HttpControlPlane(config),
  rateStore: config.rateStore === "memory"
    ? new MemoryRateStore()
    : new RedisRateStore(config.redisUrl!),
});

const shutdown = async (): Promise<void> => {
  await app.close();
  process.exit(0);
};
process.once("SIGTERM", shutdown);
process.once("SIGINT", shutdown);

await app.listen({ host: config.host, port: config.port });
