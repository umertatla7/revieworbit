import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { WorkspaceShell, type NavigationGroup } from "./workspace-shell";

vi.mock("next/navigation", () => ({ usePathname: () => "/dashboard/messages" }));

const groups: NavigationGroup[] = [
  { label: "Engagement", items: [
    { href: "/dashboard/customers", label: "Customers", icon: "users" },
    { href: "/dashboard/messages", label: "Messages", icon: "messages", badge: "Soon" },
  ] },
  { label: "Reports", items: [{ href: "/dashboard/analytics", label: "Analytics", icon: "analytics", badge: "Soon" }] },
];

describe("workspace shell", () => {
  it("renders grouped navigation, status badges, and account controls", () => {
    render(<WorkspaceShell groups={groups} mode="customer" userName="AL Barber Shop Owner" userDetail="owner@example.com" workspaceName="AL Barber Shop" onLogout={vi.fn()}><h1>Messages</h1></WorkspaceShell>);

    expect(screen.getByRole("navigation", { name: "Customer navigation" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Customers" })).toHaveAttribute("href", "/dashboard/customers");
    expect(screen.getByRole("link", { name: /Messages\s*Soon/ })).toHaveAttribute("href", "/dashboard/messages");
    expect(screen.getAllByText("AL Barber Shop")).toHaveLength(2);
    expect(screen.getByRole("button", { name: "Sign out" })).toBeInTheDocument();
  });
});
