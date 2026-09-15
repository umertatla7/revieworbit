export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "https://api.revieworbit.test";

type ApiErrorBody = {
  message?: string;
  errors?: Record<string, string[]>;
};

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

function cookie(name: string): string | undefined {
  if (typeof document === "undefined") return undefined;
  const value = document.cookie
    .split("; ")
    .find((part) => part.startsWith(`${name}=`))
    ?.split("=")
    .slice(1)
    .join("=");
  return value ? decodeURIComponent(value) : undefined;
}

export function selectedBusinessId(): string | null {
  return typeof window === "undefined" ? null : window.localStorage.getItem("revieworbit_business_id") ?? window.localStorage.getItem("revieworbit_support_business_id");
}

export function selectBusiness(id: string): void {
  window.localStorage.setItem("revieworbit_business_id", id);
}

export function startSupportMode(businessId: string, businessName: string, token: string): void {
  selectBusiness(businessId);
  window.localStorage.setItem("revieworbit_support_business_id", businessId);
  window.localStorage.setItem("revieworbit_support_token", token);
  window.localStorage.setItem("revieworbit_support_business_name", businessName);
}

export function stopSupportMode(): void {
  window.localStorage.removeItem("revieworbit_support_token");
  window.localStorage.removeItem("revieworbit_support_business_name");
  window.localStorage.removeItem("revieworbit_support_business_id");
  window.localStorage.removeItem("revieworbit_business_id");
}

export function supportBusinessName(): string | null {
  return typeof window === "undefined" ? null : window.localStorage.getItem("revieworbit_support_business_name");
}

export async function csrf(): Promise<void> {
  // Remove any legacy host-only token that can shadow the shared
  // `.revieworbit.test` cookie used by the API subdomain.
  if (typeof document !== "undefined") {
    document.cookie = "XSRF-TOKEN=; Max-Age=0; Path=/; SameSite=Lax";
  }

  const response = await fetchWithRetry(`${API_URL}/sanctum/csrf-cookie`, { credentials: "include" });
  if (!response.ok) throw new ApiError("Unable to initialize the secure session.", response.status);
}

async function fetchWithRetry(input: string, init: RequestInit): Promise<Response> {
  try {
    return await fetch(input, init);
  } catch {
    await new Promise((resolve) => window.setTimeout(resolve, 500));
    try {
      return await fetch(input, init);
    } catch {
      throw new ApiError("Unable to reach ReviewOrbit. Check your connection and try again.", 0);
    }
  }
}

export async function api<T>(path: string, init: RequestInit = {}, withBusiness = false): Promise<T> {
  const method = init.method?.toUpperCase() ?? "GET";
  const unsafe = !["GET", "HEAD", "OPTIONS"].includes(method);
  const authenticating = path === "/api/v1/auth/login" || path === "/api/v1/auth/register";

  // Authentication must always start with a fresh session/token pair. A token
  // cookie can outlive its database session during local restarts.
  if (unsafe && (authenticating || !cookie("XSRF-TOKEN"))) await csrf();

  const send = () => {
    const headers = new Headers(init.headers);
    if (!(init.body instanceof FormData)) headers.set("Content-Type", "application/json");
    const token = cookie("XSRF-TOKEN");
    if (token) headers.set("X-XSRF-TOKEN", token);
    if (withBusiness) {
      const businessId = selectedBusinessId();
      if (businessId) headers.set("X-Business-ID", businessId);
      const supportToken = typeof window === "undefined" ? null : window.localStorage.getItem("revieworbit_support_token");
      if (supportToken) headers.set("X-Support-Session", supportToken);
    }

    return fetchWithRetry(`${API_URL}${path}`, { ...init, headers, credentials: "include" });
  };

  if (withBusiness && !selectedBusinessId()) {
    throw new ApiError("No customer workspace is selected. Return to Admin and click Manage customer again.", 400);
  }

  let response = await send();
  if (response.status === 419 && unsafe) {
    await csrf();
    response = await send();
  }

  if (response.status === 401 && withBusiness && typeof window !== "undefined" && window.localStorage.getItem("revieworbit_support_token")) {
    stopSupportMode();
    window.dispatchEvent(new CustomEvent("revieworbit:support-expired"));
    window.setTimeout(() => window.dispatchEvent(new CustomEvent("revieworbit:support-expired")), 0);
    throw new ApiError("Your admin support session expired. Start a new support session to continue.", 401);
  }

  if (response.status === 401 && !authenticating && typeof window !== "undefined") {
    stopSupportMode();
    const returnTo = `${window.location.pathname}${window.location.search}`;
    window.location.replace(`/login?expired=1&return_to=${encodeURIComponent(returnTo)}`);
    throw new ApiError("Your session expired. Please sign in again.", 401);
  }

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ApiErrorBody;
    const first = Object.values(body.errors ?? {})[0]?.[0];
    const isMediaRequest = path.includes("/media") || (path.includes("/templates/") && init.body instanceof FormData);
    const transportMessage = response.status === 413
      ? "The image is larger than the 20 MB upload limit. Please resize or compress it and try again."
      : response.status >= 500 && isMediaRequest
        ? "The server could not process this image. Please try a JPG, PNG, or WebP under 20 MB."
        : response.status >= 500
          ? "The service is temporarily unavailable. Please try again in a moment."
        : "The request could not be completed.";
    throw new ApiError(first ?? body.message ?? transportMessage, response.status, body.errors);
  }
  if (response.status === 204) return undefined as T;
  return response.json() as Promise<T>;
}

export type BusinessSummary = { id: string; name: string; slug: string; role: "owner" | "manager" | "viewer" };
export type SessionUser = { id: string; name: string; email: string; businesses: BusinessSummary[]; platform_roles: string[]; is_platform_admin: boolean };
