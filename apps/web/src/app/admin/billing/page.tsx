"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";

type Plan = {
  id: string; code: string; name: string; description: string | null;
  monthly_price_minor: number; annual_price_minor: number; currency: string;
  trial_days: number; badge: string | null; is_featured: boolean; sort_order: number;
  cta_label: string; is_self_serve: boolean; monthly_customer_limit: number | null;
  features: string[] | null; status: string; stripe_product_id: string | null;
  stripe_monthly_price_id: string | null; stripe_annual_price_id: string | null;
  location_limit: number; template_limit: number; automation_limit: number;
  automation_step_limit: number; media_template_limit: number;
  review_destination_limit: number; included_message_credits: number;
  review_providers: string[];
};
type Usage = { business_id: string; business_name: string; plan_code: string; messages: number; customers: number; customer_limit: number | null };

const emptyPlan: Partial<Plan> = {
  code: "", name: "", description: "", monthly_price_minor: 0,
  annual_price_minor: 0, currency: "USD", trial_days: 14, badge: "",
  is_featured: false, is_self_serve: true, cta_label: "Start Free Trial", sort_order: 10, features: [], status: "draft",
  location_limit: 1, template_limit: 5, automation_limit: 1,
  automation_step_limit: 2, media_template_limit: 1,
  review_destination_limit: 1, included_message_credits: 0, monthly_customer_limit: 100,
  review_providers: ["google"],
};

export default function AdminBillingPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [usage, setUsage] = useState<Usage[]>([]);
  const [editing, setEditing] = useState<Partial<Plan> | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const results = await Promise.allSettled([
      api<{ data: Plan[] }>("/api/v1/admin/plans").then((response) => setPlans(response.data)),
      api<{ data: Usage[] }>("/api/v1/admin/usage").then((response) => setUsage(response.data)),
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
      "monthly_price_minor", "annual_price_minor", "trial_days", "sort_order",
      "location_limit", "template_limit", "automation_limit", "automation_step_limit",
      "media_template_limit", "review_destination_limit",
    ];
    const payload: Record<string, unknown> = {
      code: data.get("code"), name: data.get("name"), description: data.get("description"),
      currency: String(data.get("currency") ?? "USD").toUpperCase(),
      badge: data.get("badge") || null, status: data.get("status"),
      is_featured: data.get("is_featured") === "on",
      is_self_serve: data.get("is_self_serve") === "on",
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
    const activePlans = plans.filter((plan) => plan.status === "active" && plan.is_self_serve && plan.monthly_price_minor > 0);
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

  const totalMessages = usage.reduce((total, row) => total + row.messages, 0);
  return (
    <div className="mx-auto max-w-7xl">
      <div className="flex flex-wrap items-end justify-between gap-5">
        <div><p className="eyebrow">Platform billing</p><h1 className="page-title">Plans & subscriptions</h1><p className="page-intro">Create simple all-inclusive packages, control allowances, and publish their prices to Stripe.</p></div>
        <div className="flex gap-3"><button disabled={busy || plans.filter((plan) => plan.status === "active" && plan.is_self_serve).length === 0} className="rounded-xl border border-ink/10 bg-white px-5 py-3 text-sm font-semibold disabled:opacity-40" onClick={syncAll}>{busy ? "Working…" : "Sync self-serve plans"}</button><button className="button-primary" onClick={() => setEditing({ ...emptyPlan })}>+ Create plan</button></div>
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
            <p className="mt-5 text-3xl font-semibold">{plan.is_self_serve ? money(plan.monthly_price_minor, plan.currency) : "Custom"}{plan.is_self_serve&&<span className="text-xs font-normal text-ink/45"> / month</span>}</p>
            <p className="mt-1 text-xs text-ink/45">{plan.is_self_serve ? `${money(plan.annual_price_minor, plan.currency)} annually · ${plan.trial_days}-day trial` : plan.cta_label}</p>
            <div className="mt-5 grid grid-cols-2 gap-2"><Limit label="Customers / month" value={plan.monthly_customer_limit ?? "Custom"} /><Limit label="Locations" value={plan.location_limit} /><Limit label="Templates" value={plan.template_limit} /><Limit label="Automations" value={plan.automation_limit} /><Limit label="Review links" value={plan.review_destination_limit} /><Limit label="Media" value={plan.media_template_limit} /></div>
            <p className="mt-4 rounded-lg bg-paper px-3 py-2 text-xs text-ink/60">Real database plan · No per-message or overage charges.</p>
            {plan.is_self_serve&&<StripeStatus plan={plan}/>} {!plan.is_self_serve&&<p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">Sales-assisted plan · Stripe price not required</p>}
            <div className="mt-5 flex gap-3"><button className="text-sm font-semibold text-forest" onClick={() => setEditing(plan)}>Configure</button>{plan.is_self_serve&&<button disabled={busy} className="text-sm font-semibold text-forest disabled:opacity-40" onClick={() => sync(plan)}>{stripeState(plan)==="published" ? "Resync Stripe" : "Sync Stripe"}</button>}</div>
          </article>
        ))}</div>
      )}

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
      <Field name="currency" label="Currency" value={plan.currency} /><Field name="trial_days" label="Free trial days" value={plan.trial_days} number />
      <Field name="badge" label="Badge (optional)" value={plan.badge ?? ""} required={false} /><Field name="cta_label" label="Button label" value={plan.cta_label} /><Field name="sort_order" label="Display order" value={plan.sort_order} number />
      <label className="label">Status<select className="field" name="status" defaultValue={plan.status}><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select></label>
      <label className="mt-7 flex gap-2 text-sm"><input type="checkbox" name="is_featured" defaultChecked={plan.is_featured} /> Feature this plan</label>
      <label className="mt-7 flex gap-2 text-sm"><input type="checkbox" name="is_self_serve" defaultChecked={plan.is_self_serve} /> Allow self-service signup and Stripe checkout</label>
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
function Limit({ label, value }: { label: string; value: number | string }) { return <div className="rounded-lg bg-paper p-3 text-xs"><span className="text-ink/45">{label}</span><strong className="float-right">{value}</strong></div>; }
function stripeState(plan: Plan) { const count=[plan.stripe_product_id,plan.stripe_monthly_price_id,plan.stripe_annual_price_id].filter(Boolean).length; return count===3?"published":count===0?"not-synced":"partial"; }
function StripeStatus({plan}:{plan:Plan}) { const state=stripeState(plan); return <div className={`mt-3 flex items-center justify-between rounded-lg px-3 py-2 text-xs ${state==="published"?"bg-emerald-50 text-emerald-800":state==="partial"?"bg-amber-50 text-amber-800":"bg-slate-50 text-slate-600"}`}><span>Stripe status</span><strong>{state==="published"?"Published":state==="partial"?"Partial — sync again":"Not synchronized"}</strong></div>; }
function Metric({ label, value }: { label: string; value: string }) { return <div className="rounded-2xl border border-ink/8 bg-white p-5"><p className="text-xs text-ink/45">{label}</p><strong className="mt-2 block text-2xl">{value}</strong></div>; }
function money(value: number, currency: string) { return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(value / 100); }
