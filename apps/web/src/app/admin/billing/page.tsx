"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

type Plan = {
  id: string; code: string; name: string; description: string | null;
  monthly_price_minor: number; annual_price_minor: number; currency: string;
  trial_days: number; trial_message_limit: number; badge: string | null; is_featured: boolean; sort_order: number;
  cta_label: string; is_self_serve: boolean; is_public: boolean; monthly_customer_limit: number | null;
  features: string[] | null; status: string; stripe_product_id: string | null;
  stripe_monthly_price_id: string | null; stripe_annual_price_id: string | null;
  location_limit: number; template_limit: number; automation_limit: number;
  automation_step_limit: number; media_template_limit: number;
  review_destination_limit: number; included_message_credits: number;
  review_providers: string[];
};
type Usage = { business_id: string; business_name: string; plan_code: string; messages: number; customers: number; customer_limit: number | null };
type Business = { id: string; name: string; plan_code: string };
type QuoteRequest = {
  monthly_customers: number; locations: number; messages_per_customer: number;
  monthly_messages: number | null; sms_segments_per_message: number;
  mms_percent: number; support_level: string;
};
type Quote = {
  currency: string; monthly_price_minor: number; annual_price_minor: number;
  gross_profit_minor: number; gross_margin_percent: number; estimated_monthly_cost_minor: number;
  one_time_a2p_registration_minor: number;
  usage: { monthly_customers: number; messages: number; sms_messages: number; sms_segments: number; mms_messages: number; locations: number };
  breakdown: Record<string, number>;
  recommended_allowances: { template_limit: number; automation_limit: number; automation_step_limit: number; media_template_limit: number; review_destination_limit: number };
  assumptions: string[];
};

const emptyPlan: Partial<Plan> = {
  code: "", name: "", description: "", monthly_price_minor: 0,
  annual_price_minor: 0, currency: "USD", trial_days: 7, trial_message_limit: 10, badge: "",
  is_featured: false, is_self_serve: true, is_public: true, cta_label: "Start Free Trial", sort_order: 10, features: [], status: "draft",
  location_limit: 1, template_limit: 5, automation_limit: 1,
  automation_step_limit: 2, media_template_limit: 1,
  review_destination_limit: 1, included_message_credits: 0, monthly_customer_limit: 100,
  review_providers: ["google"],
};

const initialQuoteRequest: QuoteRequest = {
  monthly_customers: 100, locations: 1, messages_per_customer: 2,
  monthly_messages: null, sms_segments_per_message: 1, mms_percent: 0,
  support_level: "standard",
};

export default function AdminBillingPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [usage, setUsage] = useState<Usage[]>([]);
  const [businesses, setBusinesses] = useState<Business[]>([]);
  const [selectedBusinessId, setSelectedBusinessId] = useState("");
  const [editing, setEditing] = useState<Partial<Plan> | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [quoteBusy, setQuoteBusy] = useState(false);
  const [assignBusy, setAssignBusy] = useState(false);
  const [quote, setQuote] = useState<Quote | null>(null);
  const [quoteRequest, setQuoteRequest] = useState<QuoteRequest>(initialQuoteRequest);
  const quoteSequence = useRef(0);

  const load = useCallback(async () => {
    const results = await Promise.allSettled([
      api<{ data: Plan[] }>("/api/v1/admin/plans").then((response) => setPlans(response.data)),
      api<{ data: Usage[] }>("/api/v1/admin/usage").then((response) => setUsage(response.data)),
      api<{ data: Business[] }>("/api/v1/admin/businesses").then((response) => {
        setBusinesses(response.data);
        setSelectedBusinessId((current) => current || response.data[0]?.id || "");
      }),
    ]);
    const failure = results.find((result) => result.status === "rejected");
    if (failure?.status === "rejected") {
      setMessage(failure.reason instanceof Error ? failure.reason.message : "Some billing information could not be loaded.");
    }
  }, []);

  useEffect(() => {
    void Promise.allSettled([
      api<{ data: Plan[] }>("/api/v1/admin/plans").then((response) => setPlans(response.data)),
      api<{ data: Usage[] }>("/api/v1/admin/usage").then((response) => setUsage(response.data)),
      api<{ data: Business[] }>("/api/v1/admin/businesses").then((response) => {
        setBusinesses(response.data);
        setSelectedBusinessId((current) => current || response.data[0]?.id || "");
      }),
    ]).then((results) => {
      const failure = results.find((result) => result.status === "rejected");
      if (failure?.status === "rejected") {
        setMessage(failure.reason instanceof Error ? failure.reason.message : "Some billing information could not be loaded.");
      }
    });
  }, []);

  async function save(data: FormData) {
    if (!editing) return;
    setBusy(true);
    setMessage("");
    const numberKeys = [
      "monthly_price_minor", "annual_price_minor", "trial_days", "trial_message_limit", "sort_order",
      "location_limit", "template_limit", "automation_limit", "automation_step_limit",
      "media_template_limit", "review_destination_limit",
    ];
    const payload: Record<string, unknown> = {
      code: data.get("code"), name: data.get("name"), description: data.get("description"),
      currency: String(data.get("currency") ?? "USD").toUpperCase(),
      badge: data.get("badge") || null, status: data.get("status"),
      is_featured: data.get("is_featured") === "on",
      is_self_serve: data.get("is_self_serve") === "on",
      is_public: data.get("is_public") === "on",
      cta_label: data.get("cta_label"),
      monthly_customer_limit: data.get("monthly_customer_limit") ? Number(data.get("monthly_customer_limit")) : null,
      review_providers: data.getAll("review_providers"),
      features: String(data.get("features") ?? "").split("\n").map((value) => value.trim()).filter(Boolean),
    };
    numberKeys.forEach((key) => { payload[key] = Number(data.get(key)); });

    try {
      await api(editing.id ? `/api/v1/admin/plans/${editing.id}` : "/api/v1/admin/plans", {
        method: editing.id ? "PATCH" : "POST", body: JSON.stringify(payload),
      });
      setEditing(null);
      setMessage("Plan saved. Sync it with Stripe after changing its price.");
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to save the plan.");
    } finally { setBusy(false); }
  }

  async function sync(plan: Plan) {
    setBusy(true);
    setMessage("");
    try {
      await api(`/api/v1/admin/plans/${plan.id}/stripe-sync`, { method: "POST" });
      setMessage(`${plan.name} was synchronized with Stripe.`);
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to sync the plan with Stripe.");
    } finally { setBusy(false); }
  }

  async function syncAll() {
    const activePlans = plans.filter((plan) => plan.status === "active" && plan.monthly_price_minor > 0);
    setBusy(true);
    setMessage("");
    try {
      for (const plan of activePlans) {
        await api(`/api/v1/admin/plans/${plan.id}/stripe-sync`, { method: "POST" });
      }
      setMessage(`${activePlans.length} active plan${activePlans.length === 1 ? "" : "s"} synchronized with Stripe.`);
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to synchronize all plans with Stripe.");
      await load();
    } finally { setBusy(false); }
  }

  const calculateQuote = useCallback(async (request: QuoteRequest) => {
    const sequence = ++quoteSequence.current;
    setQuoteBusy(true);
    try {
      const result = await api<{ data: Quote }>("/api/v1/admin/plans/quote", {
        method: "POST",
        body: JSON.stringify(request),
      });
      if (sequence === quoteSequence.current) setQuote(result.data);
    } catch (error) {
      if (sequence === quoteSequence.current) setMessage(error instanceof Error ? error.message : "Unable to calculate this package.");
    } finally { if (sequence === quoteSequence.current) setQuoteBusy(false); }
  }, []);

  useEffect(() => {
    if (quoteRequest.monthly_customers < 1 || quoteRequest.locations < 1 || quoteRequest.messages_per_customer < 1 || quoteRequest.sms_segments_per_message < 1) return;
    const timer = window.setTimeout(() => void calculateQuote(quoteRequest), 350);
    return () => window.clearTimeout(timer);
  }, [calculateQuote, quoteRequest]);

  function updateQuote<K extends keyof QuoteRequest>(key: K, value: QuoteRequest[K]) {
    setQuoteRequest((current) => ({ ...current, [key]: value }));
  }

  async function assignCustomPlan() {
    if (!selectedBusinessId || !quote) return;
    const business = businesses.find((item) => item.id === selectedBusinessId);
    if (!window.confirm(`Create this private package and assign it to ${business?.name ?? "this customer"}? Active Stripe subscriptions will change immediately with prorations and no new trial.`)) return;
    setAssignBusy(true); setMessage("");
    try {
      await api("/api/v1/admin/plans/custom-assign", {
        method: "POST",
        body: JSON.stringify({ business_id: selectedBusinessId, ...quoteRequest }),
      });
      setMessage(`Custom package created, synchronized with Stripe, and assigned to ${business?.name ?? "the customer"}. No trial was added.`);
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to assign this custom package.");
    } finally { setAssignBusy(false); }
  }

  function createFromQuote() {
    if (!quote) return;
    setEditing({
      ...emptyPlan,
      name: "Custom customer plan", code: "", description: "",
      monthly_price_minor: quote.monthly_price_minor,
      annual_price_minor: quote.annual_price_minor,
      monthly_customer_limit: quote.usage.monthly_customers,
      location_limit: quote.usage.locations,
      ...quote.recommended_allowances,
      is_self_serve: false, is_public: false, trial_days: 0, trial_message_limit: 0,
      cta_label: "Contact ReviewOrbit", status: "draft",
    });
  }

  const totalMessages = usage.reduce((total, row) => total + row.messages, 0);
  return (
    <div className="mx-auto max-w-7xl">
      <div className="flex flex-wrap items-end justify-between gap-5">
        <div><p className="eyebrow">Platform billing</p><h1 className="page-title">Plans & subscriptions</h1><p className="page-intro">Create simple all-inclusive packages, control allowances, and publish their prices to Stripe.</p></div>
        <div className="flex gap-3"><button disabled={busy || plans.filter((plan) => plan.status === "active" && plan.monthly_price_minor > 0).length === 0} className="rounded-xl border border-ink/10 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-40" onClick={syncAll}>{busy ? "Working…" : "Sync paid plans"}</button><button className="button-primary" onClick={() => setEditing({ ...emptyPlan })}>+ Create plan</button></div>
      </div>
      {message && <p className="mt-5 rounded-xl border border-ink/8 bg-white px-4 py-3 text-sm">{message}</p>}
      <div className="mt-7 grid gap-4 sm:grid-cols-2"><Metric label="Active plans" value={String(plans.filter((plan) => plan.status === "active").length)} /><Metric label="Messages this month" value={String(totalMessages)} /></div>

      {plans.length === 0 ? (
        <section className="mt-6 rounded-2xl border border-dashed border-ink/15 bg-white p-10 text-center"><h2 className="text-xl font-semibold">No plans yet</h2><p className="mt-2 text-sm text-ink/50">Create Launch, Momentum, Expansion, and Enterprise packages, then sync each paid self-service plan with Stripe.</p></section>
      ) : (
        <div className="mt-6 grid gap-5 lg:grid-cols-2 xl:grid-cols-4">{plans.map((plan) => (
          <article key={plan.id} className={`relative rounded-2xl border bg-white p-6 shadow-sm ${plan.is_featured ? "border-lime" : "border-ink/8"}`}>
            {plan.badge && <span className="absolute right-5 top-5 rounded-full bg-lime px-3 py-1 text-[10px] font-bold uppercase">{plan.badge}</span>}
            <p className="eyebrow">{plan.code} · {plan.status}</p><h2 className="mt-2 text-2xl font-semibold">{plan.name}</h2>
            <p className="mt-2 min-h-12 text-sm leading-6 text-ink/50">{plan.description}</p>
            <p className="mt-5 text-3xl font-semibold">{plan.monthly_price_minor > 0 ? money(plan.monthly_price_minor, plan.currency) : "Custom"}{plan.monthly_price_minor > 0&&<span className="text-xs font-normal text-ink/45"> / month</span>}</p>
            <p className="mt-1 text-xs text-ink/45">{plan.monthly_price_minor > 0 ? `${money(plan.annual_price_minor, plan.currency)} annually · ${plan.trial_days}-day trial · ${plan.trial_message_limit} trial messages` : plan.cta_label}</p>
            <div className="mt-5 grid grid-cols-2 gap-2"><Limit label="Customers / month" value={plan.monthly_customer_limit ?? "Custom"} /><Limit label="Locations" value={plan.location_limit} /><Limit label="Templates" value={plan.template_limit} /><Limit label="Automations" value={plan.automation_limit} /><Limit label="Review links" value={plan.review_destination_limit} /><Limit label="Media" value={plan.media_template_limit} /></div>
            <p className="mt-4 rounded-lg bg-paper px-3 py-2 text-xs text-ink/60">Real database plan · No per-message or overage charges.</p>
            <p className="mt-3 text-xs text-ink/45">{plan.is_public ? "Public comparison plan" : "Private customer-specific plan"}</p>
            {plan.monthly_price_minor > 0?<StripeStatus plan={plan}/>:<p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">Quote-only plan · Stripe price not required</p>}
            <div className="mt-5 flex gap-3"><button className="text-sm font-semibold text-forest" onClick={() => setEditing(plan)}>Configure</button>{plan.monthly_price_minor > 0&&<button disabled={busy} className="text-sm font-semibold text-forest disabled:opacity-40" onClick={() => sync(plan)}>{stripeState(plan)==="published" ? "Resync Stripe" : "Sync Stripe"}</button>}</div>
          </article>
        ))}</div>
      )}

      <section className="mt-7 overflow-hidden rounded-2xl border border-ink/8 bg-white">
        <header className="border-b border-ink/8 p-5"><p className="eyebrow">Customer-specific package</p><h2 className="mt-1 text-xl font-semibold">Build and assign a custom plan</h2><p className="mt-1 text-sm text-ink/50">Choose a customer, adjust the package, and watch the price update automatically. Assigning an existing subscriber changes their Stripe subscription without starting another trial.</p></header>
        <div className="grid gap-6 p-5 lg:grid-cols-[1fr_1.15fr]">
          <div className="grid content-start gap-4 sm:grid-cols-2">
            <label className="label sm:col-span-2">Assign to customer<select className="field" value={selectedBusinessId} onChange={(event) => setSelectedBusinessId(event.target.value)}><option value="">Select a customer</option>{businesses.map((business) => <option value={business.id} key={business.id}>{business.name} · {business.plan_code}</option>)}</select></label>
            <QuoteField label="Customers per month" value={quoteRequest.monthly_customers} change={(value) => updateQuote("monthly_customers", value)} />
            <QuoteField label="Locations / phone numbers" value={quoteRequest.locations} change={(value) => updateQuote("locations", value)} />
            <QuoteField label="Messages per customer" value={quoteRequest.messages_per_customer} change={(value) => updateQuote("messages_per_customer", value)} hint="Initial request plus included follow-ups." />
            <QuoteField label="Exact monthly messages (optional)" value={quoteRequest.monthly_messages ?? ""} change={(value) => updateQuote("monthly_messages", value || null)} required={false} hint="Overrides messages per customer when a client provides a fixed volume." />
            <QuoteField label="Average SMS segments" value={quoteRequest.sms_segments_per_message} change={(value) => updateQuote("sms_segments_per_message", value)} hint="Long text or emojis can create multiple segments." />
            <QuoteField label="Messages with an image (%)" value={quoteRequest.mms_percent} change={(value) => updateQuote("mms_percent", value)} min={0} />
            <label className="label sm:col-span-2">Support level<select className="field" value={quoteRequest.support_level} onChange={(event) => updateQuote("support_level", event.target.value)}><option value="standard">Standard</option><option value="priority">Priority (+$25)</option><option value="dedicated">Dedicated (+$75)</option></select></label>
            <p className="sm:col-span-2 text-xs text-ink/45">{quoteBusy ? "Updating price…" : "Price and margin update automatically as requirements change."}</p>
          </div>
          {quote ? <QuoteResult quote={quote} create={createFromQuote} assign={assignCustomPlan} assignBusy={assignBusy} canAssign={Boolean(selectedBusinessId)} businessName={businesses.find((item) => item.id === selectedBusinessId)?.name ?? null} /> : <div className="grid min-h-64 place-items-center rounded-2xl bg-paper p-8 text-center text-sm text-ink/45">Enter the expected monthly usage to see the recommended selling price and projected margin.</div>}
        </div>
      </section>

      <section className="mt-7 overflow-hidden rounded-2xl border border-ink/8 bg-white">
        <header className="border-b border-ink/8 p-5"><h2 className="font-semibold">Customer package usage this month</h2><p className="mt-1 text-xs text-ink/45">A customer counts once when their review journey starts; included follow-up messages do not consume another customer slot.</p></header>
        <div className="divide-y divide-ink/8">{usage.length === 0 ? <p className="p-5 text-sm text-ink/50">No customer activity recorded this month.</p> : usage.map((row) => <div key={row.business_id} className="grid gap-2 px-5 py-4 text-sm sm:grid-cols-[1.5fr_.7fr_.7fr_1fr]"><strong>{row.business_name}</strong><span className="capitalize">{row.plan_code}</span><span>{row.customers}/{row.customer_limit ?? "Custom"} customers</span><span>{row.messages} messages sent</span></div>)}</div>
      </section>
      {editing && <PlanDialog plan={editing} busy={busy} close={() => setEditing(null)} save={save} />}
    </div>
  );
}

function PlanDialog({ plan, busy, close, save }: { plan: Partial<Plan>; busy: boolean; close: () => void; save: (data: FormData) => void }) {
  return <div className="fixed inset-0 z-50 grid place-items-center bg-ink/60 p-4"><form action={save} className="max-h-[94vh] w-full max-w-4xl overflow-auto rounded-2xl bg-white p-6">
    <div className="flex justify-between"><div><p className="eyebrow">Plan builder</p><h2 className="text-2xl font-semibold">{plan.id ? `Configure ${plan.name}` : "Create a new plan"}</h2></div><button type="button" aria-label="Close plan builder" onClick={close}>×</button></div>
    <div className="mt-4 rounded-xl bg-paper p-4 text-sm text-ink/65">Plans are limited by unique customers whose review journey starts each month. Follow-up messages are included, with no overage or per-message charge.</div>
    <div className="mt-6 grid gap-4 sm:grid-cols-2">
      <Field name="name" label="Plan name" value={plan.name} /><Field name="code" label="Code" value={plan.code} disabled={Boolean(plan.id)} hint="Lowercase letters, numbers, and hyphens only." />
      <label className="label sm:col-span-2">Customer-facing description<textarea className="field min-h-24" name="description" defaultValue={plan.description ?? ""} /></label>
      <Field name="monthly_price_minor" label="Monthly price (cents)" value={plan.monthly_price_minor} number /><Field name="annual_price_minor" label="Annual price (cents)" value={plan.annual_price_minor} number />
      <Field name="currency" label="Currency" value={plan.currency} /><Field name="trial_days" label="Free trial days" value={plan.trial_days} number /><Field name="trial_message_limit" label="Trial test-message limit" value={plan.trial_message_limit ?? 10} number hint="Live and automated customer sends unlock after activation; this caps ReviewOrbit-branded test sends during the trial." />
      <Field name="badge" label="Badge (optional)" value={plan.badge ?? ""} required={false} /><Field name="cta_label" label="Button label" value={plan.cta_label} /><Field name="sort_order" label="Display order" value={plan.sort_order} number />
      <label className="label">Status<select className="field" name="status" defaultValue={plan.status}><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select></label>
      <label className="mt-7 flex gap-2 text-sm"><input type="checkbox" name="is_featured" defaultChecked={plan.is_featured} /> Feature this plan</label>
      <label className="mt-7 flex gap-2 text-sm"><input type="checkbox" name="is_self_serve" defaultChecked={plan.is_self_serve} /> Allow self-service signup and Stripe checkout</label>
      <label className="mt-7 flex gap-2 text-sm"><input type="checkbox" name="is_public" defaultChecked={plan.is_public ?? true} /> Show this plan publicly and in customer comparisons</label>
    </div>
    <h3 className="mt-7 font-semibold">Package allowances</h3>
    <div className="mt-3 grid gap-4 sm:grid-cols-3"><Field name="monthly_customer_limit" label="Customers per month" value={plan.monthly_customer_limit ?? ""} number required={false} hint="Leave blank for a custom Enterprise allowance." /><Field name="location_limit" label="Locations" value={plan.location_limit} number /><Field name="template_limit" label="Message templates" value={plan.template_limit} number /><Field name="automation_limit" label="Automations" value={plan.automation_limit} number /><Field name="automation_step_limit" label="Steps per automation" value={plan.automation_step_limit} number /><Field name="media_template_limit" label="Personalized media" value={plan.media_template_limit} number /><Field name="review_destination_limit" label="Review links" value={plan.review_destination_limit} number /></div>
    <label className="label mt-6">Comparison features (one per line)<textarea className="field min-h-28" name="features" defaultValue={(plan.features ?? []).join("\n")} placeholder={"Automated visit follow-ups\nPersonalized image messages"} /></label>
    <div className="mt-5"><p className="label">Available review platforms</p><div className="mt-2 flex flex-wrap gap-3">{["google", "trustpilot", "facebook", "yelp", "other"].map((provider) => <label className="pill capitalize" key={provider}><input className="mr-2" type="checkbox" name="review_providers" value={provider} defaultChecked={plan.review_providers?.includes(provider)} />{provider}</label>)}</div></div>
    <div className="mt-7 flex justify-end gap-3"><button type="button" className="rounded-lg border px-4 py-2" onClick={close}>Cancel</button><button disabled={busy} className="button-primary">{busy ? "Saving…" : "Save plan"}</button></div>
  </form></div>;
}

function Field({ name, label, value, number, disabled, hint, required = true }: { name: string; label: string; value: unknown; number?: boolean; disabled?: boolean; hint?: string; required?: boolean }) {
  return <label className="label">{label}<input className="field" name={name} required={required} disabled={disabled} type={number ? "number" : "text"} min={number ? 0 : undefined} defaultValue={String(value ?? "")} />{disabled && <input type="hidden" name={name} value={String(value ?? "")} />}{hint && <span className="mt-1 block text-xs font-normal normal-case text-ink/45">{hint}</span>}</label>;
}
function QuoteField({ label, value, change, hint, required = true, min = 1 }: { label: string; value: number | string; change: (value: number) => void; hint?: string; required?: boolean; min?: number }) {
  return <label className="label">{label}<input className="field" type="number" min={min} required={required} value={value} onChange={(event) => change(event.target.value === "" ? 0 : Number(event.target.value))} />{hint && <span className="mt-1 block text-xs font-normal normal-case text-ink/45">{hint}</span>}</label>;
}
function Limit({ label, value }: { label: string; value: number | string }) { return <div className="rounded-lg bg-paper p-3 text-xs"><span className="text-ink/45">{label}</span><strong className="float-right">{value}</strong></div>; }
function QuoteResult({ quote, create, assign, assignBusy, canAssign, businessName }: { quote: Quote; create: () => void; assign: () => void; assignBusy: boolean; canAssign: boolean; businessName: string | null }) {
  const rows = [
    ["Twilio SMS", quote.breakdown.twilio_sms_minor], ["Twilio MMS", quote.breakdown.twilio_mms_minor],
    ["Phone numbers", quote.breakdown.twilio_numbers_minor], ["A2P campaign", quote.breakdown.a2p_campaign_minor],
    ["Stripe estimate", quote.breakdown.stripe_minor], ["Operations allowance", quote.breakdown.operations_allowance_minor],
  ] as const;
  return <div className="rounded-2xl bg-forest p-6 text-white">
    <div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[.18em] text-mint">Recommended package</p><p className="mt-2 text-4xl font-semibold">{money(quote.monthly_price_minor, quote.currency)}<span className="text-sm font-normal text-white/55"> / month</span></p><p className="mt-1 text-xs text-white/55">{money(quote.annual_price_minor, quote.currency)} annually</p></div><div className="rounded-xl bg-white/10 px-4 py-3 text-right"><span className="text-xs text-white/55">Projected margin</span><strong className="block text-2xl text-mint">{quote.gross_margin_percent}%</strong></div></div>
    <div className="mt-5 grid grid-cols-2 gap-2 text-xs"><LimitDark label="Customers" value={quote.usage.monthly_customers.toLocaleString()} /><LimitDark label="Messages" value={quote.usage.messages.toLocaleString()} /><LimitDark label="SMS segments" value={quote.usage.sms_segments.toLocaleString()} /><LimitDark label="MMS" value={quote.usage.mms_messages.toLocaleString()} /></div>
    <div className="mt-5 space-y-2 border-t border-white/15 pt-4 text-xs">{rows.map(([label, value])=><div className="flex justify-between" key={label}><span className="text-white/60">{label}</span><strong>{money(value, quote.currency)}</strong></div>)}<div className="flex justify-between border-t border-white/15 pt-2"><span>Estimated monthly cost</span><strong>{money(quote.estimated_monthly_cost_minor, quote.currency)}</strong></div><div className="flex justify-between text-mint"><span>Estimated gross profit</span><strong>{money(quote.gross_profit_minor, quote.currency)}</strong></div></div>
    <p className="mt-4 text-xs leading-5 text-white/55">One-time A2P registration estimate: {money(quote.one_time_a2p_registration_minor, quote.currency)}. Final cost varies by carrier, encoding, taxes, and registration type.</p>
    <button type="button" disabled={!canAssign || assignBusy} className="mt-5 w-full rounded-xl bg-mint px-4 py-3 text-sm font-semibold text-ink disabled:opacity-40" onClick={assign}>{assignBusy ? "Assigning package…" : businessName ? `Create and assign to ${businessName}` : "Select a customer to assign"}</button>
    <button type="button" className="mt-3 w-full rounded-xl border border-white/25 px-4 py-3 text-sm font-semibold" onClick={create}>Copy values into manual plan builder</button>
  </div>;
}
function LimitDark({ label, value }: { label: string; value: string }) { return <div className="rounded-lg bg-white/10 p-3"><span className="text-white/50">{label}</span><strong className="float-right">{value}</strong></div>; }
function stripeState(plan: Plan) { const count=[plan.stripe_product_id,plan.stripe_monthly_price_id,plan.stripe_annual_price_id].filter(Boolean).length; return count===3?"published":count===0?"not-synced":"partial"; }
function StripeStatus({plan}:{plan:Plan}) { const state=stripeState(plan); return <div className={`mt-3 flex items-center justify-between rounded-lg px-3 py-2 text-xs ${state==="published"?"bg-emerald-50 text-emerald-800":state==="partial"?"bg-amber-50 text-amber-800":"bg-slate-50 text-slate-600"}`}><span>Stripe status</span><strong>{state==="published"?"Published":state==="partial"?"Partial — sync again":"Not synchronized"}</strong></div>; }
function Metric({ label, value }: { label: string; value: string }) { return <div className="rounded-2xl border border-ink/8 bg-white p-5"><p className="text-xs text-ink/45">{label}</p><strong className="mt-2 block text-2xl">{value}</strong></div>; }
function money(value: number, currency: string) { return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(value / 100); }
