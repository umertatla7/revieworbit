import { describe, expect, it } from "vitest";
import { formatUsPhone, isUsPhone, toUsE164 } from "./us-phone";

describe("US phone formatting", () => {
  it("formats ten digits while the customer types", () => {
    expect(formatUsPhone("7138931144")).toBe("(713) 893-1144");
    expect(formatUsPhone("71389")).toBe("(713) 89");
  });

  it("normalizes a formatted number for the API", () => {
    expect(isUsPhone("(713) 893-1144")).toBe(true);
    expect(toUsE164("(713) 893-1144")).toBe("+17138931144");
  });
});
