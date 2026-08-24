import { Redis } from "ioredis";
import { GatewayError } from "./errors.js";
import type { Limits, RateLease, RateStore } from "./types.js";

type Entry = { window: number; requests: number; tokens: number; concurrency: number };

export class MemoryRateStore implements RateStore {
  private readonly entries = new Map<string, Entry>();

  async admit(identity: string, requestsPerMinute: number): Promise<void> {
    const window = Math.floor(Date.now() / 60_000);
    const key = `admission:${identity}`;
    const entry = this.entries.get(key) ?? { window, requests: 0, tokens: 0, concurrency: 0 };
    if (entry.window !== window) Object.assign(entry, { window, requests: 0, tokens: 0 });
    if (entry.requests >= requestsPerMinute) throw limitError("rpm");
    entry.requests += 1;
    this.entries.set(key, entry);
  }

  async acquire(keyId: string, limits: Limits, estimatedTokens: number): Promise<RateLease> {
    const window = Math.floor(Date.now() / 60_000);
    const entry = this.entries.get(keyId) ?? { window, requests: 0, tokens: 0, concurrency: 0 };
    if (entry.window !== window) Object.assign(entry, { window, requests: 0, tokens: 0 });
    enforce(entry, limits, estimatedTokens);
    entry.requests += 1;
    entry.tokens += estimatedTokens;
    entry.concurrency += 1;
    this.entries.set(keyId, entry);
    let released = false;
    return { release: async () => { if (!released) { entry.concurrency = Math.max(0, entry.concurrency - 1); released = true; } } };
  }

  async close(): Promise<void> {}
}

export class RedisRateStore implements RateStore {
  private readonly redis: Redis;

  constructor(url: string) {
    this.redis = new Redis(url, { lazyConnect: true, maxRetriesPerRequest: 1, enableOfflineQueue: false, connectTimeout: 2000 });
  }

  async admit(identity: string, requestsPerMinute: number): Promise<void> {
    try {
      if (this.redis.status === "wait") await this.redis.connect();
      const key = `spcambo:gateway:admission:${identity}`;
      const count = Number(await this.redis.eval(ADMISSION_SCRIPT, 1, key));
      if (count > requestsPerMinute) throw limitError("rpm");
    } catch (error) {
      if (error instanceof GatewayError) throw error;
      throw new GatewayError(503, "rate_limiter_unavailable", "The rate limiter is temporarily unavailable.");
    }
  }

  async acquire(keyId: string, limits: Limits, estimatedTokens: number): Promise<RateLease> {
    try {
      if (this.redis.status === "wait") await this.redis.connect();
      const key = `spcambo:gateway:limits:${keyId}`;
      const now = Date.now();
      const result = await this.redis.eval(ACQUIRE, 1, key, String(now), String(estimatedTokens), nullable(limits.requests_per_minute), nullable(limits.tokens_per_minute), nullable(limits.concurrency)) as [number, string];
      if (Number(result[0]) !== 1) throw limitError(result[1]);
      let released = false;
      return { release: async () => {
        if (released) return;
        released = true;
        try { await this.redis.eval(RELEASE, 1, key); } catch { /* TTL prevents a permanent leak. */ }
      } };
    } catch (error) {
      if (error instanceof GatewayError) throw error;
      throw new GatewayError(503, "rate_limiter_unavailable", "The rate limiter is temporarily unavailable.");
    }
  }

  async close(): Promise<void> { if (this.redis.status !== "end") this.redis.disconnect(); }
}

function enforce(entry: Entry, limits: Limits, estimated: number): void {
  if (limits.requests_per_minute !== null && entry.requests >= limits.requests_per_minute) throw limitError("rpm");
  if (limits.tokens_per_minute !== null && entry.tokens + estimated > limits.tokens_per_minute) throw limitError("tpm");
  if (limits.concurrency !== null && entry.concurrency >= limits.concurrency) throw limitError("concurrency");
}

function limitError(kind: string): GatewayError {
  return kind === "concurrency"
    ? new GatewayError(429, "concurrency_limit_exceeded", "The API key concurrency limit was exceeded.", { "retry-after": "1" })
    : new GatewayError(429, "rate_limit_exceeded", "The API key rate limit was exceeded.", { "retry-after": "60" });
}

function nullable(value: number | null): string { return value === null ? "-1" : String(value); }

export const ADMISSION_SCRIPT = `local count = redis.call('INCR', KEYS[1]); if count == 1 then redis.call('PEXPIRE', KEYS[1], 60000) end; return count;`;
const ACQUIRE = `
local now = tonumber(ARGV[1]); local estimated = tonumber(ARGV[2]);
local rpm = tonumber(ARGV[3]); local tpm = tonumber(ARGV[4]); local concurrent = tonumber(ARGV[5]);
local start = tonumber(redis.call('HGET', KEYS[1], 'start') or '0');
if start == 0 or now - start >= 60000 then redis.call('HMSET', KEYS[1], 'start', now, 'requests', 0, 'tokens', 0) end;
local requests = tonumber(redis.call('HGET', KEYS[1], 'requests') or '0');
local tokens = tonumber(redis.call('HGET', KEYS[1], 'tokens') or '0');
local active = tonumber(redis.call('HGET', KEYS[1], 'active') or '0');
if rpm >= 0 and requests >= rpm then return {0, 'rpm'} end;
if tpm >= 0 and tokens + estimated > tpm then return {0, 'tpm'} end;
if concurrent >= 0 and active >= concurrent then return {0, 'concurrency'} end;
redis.call('HINCRBY', KEYS[1], 'requests', 1); redis.call('HINCRBY', KEYS[1], 'tokens', estimated); redis.call('HINCRBY', KEYS[1], 'active', 1); redis.call('PEXPIRE', KEYS[1], 900000); return {1, 'ok'};
`;
const RELEASE = `local active = tonumber(redis.call('HGET', KEYS[1], 'active') or '0'); if active > 0 then redis.call('HINCRBY', KEYS[1], 'active', -1) end; return 1;`;
