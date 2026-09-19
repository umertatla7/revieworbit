import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import BillingPage from "./page";

const apiMock = vi.fn();
vi.mock("@/lib/api", () => ({ api: (...args: unknown[]) => apiMock(...args), supportBusinessName: () => null }));

const plan = {
  id: "plan-momentum", code: "momentum", name: "Momentum", description: "For growing teams",
  monthly_price_minor: 9900, annual_price_minor: 99000, currency: "USD", trial_days: 7, trial_message_limit: 10,
  badge: "Popular", cta_label: "Start Free Trial", is_featured: true, is_self_serve: true, is_public: true, monthly_customer_limit: 300, features: ["Automated follow-ups"], location_limit: 1,
  template_limit: 5, automation_limit: 5, automation_step_limit: 5, media_template_limit: 5,
  review_destination_limit: 1,
  stripe_monthly_price_id: "price_month", stripe_annual_price_id: "price_year",
};
const launchPlan = { ...plan, id: "plan-launch", code: "launch", name: "Launch", monthly_customer_limit: 100, monthly_price_minor: 4900, annual_price_minor: 49000, is_featured: false, badge: null };
const expansionPlan = { ...plan, id: "plan-expansion", code: "expansion", name: "Expansion", monthly_customer_limit: 400, location_limit: 2, review_destination_limit: 2, monthly_price_minor: 13900, annual_price_minor: 139000, is_featured: false, badge: null };
const customPlan = { ...plan, id: "plan-custom", code: "custom", name: "Custom", description: null, monthly_customer_limit: null, monthly_price_minor: 0, annual_price_minor: 0, trial_days: 0, trial_message_limit: 0, is_featured: false, is_self_serve: false, is_public: true, badge: "Tailored", features: [], stripe_monthly_price_id: null, stripe_annual_price_id: null };

describe("customer billing", () => {
  afterEach(() => { cleanup(); apiMock.mockReset(); window.history.replaceState({}, "", "/"); });

  it("shows real billing sections, masked cards, invoices, and plan comparison", async () => {
    apiMock.mockResolvedValue({ data: {
      stripe_ready: true, has_stripe_customer: true, can_manage_billing: true, managed_by_support: false, trial_eligible: false, current_plan_code: "momentum",
      subscription: { status: "active", billing_interval: "month", trial_ends_at: null, current_period_ends_at: "2030-01-01T00:00:00Z", cancel_at_period_end: false, plan },
      plans: [launchPlan, plan, expansionPlan], payment_methods: [{ id: "pm_1", brand: "visa", last4: "4242", exp_month: 12, exp_year: 2030, is_default: true }],
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
    expect(screen.getByRole("button", { name: "Downgrade to Launch" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "Upgrade to Expansion" })).toBeEnabled();
  });

  it("lets an owner configure requirements and see a calculated custom price", async () => {
    apiMock.mockImplementation((path:string) => {
      if(path==="/api/v1/billing/custom-quote") return Promise.resolve({data:{
        currency:"USD", monthly_price_minor:6000, annual_price_minor:60000,
        usage:{monthly_customers:100,messages:1000,sms_messages:1000,sms_segments:1000,mms_messages:0,locations:1},
        recommended_allowances:{template_limit:5,automation_limit:2,automation_step_limit:2,media_template_limit:1,review_destination_limit:1},
      }});
      return Promise.resolve({data:{
        stripe_ready:true,has_stripe_customer:false,can_manage_billing:true,managed_by_support:false,trial_eligible:true,current_plan_code:"launch",
        subscription:null,plans:[launchPlan,plan,expansionPlan,customPlan],payment_methods:[],invoices:[],stripe_error:null,
      }});
    });

    render(<BillingPage/>);
    fireEvent.click(await screen.findByRole("button",{name:"Plans"}));
    const customize = screen.getByRole("button",{name:"Customize plan"});
    expect(customize).toBeEnabled();
    await act(async()=>{ fireEvent.click(customize); });
    expect(await screen.findByRole("dialog",{name:"Customize your ReviewOrbit plan"})).toBeInTheDocument();
    fireEvent.change(await screen.findByLabelText(/^Customers each month/),{target:{value:"100"}});
    fireEvent.change(screen.getByLabelText("Business locations"),{target:{value:"1"}});
    fireEvent.change(screen.getByLabelText(/^Exact monthly messages \(optional\)/),{target:{value:"1000"}});

    expect(await screen.findByText("$60.00")).toBeInTheDocument();
    expect(screen.getByRole("button",{name:"Continue with this package"})).toBeEnabled();
  });
});
