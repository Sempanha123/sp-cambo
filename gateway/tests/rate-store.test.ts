import { describe, expect, it } from "vitest";
import { ADMISSION_SCRIPT } from "../src/rate-store.js";

describe("Redis admission script", () => {
  it("sets the fixed-window TTL only when the counter is created", () => {
    expect(ADMISSION_SCRIPT).toContain("if count == 1 then");
    expect(ADMISSION_SCRIPT).toContain("PEXPIRE");
    expect(ADMISSION_SCRIPT).not.toMatch(/end;\s*redis\.call\('PEXPIRE'/);
  });
});
