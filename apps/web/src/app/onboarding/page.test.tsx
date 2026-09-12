import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import OnboardingPage from "./page";

const apiMock = vi.fn();
vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => apiMock(...args) }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));

describe("customer onboarding", () => {
  beforeEach(() => {
    apiMock.mockImplementation((path: string) => {
      if (path === "/api/v1/pos-integrations") return Promise.resolve({ data: { connections: [], providers: [
        { id: "generic", name: "Generic POS / API", availability: "available", description: "Connect a POS API." },
        { id: "square", name: "Square Appointments", availability: "available", configured: true, description: "Connect Square securely." },
        { id: "toast", name: "Toast POS", availability: "partner setup required", configured: false, description: "Connect Toast locations." },
        { id: "manual", name: "Manual mode", availability: "available", description: "Use manual visits." },
      ] } });
      if (path === "/api/v1/toast/connections") return Promise.resolve({ data: { ready: false, environment: "sandbox", requests: [], connections: [] } });
      if (path === "/api/v1/business") return Promise.resolve({ data: { locations: [] } });
      return Promise.resolve({ data: {
        business: { id: "business-1", name: "AL Barber Shop", default_timezone: "America/New_York", default_country: "US", operation_mode: "manual", onboarding_status: "in_progress", locations: [] },
        checks: { business_details: true, primary_location: false, google_review_url: false, message_template: false, messaging_preferences: false, consent_confirmation: false, integration: true },
        completed_count: 2, total_count: 7,
      } });
    });
  });

  it("guides a new customer through setup and POS selection", async () => {
    render(<OnboardingPage />);
    expect(screen.getByRole("heading", { name: "Set up your customer workspace" })).toBeInTheDocument();
    expect(await screen.findByDisplayValue("AL Barber Shop")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Connect your POS" })).toBeInTheDocument();
    expect(await screen.findByRole("button", { name: "Connect API" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Connect Square account" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Create Toast location code" })).toBeDisabled();
    expect(screen.getByRole("combobox", { name: "Square environment" })).toBeInTheDocument();
  });
});
