"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Plan = {
  id: string; code: string; name: string; monthly_price_minor: number; currency: string;
  location_limit: number; template_limit: number; automation_limit: number; automation_step_limit: number;
  media_template_limit: number; included_message_credits: number; overage_price_minor: number;
  estimated_sms_provider_cost_minor: number; estimated_mms_provider_cost_minor: number;
  estimated_whatsapp_provider_cost_minor: number; review_providers: string[]; allow_overage: boolean;
};
type Usage = { business_id: string; business_name: string; plan_code: string; messages: number; delivered: number; failed: number; credits_used: number; included_credits: number; estimated_cost_minor: number; currency: string };
const numericFields = ["monthly_price_minor", "location_limit", "template_limit", "automation_limit", "automation_step_limit", "media_template_limit", "included_message_credits", "overage_price_minor", "estimated_sms_provider_cost_minor", "estimated_mms_provider_cost_minor", "estimated_whatsapp_provider_cost_minor"] as const;

export default function AdminBillingPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [usage, setUsage] = useState<Usage[]>([]);
  const [editing, setEditing] = useState<Plan | null>(null);
  const [message, setMessage] = useState("");

  async function load() {
    const [planResult, usageResult] = await Promise.all([
      api<{ data: Plan[] }>("/api/v1/admin/plans"),
      api<{ data: Usage[] }>("/api/v1/admin/usage"),
    ]);
    setPlans(planResult.data); setUsage(usageResult.data);
  }
  useEffect(() => {
    void Promise.all([api<{ data: Plan[] }>("/api/v1/admin/plans"), api<{ data: Usage[] }>("/api/v1/admin/usage")])
      .then(([planResult, usageResult]) => { setPlans(planResult.data); setUsage(usageResult.data); })
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save(data: FormData) {
    if (!editing) return;
    const payload: Record<string, unknown> = { name: data.get("name"), allow_overage: data.get("allow_overage") === "on", review_providers: data.getAll("review_providers") };
    numericFields.forEach((key) => payload[key] = Number(data.get(key)));
    try {
      await api(`/api/v1/admin/plans/${editing.id}`, { method: "PATCH", body: JSON.stringify(payload) });
      setEditing(null); setMessage("Plan entitlements and cost assumptions updated."); await load();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to update plan."); }
  }

  return <div className="mx-auto max-w-7xl">
    <p className="eyebrow">Platform billing</p><h1 className="page-title">Plans, credits & messaging cost</h1>
    <p className="page-intro">Configure product limits centrally and compare each customer’s message-credit use with estimated provider cost. Update the cost assumptions when Twilio pricing changes.</p>
    {message && <p className="mt-5 rounded-xl bg-white px-4 py-3 text-sm text-forest">{message}</p>}
    <div className="mt-7 grid gap-4 lg:grid-cols-3">{plans.map((plan) => <article key={plan.id} className="rounded-xl border border-ink/8 bg-white p-5"><div className="flex justify-between"><div><p className="eyebrow">{plan.code}</p><h2 className="mt-1 text-xl font-semibold">{plan.name}</h2></div><button className="text-xs font-semibold text-forest" onClick={() => setEditing(plan)}>Configure</button></div><p className="mt-4 text-2xl font-semibold">{money(plan.monthly_price_minor, plan.currency)}<span className="text-xs font-normal text-ink/40"> / month</span></p><div className="mt-5 grid grid-cols-2 gap-3 text-xs"><Limit label="Locations" value={plan.location_limit}/><Limit label="Templates" value={plan.template_limit}/><Limit label="Automations" value={plan.automation_limit}/><Limit label="Steps / rule" value={plan.automation_step_limit}/><Limit label="Media" value={plan.media_template_limit}/><Limit label="Credits" value={plan.included_message_credits}/></div><p className="mt-4 text-xs capitalize text-ink/45">Review links: {plan.review_providers.join(", ")}</p></article>)}</div>
    <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white"><header className="border-b border-ink/8 px-5 py-4"><h2 className="font-semibold">Customer usage this month</h2><p className="mt-1 text-xs text-ink/45">Automatic and controlled manual messages are included. Cost is an estimate based on the configured channel rates.</p></header><div className="hidden grid-cols-[1.4fr_100px_110px_120px_130px_120px] gap-4 border-b border-ink/8 bg-paper/70 px-5 py-3 text-[10px] font-bold uppercase tracking-wider text-ink/35 lg:grid"><span>Customer</span><span>Plan</span><span>Messages</span><span>Delivered / failed</span><span>Credits</span><span>Est. cost</span></div><div className="divide-y divide-ink/8">{usage.map((row) => <article key={row.business_id} className="grid gap-3 px-5 py-4 text-sm lg:grid-cols-[1.4fr_100px_110px_120px_130px_120px] lg:items-center"><strong>{row.business_name}</strong><span className="pill w-fit capitalize">{row.plan_code}</span><span>{row.messages}</span><span>{row.delivered} / {row.failed}</span><span>{row.credits_used} / {row.included_credits}</span><strong>{money(row.estimated_cost_minor, row.currency)}</strong></article>)}</div></section>
    {editing && <PlanDialog plan={editing} onClose={() => setEditing(null)} onSave={save}/>} 
  </div>;
}

function PlanDialog({ plan, onClose, onSave }: { plan: Plan; onClose: () => void; onSave: (data: FormData) => void }) {
  return <div className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-4"><form action={onSave} className="max-h-[92vh] w-full max-w-3xl overflow-auto rounded-xl bg-white p-6 shadow-2xl"><div className="flex justify-between"><div><p className="eyebrow">Package entitlements</p><h2 className="mt-1 text-xl font-semibold">Configure {plan.name}</h2></div><button type="button" onClick={onClose}>×</button></div>
    <div className="mt-6 grid gap-4 sm:grid-cols-2"><Field name="name" label="Plan name" value={plan.name}/><Field name="monthly_price_minor" label="Monthly price (minor units)" value={plan.monthly_price_minor}/><Field name="location_limit" label="Locations" value={plan.location_limit}/><Field name="template_limit" label="Message templates" value={plan.template_limit}/><Field name="automation_limit" label="Automations" value={plan.automation_limit}/><Field name="automation_step_limit" label="Steps per automation" value={plan.automation_step_limit}/><Field name="media_template_limit" label="Personalized media" value={plan.media_template_limit}/><Field name="included_message_credits" label="Included message credits" value={plan.included_message_credits}/><Field name="overage_price_minor" label="Customer overage / credit (minor)" value={plan.overage_price_minor}/></div>
    <div className="mt-6 rounded-xl border border-ink/10 bg-paper p-4"><p className="text-sm font-semibold">Provider cost assumptions</p><p className="mt-1 text-xs text-ink/45">Minor currency units; use 0 until a rate is confirmed. Final Twilio price reconciliation remains separate.</p><div className="mt-4 grid gap-4 sm:grid-cols-3"><Field name="estimated_sms_provider_cost_minor" label="SMS segment" value={plan.estimated_sms_provider_cost_minor}/><Field name="estimated_mms_provider_cost_minor" label="MMS message" value={plan.estimated_mms_provider_cost_minor}/><Field name="estimated_whatsapp_provider_cost_minor" label="WhatsApp message" value={plan.estimated_whatsapp_provider_cost_minor}/></div></div>
    <div className="mt-5"><p className="label">Included review providers</p><div className="mt-2 flex flex-wrap gap-3">{["google", "trustpilot", "facebook", "yelp", "other"].map((provider) => <label key={provider} className="pill capitalize"><input className="mr-2" type="checkbox" name="review_providers" value={provider} defaultChecked={plan.review_providers.includes(provider)}/>{provider}</label>)}</div></div>
    <label className="mt-5 flex gap-2 text-sm"><input type="checkbox" name="allow_overage" defaultChecked={plan.allow_overage}/>Allow messages after included credits are used</label><div className="mt-6 flex justify-end gap-2"><button type="button" className="rounded-lg border border-ink/10 px-4 py-2" onClick={onClose}>Cancel</button><button className="button-primary">Save plan</button></div>
  </form></div>;
}
function Limit({ label, value }: { label: string; value: number }) { return <div className="rounded-lg bg-paper p-3"><p className="text-ink/40">{label}</p><strong className="mt-1 block">{value}</strong></div>; }
function Field({ name, label, value }: { name: string; label: string; value: string | number }) { return <label className="label">{label}<input className="field" name={name} required type={typeof value === "number" ? "number" : "text"} min={typeof value === "number" ? 0 : undefined} defaultValue={value}/></label>; }
function money(minor: number, currency: string) { return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(minor / 100); }
