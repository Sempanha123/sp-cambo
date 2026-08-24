import { describe, expect, it } from "vitest";
import { loadConfig } from "../src/config.js";
import { generateKhqr } from "../src/khqr.js";

const env = {
  CONTROL_PLANE_BASE_URL: "http://control-plane",
  SP_CAMBO_INTERNAL_GATEWAY_SECRET: "i".repeat(32),
  OMNIROUTE_BASE_URL: "http://omniroute:20128",
  OMNIROUTE_API_KEY: "o".repeat(32),
  REDIS_URL: "redis://redis:6379",
};

describe("configuration", () => {
  it("fails closed when the control-plane secret is missing", () => {
    expect(() => loadConfig({ ...env, SP_CAMBO_INTERNAL_GATEWAY_SECRET: "" })).toThrow(/SP_CAMBO_INTERNAL_GATEWAY_SECRET/);
  });
  it("does not require a duplicate OmniRoute credential when routing comes from preflight", () => {
    const config = loadConfig({ ...env, OMNIROUTE_BASE_URL: "", OMNIROUTE_API_KEY: "", GATEWAY_RATE_STORE: "memory" });
    expect(config.omniRouteApiKey).toBe("");
    expect(config.rateStore).toBe("memory");
  });
  it("accepts private service-network origins as a backwards-compatible fallback", () => expect(loadConfig(env).omniRouteBaseUrl).toBe("http://omniroute:20128"));
});

it("generates verifiable official KHQR output without exposing merchant configuration", () => {
  const result = generateKhqr({ account_id: "merchant@bank", merchant_name: "SP Cambo", merchant_city: "Phnom Penh", currency: "USD", amount: "1.25", reference: "SPC-TEST", expires_at_unix_ms: Date.now() + 60_000 });
  expect(result?.qr_payload).toMatch(/^000201/); expect(result?.md5).toMatch(/^[a-f0-9]{32}$/);
});
