import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import LoginPage from "./page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

describe("login", () => {
  it("renders an accessible session login and registration link", () => {
    render(<LoginPage />);

    expect(screen.getByRole("heading", { name: "Sign in" })).toBeInTheDocument();
    expect(screen.getByLabelText("Email address")).toHaveAttribute("type", "email");
    expect(screen.getByLabelText("Password")).toHaveAttribute("type", "password");
    expect(screen.getByRole("link", { name: "Create an account" })).toHaveAttribute("href", "/register");
  });
});
