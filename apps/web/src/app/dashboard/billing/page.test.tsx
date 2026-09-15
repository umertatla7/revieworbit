import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import BillingPage from "./page";

const apiMock = vi.fn();
vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => apiMock(...args), supportBusinessName: () => null }));

const plan = {
  id: "plan-growth", code: "growth", name: "Growth", description: "For growing teams",
  monthly_price_minor: 4900, annual_price_minor: 49000, currency: "USD", trial_days: 14,
  badge: "Popular", is_featured: true, features: ["Automated follow-ups"], location_limit: 5,
  template_limit: 5, automation_limit: 5, automation_step_limit: 5, media_template_limit: 5,
  review_destination_limit: 5, included_message_credits: 500,
  stripe_monthly_price_id: "price_month", stripe_annual_price_id: "price_year",
};
const basicPlan = { ...plan, id: "plan-basic", code: "basic", name: "Basic", monthly_price_minor: 2500, annual_price_minor: 25000, is_featured: false, badge: null };
const proPlan = { ...plan, id: "plan-pro", code: "pro", name: "Pro", monthly_price_minor: 9900, annual_price_minor: 99000, is_featured: false, badge: null };

describe("customer billing", () => {
  afterEach(() => { cleanup(); apiMock.mockReset(); window.history.replaceState({}, "", "/"); });

  it("shows real billing sections, masked cards, invoices, and plan comparison", async () => {
    apiMock.mockResolvedValue({ data: {
      stripe_ready: true, has_stripe_customer: true, can_manage_billing: true, managed_by_support: false, current_plan_code: "growth",
      subscription: { status: "active", billing_interval: "month", trial_ends_at: null, current_period_ends_at: "2030-01-01T00:00:00Z", cancel_at_period_end: false, plan },
      plans: [basicPlan, plan, proPlan], payment_methods: [{ id: "pm_1", brand: "visa", last4: "4242", exp_month: 12, exp_year: 2030, is_default: true }],
      invoices: [{ id: "in_1", number: "RO-001", status: "paid", amount_paid_minor: 4900, amount_due_minor: 4900, currency: "USD", hosted_invoice_url: "https://invoice.test", invoice_pdf_url: "https://invoice.test/pdf", created_at: "2029-12-01T00:00:00Z" }],
      stripe_error: null,
    }});

    render(<BillingPage />);
    expect(await screen.findByText("Simple, secure account billing")).toBeInTheDocument();
    expect(screen.getByText("Visa ending in 4242, expires 12/2030.")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Payment methods" }));
    expect(screen.getByText("•••• •••• •••• 4242")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Change payment method" })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Invoices" }));
    expect(screen.getByText("RO-001")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "View invoice" })).toHaveAttribute("href", "https://invoice.test");

    fireEvent.click(screen.getByRole("button", { name: "Plans" }));
    expect(screen.getAllByText("For growing teams")).toHaveLength(3);
    expect(screen.getByRole("button", { name: "Current plan" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Downgrade to Basic" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "Upgrade to Pro" })).toBeEnabled();
  });
});
