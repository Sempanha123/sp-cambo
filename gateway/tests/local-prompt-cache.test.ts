import { describe, expect, it } from "vitest";
import { LocalPromptCache } from "../src/local-prompt-cache.js";
import { prepare } from "../src/protocol.js";

describe("SP Cambo local prompt cache", () => {
  it("discounts only a repeated local prefix and never needs provider usage", () => {
    const cache = new LocalPromptCache(300_000, 1_024, 100);
    const stable = "tool schema ".repeat(900);
    const first = prepare("/v1/messages", JSON.stringify({
      model: "opus-5",
      max_tokens: 100,
      system: stable,
      messages: [{ role: "user", content: "first" }],
    }), 100);

    const before = cache.measure("key-1", "/v1/messages", "opus-5", first.promptSegments, first.estimatedInput, 1_000);
    expect(before.cache_read_tokens).toBe(0);
    expect(before.input_tokens).toBe(first.estimatedInput);
    cache.remember("key-1", "/v1/messages", "opus-5", first.promptSegments, 1_000);

    const second = prepare("/v1/messages", JSON.stringify({
      model: "opus-5",
      max_tokens: 100,
      system: stable,
      messages: [{ role: "user", content: "second" }],
    }), 100);
    const after = cache.measure("key-1", "/v1/messages", "opus-5", second.promptSegments, second.estimatedInput, 2_000);

    expect(after.cache_read_tokens).toBeGreaterThanOrEqual(1_024);
    expect(after.input_tokens + after.cache_read_tokens).toBe(second.estimatedInput);
    expect(after.input_tokens).toBeLessThan(second.estimatedInput);
  });

  it("does not keep prompt text in cache state exposed by the API", () => {
    const cache = new LocalPromptCache();
    const prepared = prepare("/v1/chat/completions", JSON.stringify({
      model: "5.6-sol",
      messages: [{ role: "user", content: "private customer prompt" }],
    }), 100);
    cache.remember("key-1", "/v1/chat/completions", "5.6-sol", prepared.promptSegments);
    expect(JSON.stringify(cache)).not.toContain("private customer prompt");
  });
});
