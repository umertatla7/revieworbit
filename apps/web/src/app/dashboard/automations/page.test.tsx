import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import AutomationsPage from "./page";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: vi.fn() }));

describe("visits and automations", () => {
  beforeEach(() => {
    vi.mocked(api).mockImplementation(async (path: string) => {
      if (path === "/api/v1/business") return { data: { locations: [{ id: "location-1", name: "Main Street Location" }] } } as never;
      if (path === "/api/v1/customers") return { data: [{ id: "customer-1", first_name: "Umer" }] } as never;
      if (path === "/api/v1/templates") return { data: [{ id: "template-1", name: "Standard review request", status: "active" }] } as never;
      return { data: [] } as never;
    });
  });

  it("offers manual visits and generic integration without claiming messages are sent", async () => {
    render(<AutomationsPage />);

    expect(screen.getByRole("heading", { name: "Turn eligible visits into scheduled decisions" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Complete visit & evaluate" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Create credentials" })).toBeInTheDocument();
    expect(screen.getByText(/Milestone 7 will send messages/i)).toBeInTheDocument();
    expect(await screen.findAllByRole("option", { name: "Main Street Location" })).toHaveLength(2);
  });
});
