import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api, startSupportMode } from "./api";

function response(status: number, body: unknown = {}) {
  return new Response(status === 204 ? null : JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  const values = new Map<string, string>();
  Object.defineProperty(window, "localStorage", {
    configurable: true,
    value: {
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => values.set(key, value),
      removeItem: (key: string) => values.delete(key),
      clear: () => values.clear(),
    },
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
  document.cookie = "XSRF-TOKEN=; Max-Age=0; Path=/";
  window.localStorage.clear();
});

describe("API CSRF recovery", () => {
  it("refreshes the CSRF session before login", async () => {
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(async () => {
        document.cookie = "XSRF-TOKEN=fresh-login-token; Path=/";
        return response(204);
      })
      .mockResolvedValueOnce(response(200, { data: { id: "user-1" } }));
    vi.stubGlobal("fetch", fetchMock);

    await api("/api/v1/auth/login", {
      method: "POST",
      body: JSON.stringify({ email: "admin@revieworbit.test", password: "secret" }),
    });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0]?.[0]).toContain("/sanctum/csrf-cookie");
    const loginHeaders = fetchMock.mock.calls[1]?.[1]?.headers as Headers;
    expect(loginHeaders.get("X-XSRF-TOKEN")).toBe("fresh-login-token");
  });

  it("refreshes the token and retries one 419 response", async () => {
    document.cookie = "XSRF-TOKEN=expired-token; Path=/";
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(response(419, { message: "CSRF token mismatch." }))
      .mockImplementationOnce(async () => {
        document.cookie = "XSRF-TOKEN=fresh-token; Path=/";
        return response(204);
      })
      .mockResolvedValueOnce(response(200, { data: { id: "customer-1" } }));
    vi.stubGlobal("fetch", fetchMock);

    await api("/api/v1/customers", { method: "POST", body: "{}" });

    expect(fetchMock).toHaveBeenCalledTimes(3);
    const retryHeaders = fetchMock.mock.calls[2]?.[1]?.headers as Headers;
    expect(retryHeaders.get("X-XSRF-TOKEN")).toBe("fresh-token");
  });

  it("clears stale support mode when the audited session has expired", async () => {
    startSupportMode("business-1", "Harbor Dental", "support_expired");
    const expired = vi.fn();
    window.addEventListener("revieworbit:support-expired", expired);
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(response(401, { message: "expired" })));

    await expect(api("/api/v1/business", {}, true)).rejects.toThrow("support session expired");

    expect(window.localStorage.getItem("revieworbit_support_token")).toBeNull();
    expect(window.localStorage.getItem("revieworbit_business_id")).toBeNull();
    expect(expired).toHaveBeenCalledOnce();
    window.removeEventListener("revieworbit:support-expired", expired);
  });

  it("always sends the supported customer business id in admin workspace mode", async () => {
    startSupportMode("business-42", "AL Barber Shop", "support_current");
    const fetchMock = vi.fn().mockResolvedValue(response(200, { data: [] }));
    vi.stubGlobal("fetch", fetchMock);

    await api("/api/v1/media-templates", {}, true);

    const headers = fetchMock.mock.calls[0]?.[1]?.headers as Headers;
    expect(headers.get("X-Business-ID")).toBe("business-42");
    expect(headers.get("X-Support-Session")).toBe("support_current");
  });
});
