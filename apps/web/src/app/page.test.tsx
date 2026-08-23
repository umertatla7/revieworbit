import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import Home from "./page";

describe("home", () => {
  it("uses truthful review click language", () => {
    render(<Home />);

    expect(screen.getByRole("heading", { name: /turn completed visits/i })).toBeInTheDocument();
    expect(screen.getByText("Review link clicked")).toBeInTheDocument();
    expect(screen.queryByText("Review submitted")).not.toBeInTheDocument();
  });
});

