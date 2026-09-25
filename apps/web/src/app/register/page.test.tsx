import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import RegisterPage from "./page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

vi.mock("@/lib/api", () => ({
  api: vi.fn().mockResolvedValue({ data: [] }),
  selectBusiness: vi.fn(),
}));

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
});
