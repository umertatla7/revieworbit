"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Summary = {
  messages_this_month: number; messages_all_time: number; delivered_this_month: number;
  failed_this_month: number; credits_used_this_month: number; estimated_cost_minor_this_month: number;
  provider_cost_minor_this_month: number; visits_this_month: number; visits_all_time: number; review_link_clicks: number;
};
type Delivery = { id: string; customer_name: string; template_name: string | null; channel: string; delivery_type: string; status: string; recipient_last_four: string; billable_credits: number; estimated_cost_minor: number; provider_cost_minor: number | null; currency: string; body: string | null; created_at: string };
type Visit = { id: string; customer_name: string; location_name: string | null; source: string; type: string; status: string; amount: string | null; currency: string | null; completed_at: string };
type BusinessDetail = {
  id: string; name: string; legal_name: string | null; industry: string; status: string; plan_code: string;
  primary_email: string; phone: string; website_url: string | null; default_timezone: string; default_country: string;
  onboarding_status: string; operation_mode: string; locations_count: number; customers_count: number; pos_integrations_count: number;
  can_reset_owner_password: boolean;
  owners: { id: string; name: string; email: string }[];
  entitlements: { plan_name: string; location_limit: number; locations_used: number; template_limit: number; templates_used: number; media_template_limit: number; media_templates_used: number; review_destination_limit: number; review_destinations_used: number; included_message_credits: number; message_credits_used: number };
  analytics: { summary: Summary; by_channel: { channel: string; messages: number }[]; by_status: { status: string; messages: number }[]; recent_deliveries: Delivery[]; recent_visits: Visit[] };
  pos_integrations?: { id: string; provider: string; name: string; environment: string; status: string; last_synced_at?: string; sync_error?: string; toast_restaurants?: { id: string; restaurant_name?: string; restaurant_guid: string; status: string; last_synced_at?: string; location?: { name: string } }[] }[];
};

export default function AdminCustomerDetailPage() {
  const { businessId } = useParams<{ businessId: string }>();
  const [business, setBusiness] = useState<BusinessDetail | null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [resetOwner, setResetOwner] = useState<BusinessDetail["owners"][number] | null>(null);
  const [resetBusy, setResetBusy] = useState(false);

  useEffect(() => {
    api<{ data: BusinessDetail }>(`/api/v1/admin/businesses/${businessId}`)
      .then((result) => setBusiness(result.data))
      .catch((reason: Error) => setError(reason.message));
  }, [businessId]);

  if (error) return <div className="rounded-xl bg-white p-6 text-sm text-red-700">{error}</div>;
  if (!business) return <p className="text-sm text-ink/45">Loading customer account…</p>;

  async function resetPassword(formData: FormData) {
    if (!resetOwner) return;
    setResetBusy(true); setNotice("");
    try {
      const result = await api<{ message: string }>(`/api/v1/admin/businesses/${businessId}/owners/${resetOwner.id}/password`, {
        method: "POST",
        body: JSON.stringify({ password: formData.get("password"), password_confirmation: formData.get("password_confirmation") }),
      });
      setNotice(result.message); setResetOwner(null);
    } catch (reason) { setNotice(reason instanceof Error ? reason.message : "Unable to reset the owner password."); }
    finally { setResetBusy(false); }
  }

  const summary = business.analytics.summary;
  return <div className="mx-auto max-w-[1380px]">
    <div className="flex flex-col justify-between gap-4 border-b border-ink/8 pb-6 lg:flex-row lg:items-end">
      <div><Link href="/admin" className="text-xs font-semibold text-forest">← Customer accounts</Link><p className="eyebrow mt-4">Platform · Customer detail</p><h1 className="page-title">{business.name}</h1><p className="page-intro">Read-only platform analytics and account health. Use Manage workspace only when an audited customer-support session is required.</p></div>
      <div className="flex gap-2"><span className="pill capitalize">{business.plan_code} plan</span><span className="pill capitalize">{business.status}</span></div>
    </div>
    {notice && <p role="status" className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest">{notice}</p>}

    <section className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
      <Stat label="Messages this month" value={summary.messages_this_month} detail={`${summary.messages_all_time} all time`}/>
      <Stat label="Delivered" value={summary.delivered_this_month} detail={`${summary.failed_this_month} failed`}/>
      <Stat label="Estimated provider cost" value={money(summary.estimated_cost_minor_this_month)} detail={`${summary.credits_used_this_month} credits used`}/>
      <Stat label="Provider reported cost" value={money(summary.provider_cost_minor_this_month)} detail="Reconciled provider amount"/>
      <Stat label="Visits" value={summary.visits_this_month} detail={`${summary.visits_all_time} all time`}/>
      <Stat label="Review link clicked" value={summary.review_link_clicks} detail="Clicks only; not submitted reviews"/>
    </section>

    <div className="mt-6 grid gap-5 xl:grid-cols-[1.15fr_.85fr]">
      <section className="rounded-xl border border-ink/8 bg-white p-5"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="eyebrow">Account profile</p><h2 className="mt-1 text-lg font-semibold">Business and owner</h2></div>{business.can_reset_owner_password&&business.owners[0]&&<button className="rounded-lg border border-ink/10 px-3 py-2 text-xs font-semibold text-forest" onClick={()=>setResetOwner(business.owners[0])}>Reset owner password</button>}</div><dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2"><Info label="Owner" value={business.owners[0]?.name ?? "Not assigned"}/><Info label="Owner email" value={business.owners[0]?.email ?? "—"}/><Info label="Business email" value={business.primary_email || "—"}/><Info label="Business phone" value={business.phone || "—"}/><Info label="Industry" value={business.industry?.replaceAll("_", " ") || "—"}/><Info label="Time zone" value={business.default_timezone}/><Info label="Operation mode" value={business.operation_mode}/><Info label="POS connections" value={String(business.pos_integrations_count)}/></dl></section>
      <section className="rounded-xl border border-ink/8 bg-white p-5"><p className="eyebrow">Package usage</p><h2 className="mt-1 text-lg font-semibold">{business.entitlements.plan_name} limits</h2><div className="mt-5 space-y-4"><Usage label="Locations" used={business.entitlements.locations_used} limit={business.entitlements.location_limit}/><Usage label="Templates" used={business.entitlements.templates_used} limit={business.entitlements.template_limit}/><Usage label="Personalized media" used={business.entitlements.media_templates_used} limit={business.entitlements.media_template_limit}/><Usage label="Review links" used={business.entitlements.review_destinations_used} limit={business.entitlements.review_destination_limit}/><Usage label="Message credits" used={business.entitlements.message_credits_used} limit={business.entitlements.included_message_credits}/></div></section>
    </div>

    <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white"><header className="border-b border-ink/8 px-5 py-4"><h2 className="font-semibold">POS connections</h2><p className="mt-1 text-xs text-ink/45">Provider status and per-location mappings. Use Manage workspace for audited customer setup changes.</p></header>{business.pos_integrations?.length ? <div className="divide-y divide-ink/8">{business.pos_integrations.map((integration) => <article key={integration.id} className="px-5 py-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><strong>{integration.name}</strong><p className="mt-1 text-xs capitalize text-ink/45">{integration.provider} · {integration.environment} · {integration.last_synced_at ? `Last synced ${dateTime(integration.last_synced_at)}` : "Not synced"}</p></div><span className="pill capitalize">{integration.status.replaceAll("_", " ")}</span></div>{integration.sync_error && <p className="mt-3 text-xs text-red-700">{integration.sync_error}</p>}{!!integration.toast_restaurants?.length && <div className="mt-3 grid gap-2 sm:grid-cols-2">{integration.toast_restaurants.map((restaurant) => <div key={restaurant.id} className="rounded-lg bg-paper px-3 py-2 text-xs"><strong>{restaurant.location?.name ?? "Unmapped location"}</strong><p className="mt-1 text-ink/45">{restaurant.restaurant_name ?? restaurant.restaurant_guid} · {restaurant.status}</p></div>)}</div>}</article>)}</div> : <p className="px-5 py-8 text-sm text-ink/45">No POS integration records yet.</p>}</section>

    <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white"><header className="border-b border-ink/8 px-5 py-4"><h2 className="font-semibold">Recent messages</h2><p className="mt-1 text-xs text-ink/45">Latest activity, delivery outcome, credits, and estimated cost. Recipient numbers remain masked.</p></header><div className="overflow-x-auto"><table className="w-full min-w-[900px] text-left text-xs"><thead className="bg-paper text-[10px] uppercase tracking-wider text-ink/40"><tr><th className="px-5 py-3">Customer / message</th><th className="px-4 py-3">Channel</th><th className="px-4 py-3">Type</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Recipient</th><th className="px-4 py-3">Credits</th><th className="px-4 py-3">Cost</th><th className="px-4 py-3">Sent</th></tr></thead><tbody className="divide-y divide-ink/8">{business.analytics.recent_deliveries.map((delivery) => <tr key={delivery.id}><td className="px-5 py-4"><strong className="block">{delivery.customer_name || "Unknown customer"}</strong><span className="mt-1 block max-w-md truncate text-ink/45">{delivery.body || delivery.template_name || "No body snapshot"}</span></td><td className="px-4 py-4 uppercase">{delivery.channel}</td><td className="px-4 py-4 capitalize">{delivery.delivery_type.replaceAll("_", " ")}</td><td className="px-4 py-4"><span className="pill capitalize">{delivery.status}</span></td><td className="px-4 py-4">•••• {delivery.recipient_last_four}</td><td className="px-4 py-4">{delivery.billable_credits}</td><td className="px-4 py-4">{money(delivery.estimated_cost_minor, delivery.currency)}</td><td className="px-4 py-4">{dateTime(delivery.created_at)}</td></tr>)}</tbody></table></div>{business.analytics.recent_deliveries.length === 0 && <p className="px-5 py-8 text-sm text-ink/45">No messages recorded yet.</p>}</section>

    <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white"><header className="border-b border-ink/8 px-5 py-4"><h2 className="font-semibold">Recent visits</h2><p className="mt-1 text-xs text-ink/45">POS and manual visits remain clearly identified by source.</p></header><div className="grid divide-y divide-ink/8">{business.analytics.recent_visits.map((visit) => <article key={visit.id} className="grid gap-3 px-5 py-4 text-sm md:grid-cols-[1.4fr_1fr_100px_120px_170px] md:items-center"><div><strong>{visit.customer_name || "Unknown customer"}</strong><p className="mt-1 text-xs text-ink/40">{visit.location_name ?? "Unknown location"}</p></div><span className="capitalize">{visit.type}</span><span className="pill w-fit uppercase">{visit.source}</span><span>{visit.amount ? money(Math.round(Number(visit.amount) * 100), visit.currency ?? "USD") : "—"}</span><span className="text-xs text-ink/50">{dateTime(visit.completed_at)}</span></article>)}</div></section>
    {resetOwner&&<div className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-5"><section role="dialog" aria-modal="true" aria-labelledby="password-title" className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div className="flex items-start justify-between gap-4"><div><p className="eyebrow">Security</p><h2 id="password-title" className="mt-1 text-xl font-semibold">Reset owner password</h2></div><button aria-label="Close dialog" className="rounded-lg border border-ink/10 px-3 py-1.5" onClick={()=>setResetOwner(null)}>×</button></div><p className="mt-4 text-sm leading-6 text-ink/50">Set a temporary password for <strong>{resetOwner.email}</strong>. All existing browser and mobile sessions will be signed out. Share the password securely.</p><form action={resetPassword} className="mt-5 space-y-4"><label className="label">Temporary password<input className="field" name="password" type="password" autoComplete="new-password" minLength={12} required/></label><label className="label">Confirm temporary password<input className="field" name="password_confirmation" type="password" autoComplete="new-password" minLength={12} required/></label><p className="text-xs text-ink/45">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p><div className="flex justify-end gap-2"><button type="button" className="rounded-lg border border-ink/10 px-4 py-2.5 text-sm font-semibold" onClick={()=>setResetOwner(null)}>Cancel</button><button className="button-primary" disabled={resetBusy}>{resetBusy?"Resetting…":"Reset password"}</button></div></form></section></div>}
  </div>;
}

function Stat({ label, value, detail }: { label: string; value: string | number; detail: string }) { return <div className="rounded-xl border border-ink/8 bg-white p-4"><p className="text-xs text-ink/45">{label}</p><strong className="mt-2 block text-2xl">{value}</strong><p className="mt-1 text-[10px] text-ink/35">{detail}</p></div>; }
function Info({ label, value }: { label: string; value: string }) { return <div><dt className="text-[10px] font-bold uppercase tracking-wider text-ink/35">{label}</dt><dd className="mt-1 capitalize">{value}</dd></div>; }
function Usage({ label, used, limit }: { label: string; used: number; limit: number }) { const percent = Math.min(100, limit ? (used / limit) * 100 : 0); return <div><div className="flex justify-between text-xs"><span>{label}</span><strong>{used} / {limit}</strong></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-paper"><div className="h-full rounded-full bg-forest" style={{ width: `${percent}%` }}/></div></div>; }
function money(minor: number, currency = "USD") { return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(minor / 100); }
function dateTime(value: string) { return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)); }
