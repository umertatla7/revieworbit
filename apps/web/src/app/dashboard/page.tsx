"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Overview = {
  business: { name: string; onboarding_status: string; operation_mode: string; locations: unknown[]; pos_integrations: { status: string }[] };
  checks: Record<string, boolean>; completed_count: number; total_count: number;
};

export default function DashboardPage() {
  const [overview, setOverview] = useState<Overview | null>(null);
  const [counts, setCounts] = useState({ customers: 0, visits: 0, automations: 0 });
  const [message, setMessage] = useState("");

  useEffect(() => {
    Promise.all([
      api<{ data: Overview }>("/api/v1/onboarding", {}, true),
      api<{ data: unknown[] }>("/api/v1/customers", {}, true),
      api<{ data: unknown[] }>("/api/v1/visits", {}, true),
      api<{ data: unknown[] }>("/api/v1/automations", {}, true),
    ]).then(([onboarding, customers, visits, automations]) => {
      setOverview(onboarding.data);
      setCounts({ customers: customers.data.length, visits: visits.data.length, automations: automations.data.length });
    }).catch((error) => setMessage(error.message));
  }, []);

  const progress = overview ? Math.round((overview.completed_count / overview.total_count) * 100) : 0;
  const connection = overview?.business.pos_integrations?.[0];

  return <div className="mx-auto max-w-[1380px]">
    <div className="flex flex-col justify-between gap-4 border-b border-ink/8 pb-6 sm:flex-row sm:items-end"><div><p className="eyebrow">Workspace overview</p><h1 className="page-title">Good afternoon, {overview?.business.name ?? "ReviewOrbit"}</h1><p className="page-intro">A concise view of customer activity, visit automation, and workspace readiness.</p></div><div className="flex gap-2"><Link className="rounded-lg border border-ink/10 bg-white px-4 py-2.5 text-sm font-semibold" href="/dashboard/integrations">Manage POS</Link><Link className="button-primary" href="/dashboard/automations">+ Record visit</Link></div></div>
    {message && <p role="alert" className="mt-5 rounded-lg bg-red-50 px-4 py-3 text-xs text-red-800">{message}</p>}
    <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Metric label="Customers" value={counts.customers} detail="Consent-aware contacts"/><Metric label="Completed visits" value={counts.visits} detail="Manual and connected sources"/><Metric label="Active automations" value={counts.automations} detail="Eligibility rules"/><Metric label="POS status" value={connection?.status.replaceAll("_", " ") ?? overview?.business.operation_mode ?? "manual"} detail={connection ? "Connection configured" : "No external POS yet"}/></div>
    <div className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]"><section className="overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm"><div className="flex items-center justify-between border-b border-ink/8 px-5 py-4"><div><h2 className="text-sm font-semibold">Workspace readiness</h2><p className="mt-0.5 text-[11px] text-ink/40">Complete the essentials before enabling live messaging.</p></div><div className="text-right"><strong className="text-lg">{progress}%</strong><p className="text-[10px] text-ink/35">{overview?.completed_count ?? 0}/{overview?.total_count ?? 7} complete</p></div></div><div className="h-1 bg-paper"><div className="h-full bg-forest" style={{ width: `${progress}%` }}/></div><div className="grid gap-x-6 px-5 py-2 sm:grid-cols-2">{Object.entries(overview?.checks ?? {}).map(([key, complete]) => <div key={key} className="flex items-center gap-3 border-b border-ink/6 py-3"><span className={`grid size-5 place-items-center rounded-full text-[9px] font-bold ${complete ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-400"}`}>{complete ? "✓" : "·"}</span><span className="text-xs font-medium capitalize">{key.replaceAll("_", " ")}</span></div>)}</div>{overview?.business.onboarding_status !== "completed" && <div className="border-t border-ink/8 px-5 py-4"><Link href="/onboarding" className="text-xs font-semibold text-forest">Continue onboarding →</Link></div>}</section><aside className="space-y-5"><section className="rounded-xl bg-[#17231f] p-5 text-white"><p className="text-[10px] font-bold uppercase tracking-[0.16em] text-mint">Quick actions</p><div className="mt-3 space-y-1"><Quick href="/dashboard/customers" title="Add or import customers"/><Quick href="/dashboard/templates" title="Prepare a message template"/><Quick href="/dashboard/messages" title="Configure SMS and WhatsApp"/><Quick href="/dashboard/integrations" title="Connect a POS or API"/><Quick href="/dashboard/settings" title="Manage business settings"/></div></section><section className="rounded-xl border border-ink/8 bg-white p-5"><p className="eyebrow">Messaging</p><p className="mt-2 text-sm font-semibold">Twilio delivery is available</p><p className="mt-1 text-xs leading-5 text-ink/40">Configure an isolated subaccount, approved senders, and channel-specific consent before activation.</p><Link href="/dashboard/messages" className="mt-3 inline-block text-xs font-semibold text-forest">Open messaging setup →</Link></section></aside></div>
  </div>;
}

function Metric({ label, value, detail }: { label: string; value: number | string; detail: string }) { return <article className="rounded-xl border border-ink/8 bg-white p-4 shadow-sm"><p className="text-xs text-ink/45">{label}</p><p className="mt-2 text-2xl font-semibold capitalize">{value}</p><p className="mt-1 text-[10px] text-ink/35">{detail}</p></article>; }
function Quick({ href, title }: { href: string; title: string }) { return <Link className="flex items-center justify-between rounded-lg px-3 py-2.5 text-xs font-medium text-white/65 transition hover:bg-white/8 hover:text-white" href={href}><span>{title}</span><span>→</span></Link>; }
