import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "@/lib/api";
import AdminCustomerDetailPage from "./page";

vi.mock("@/lib/api", () => ({ api: vi.fn() }));
vi.mock("next/navigation", () => ({ useParams: () => ({ businessId: "business-1" }) }));

describe("platform customer analytics", () => {
  beforeEach(() => {
    vi.mocked(api).mockResolvedValue({ data: {
      id: "business-1", name: "Northstar Dental", legal_name: "Northstar Dental LLC", industry: "dental", status: "active", plan_code: "basic", primary_email: "owner@example.test", phone: "+12025550100", website_url: null, default_timezone: "America/New_York", default_country: "US", onboarding_status: "completed", operation_mode: "square", locations_count: 1, customers_count: 4, pos_integrations_count: 0, owners: [{ id: "owner-1", name: "Avery Morgan", email: "owner.basic01@revieworbit.test" }],
      entitlements: { plan_name: "Basic", location_limit: 1, locations_used: 1, template_limit: 1, templates_used: 1, media_template_limit: 1, media_templates_used: 0, review_destination_limit: 1, review_destinations_used: 1, included_message_credits: 100, message_credits_used: 8 },
      analytics: { summary: { messages_this_month: 8, messages_all_time: 8, delivered_this_month: 7, failed_this_month: 1, credits_used_this_month: 8, estimated_cost_minor_this_month: 8, provider_cost_minor_this_month: 7, visits_this_month: 8, visits_all_time: 8, review_link_clicks: 6 }, by_channel: [{ channel: "sms", messages: 8 }], by_status: [{ status: "delivered", messages: 7 }], recent_deliveries: [{ id: "delivery-1", customer_name: "Ava Tester", template_name: "Standard review request", channel: "sms", delivery_type: "fixture", status: "delivered", recipient_last_four: "0101", billable_credits: 1, estimated_cost_minor: 1, provider_cost_minor: 1, currency: "USD", body: "Thank you for visiting.", created_at: "2026-09-01T10:00:00Z" }], recent_visits: [{ id: "visit-1", customer_name: "Ava Tester", location_name: "Main Location", source: "square", type: "appointment", status: "completed", amount: "48.00", currency: "USD", completed_at: "2026-09-01T09:00:00Z" }] },
    } } as never);
  });

  it("shows read-only usage, cost, message, and visit details", async () => {
    render(<AdminCustomerDetailPage />);
    expect(await screen.findByRole("heading", { name: "Northstar Dental" })).toBeInTheDocument();
    expect(screen.getByText("Provider reported cost")).toBeInTheDocument();
    expect(screen.getAllByText("Ava Tester")).toHaveLength(2);
    expect(screen.getByText("•••• 0101")).toBeInTheDocument();
    expect(screen.getByText("Clicks only; not submitted reviews")).toBeInTheDocument();
  });
});
