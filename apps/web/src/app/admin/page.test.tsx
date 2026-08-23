import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import AdminPage from "./page";

const apiMock = vi.fn();
vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => apiMock(...args), startSupportMode: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));

describe("platform admin customer directory", () => {
  afterEach(cleanup);
  beforeEach(() => {
    apiMock.mockResolvedValue({ data: [{
      id: "business-1", name: "AL Barber Shop", slug: "al-barber-shop", status: "active",
      default_timezone: "America/New_York", default_country: "US", onboarding_status: "completed",
      onboarding_step: 10, operation_mode: "generic", locations_count: 1, customers_count: 12,
      pos_integrations_count: 1, owners: [{ name: "Owner", email: "owner@albarbershop.test" }],
    }] });
  });

  it("shows tenant health and an explicit support-mode action", async () => {
    render(<AdminPage />);
    expect(screen.getByRole("heading", { name: "Customer accounts" })).toBeInTheDocument();
    expect(await screen.findByText("AL Barber Shop")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Manage" })).toBeInTheDocument();
    expect(screen.getByText("owner@albarbershop.test · America/New_York")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Manage" }));
    expect(screen.getByRole("dialog", { name: "Manage AL Barber Shop" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Start support session" })).toBeInTheDocument();
  });

  it("opens a complete professional customer provisioning form", async () => {
    render(<AdminPage />);
    fireEvent.click(screen.getByRole("button", { name: "+ New customer" }));

    expect(screen.getByRole("dialog", { name: "Create customer account" })).toBeInTheDocument();
    expect(screen.getByText("Business identity")).toBeInTheDocument();
    expect(screen.getByText("Account owner")).toBeInTheDocument();
    expect(screen.getByText("Primary location")).toBeInTheDocument();
    expect(screen.getByText("Service defaults")).toBeInTheDocument();
    expect(screen.getByLabelText(/Business display name/)).toBeRequired();
    expect(screen.getByLabelText(/Owner email/)).toBeRequired();
    expect(screen.getByLabelText(/Street address/)).toBeRequired();
    expect(screen.getByRole("button", { name: "Create customer account" })).toBeInTheDocument();
  });
});
