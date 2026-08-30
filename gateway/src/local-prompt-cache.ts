import type { InferencePath } from "./types.js";
import type { PromptSegment } from "./protocol.js";

export type LocalPromptCacheResult = {
  input_tokens: number;
  cache_read_tokens: number;
  logical_input_tokens: number;
  cache_hit: boolean;
};

type CacheEntry = {
  segments: PromptSegment[];
  expiresAt: number;
  touchedAt: number;
};

/**
 * Provider-independent prompt-prefix cache meter.
 *
 * This class never receives provider usage. It stores only SHA-256 digests and
 * token counts produced from the public request received by SP Cambo. No prompt
 * or response text is retained. The cache is intentionally local to the SP
 * Cambo gateway and is used only to decide the customer's local cache discount.
 */
export class LocalPromptCache {
  private readonly entries = new Map<string, CacheEntry>();
  private operations = 0;

  constructor(
    private readonly ttlMs = 5 * 60_000,
    private readonly minimumCacheTokens = 1_024,
    private readonly maxEntries = 5_000,
  ) {}

  measure(
    keyId: string,
    path: InferencePath,
    publicModel: string,
    segments: PromptSegment[],
    logicalInputTokens: number,
    now = Date.now(),
  ): LocalPromptCacheResult {
    const cacheKey = `${keyId}:${path}:${publicModel}`;
    const previous = this.entries.get(cacheKey);
    let cached = 0;

    if (previous && previous.expiresAt > now) {
      const count = Math.min(previous.segments.length, segments.length);
      for (let index = 0; index < count; index++) {
        const left = previous.segments[index]!;
        const right = segments[index]!;
        if (left.digest !== right.digest || left.tokens !== right.tokens) break;
        cached += right.tokens;
      }
      cached = Math.min(cached, logicalInputTokens);
      if (cached < this.minimumCacheTokens) cached = 0;
    }

    return {
      input_tokens: Math.max(0, logicalInputTokens - cached),
      cache_read_tokens: cached,
      logical_input_tokens: Math.max(0, logicalInputTokens),
      cache_hit: cached > 0,
    };
  }

  remember(
    keyId: string,
    path: InferencePath,
    publicModel: string,
    segments: PromptSegment[],
    now = Date.now(),
  ): void {
    const cacheKey = `${keyId}:${path}:${publicModel}`;
    this.entries.set(cacheKey, {
      segments: segments.map((segment) => ({ ...segment })),
      expiresAt: now + this.ttlMs,
      touchedAt: now,
    });
    this.maintain(now);
  }

  clear(): void {
    this.entries.clear();
  }

  private maintain(now: number): void {
    this.operations++;
    if (this.operations % 128 !== 0 && this.entries.size <= this.maxEntries) return;

    for (const [key, entry] of this.entries) {
      if (entry.expiresAt <= now) this.entries.delete(key);
    }
    if (this.entries.size <= this.maxEntries) return;

    const oldest = [...this.entries.entries()]
      .sort((left, right) => left[1].touchedAt - right[1].touchedAt)
      .slice(0, this.entries.size - this.maxEntries);
    for (const [key] of oldest) this.entries.delete(key);
  }
}
