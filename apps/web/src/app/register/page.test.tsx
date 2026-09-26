import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import RegisterPage from "./page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

vi.mock("@/lib/api", () => ({
  api: vi.fn().mockResolvedValue({ data: [] }),
  selectBusiness: vi.fn(),
}));

afterEach(cleanup);

describe("registration", () => {
  it("keeps the user on business details until required fields are valid", async () => {
    render(<RegisterPage />);

    expect(screen.getByRole("link", { name: "Sign in" })).toHaveAttribute("href", "/login");
    fireEvent.click(screen.getByRole("button", { name: "Continue to plans" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Complete the required business and account details",
    );
    expect(screen.getByRole("heading", { name: "Tell us about your business" })).toBeInTheDocument();
  });

  it("formats US phone numbers and shows live password requirements", () => {
    render(<RegisterPage />);

    fireEvent.input(screen.getByLabelText("Business phone"), {
      target: { value: "7138931144" },
    });
    fireEvent.change(screen.getByLabelText("Password"), {
      target: { value: "ModernPassword2026" },
    });
    fireEvent.change(screen.getByLabelText("Confirm password"), {
      target: { value: "ModernPassword2026" },
    });

    expect(screen.getByLabelText("Business phone")).toHaveValue("(713) 893-1144");
    expect(screen.getByText("At least 12 characters")).toHaveClass("text-forest");
    expect(screen.getByText("Passwords match")).toHaveClass("text-forest");
  });
});
