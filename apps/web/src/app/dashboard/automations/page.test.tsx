import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import AutomationsPage from "./page";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: vi.fn() }));

describe("visits and automations", () => {
  beforeEach(() => {
    vi.mocked(api).mockImplementation(async (path: string) => {
      if (path === "/api/v1/business") return { data: { locations: [{ id: "location-1", name: "Main Street Location" }], entitlements: { plan_name: "Growth", automation_limit: 5, automations_used: 0, automation_step_limit: 4, can_add_automation: true } } } as never;
      if (path === "/api/v1/templates") return { data: [{ id: "template-1", name: "Standard review request", status: "active" }] } as never;
      return { data: [] } as never;
    });
  });

  it("keeps automation building separate from manual visit and integration controls", async () => {
    render(<AutomationsPage />);

    expect(screen.getByRole("heading", { name: "Build the follow-up journey" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "+ Create automation" })).toBeInTheDocument();
    expect(await screen.findByText("Growth automation allowance")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Complete visit & evaluate" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Create credentials" })).not.toBeInTheDocument();
  });
});
