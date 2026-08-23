import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import CustomersPage from "./page";

const apiMock = vi.fn();
vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => apiMock(...args) }));

describe("customer directory", () => {
  afterEach(() => { cleanup(); apiMock.mockReset(); });

  it("renders the customer table and modal workflows", async () => {
    apiMock.mockResolvedValue({ data: [{ id: "customer-1", first_name: "Ava", last_name: "Morgan", email: "ava@example.com", phone_e164: "+12025550123", status: "active", source: "manual", consents: [{ channel: "sms", status: "granted" }], suppressions: [] }], meta: { current_page: 1, last_page: 1, total: 1 } });
    render(<CustomersPage />);
    expect(await screen.findByText("Ava Morgan")).toBeInTheDocument();
    expect(screen.getByText("Consented")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "+ Add customer" }));
    expect(screen.getByRole("dialog", { name: "Add customer" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Close dialog" }));
    fireEvent.click(screen.getByRole("button", { name: "Import CSV" }));
    expect(screen.getByRole("dialog", { name: "Import customers" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Download sample CSV" })).toBeInTheDocument();
    expect(screen.getByText("Maximum 500 rows and 2 MB")).toBeInTheDocument();
  });
});
