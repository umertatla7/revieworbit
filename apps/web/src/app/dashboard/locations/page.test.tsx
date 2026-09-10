import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import LocationsPage from "./page";

const apiMock = vi.fn();
vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => apiMock(...args) }));

describe("locations and reviews", () => {
  afterEach(() => {
    cleanup();
    apiMock.mockReset();
  });

  it("shows plan limits and an editable location table", async () => {
    apiMock.mockResolvedValue({
      data: {
        name: "AL Barber Shop",
        default_timezone: "America/New_York",
        default_country: "US",
        entitlements: {
          plan_code: "basic",
          plan_name: "Basic",
          location_limit: 1,
          locations_used: 1,
          locations_remaining: 0,
          can_add_location: false,
          review_destination_limit: 1,
          review_providers: [
            "google",
            "trustpilot",
            "facebook",
            "yelp",
            "other",
          ],
          available_review_providers: [
            { provider: "google", included: true },
            { provider: "trustpilot", included: true },
            { provider: "facebook", included: true },
            { provider: "yelp", included: true },
            { provider: "other", included: true },
          ],
        },
        locations: [
          {
            id: "loc-1",
            name: "Main Street",
            timezone: "America/New_York",
            phone: "+12025550123",
            status: "active",
            address: { city: "New York", region: "NY" },
            review_destinations: [
              {
                provider: "google",
                url: "https://g.page/r/example/review",
                is_primary: true,
              },
            ],
          },
        ],
      },
    });
    render(<LocationsPage />);
    expect(await screen.findByText("Main Street")).toBeInTheDocument();
    expect(
      screen.getByText("Your Basic plan location limit is reached"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "+ Add location" }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Edit" }));
    expect(
      screen.getByRole("dialog", { name: "Edit Main Street" }),
    ).toBeInTheDocument();
    expect(
      screen.getByDisplayValue("https://g.page/r/example/review"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Basic includes one review link of your choice."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("option", { name: "Trustpilot" }),
    ).toBeInTheDocument();
  });
});
