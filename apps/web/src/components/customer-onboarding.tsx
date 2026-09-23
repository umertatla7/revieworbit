"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { PosSetup } from "@/components/pos-setup";

type Location = { id: string; name: string; timezone: string; google_review_url?: string };
type Onboarding = { business: { id: string; name: string; default_timezone: string; default_country: string; operation_mode: string; onboarding_status: string; locations: Location[] }; checks: Record<string, boolean>; completed_count: number; total_count: number };

const checkLabels: Record<string, string> = { business_details: "Business details", primary_location: "Primary location", google_review_url: "Google review URL", message_template: "Default message", messaging_preferences: "Messaging preferences", consent_confirmation: "Consent confirmation", integration: "POS or manual mode" };

export function CustomerOnboarding() {
  const router = useRouter();
  const [data, setData] = useState<Onboarding | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  async function load() { const result = await api<{ data: Onboarding }>("/api/v1/onboarding", {}, true); setData(result.data); }
  useEffect(() => {
    api<{ data: Onboarding }>("/api/v1/onboarding", {}, true)
      .then((result) => setData(result.data))
      .catch((error) => setMessage(error.message));
  }, []);

  async function saveBusiness(formData: FormData) {
    await run(async () => { await api("/api/v1/onboarding", { method: "PATCH", body: JSON.stringify({ name: formData.get("name"), default_timezone: formData.get("timezone"), default_country: formData.get("country"), messaging_preferences: { channel: formData.get("channel"), quiet_hours_start: formData.get("quiet_start"), quiet_hours_end: formData.get("quiet_end") }, onboarding_step: 3 }) }, true); }, "Business and messaging preferences saved.");
  }
  async function saveLocation(formData: FormData) {
    const existing = data?.business.locations?.[0];
    await run(async () => { await api(existing ? `/api/v1/locations/${existing.id}` : "/api/v1/locations", { method: existing ? "PATCH" : "POST", body: JSON.stringify({ name: formData.get("name"), timezone: formData.get("timezone"), google_review_url: formData.get("google_review_url") }) }, true); }, "Primary location saved.");
  }
  async function saveTemplate(formData: FormData) {
    await run(async () => { await api("/api/v1/templates", { method: "POST", body: JSON.stringify({ name: "Default review request", channel: "sms", status: "active", body: formData.get("body") }) }, true); }, "Default template saved.");
  }
  async function confirmConsent() {
    await run(async () => { await api("/api/v1/onboarding", { method: "PATCH", body: JSON.stringify({ consent_confirmed: true, onboarding_step: 8 }) }, true); }, "Consent responsibility confirmed.");
  }
  async function complete() {
    await run(async () => { await api("/api/v1/onboarding/complete", { method: "POST", body: "{}" }, true); router.push("/dashboard"); }, "Onboarding completed.");
  }
  async function run(action: () => Promise<void>, success: string) { setBusy(true); setMessage(""); try { await action(); setMessage(success); await load(); } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to save this step."); } finally { setBusy(false); } }

  const percent = data ? Math.round((data.completed_count / data.total_count) * 100) : 0;
  return <main className="min-h-screen bg-paper px-6 py-8 lg:px-10">
    <div className="mx-auto max-w-7xl"><div className="flex items-center justify-between"><div className="flex items-center gap-3 font-semibold"><span className="grid size-10 place-items-center rounded-xl bg-forest text-sm text-white">BR</span>Breviews</div><button className="text-sm font-semibold text-forest underline" onClick={() => router.push("/dashboard")}>Finish later</button></div>
      <div className="mt-10 grid gap-8 lg:grid-cols-[300px_1fr]">
        <aside className="h-fit rounded-[2rem] bg-forest p-6 text-white lg:sticky lg:top-8"><p className="text-xs font-bold uppercase tracking-[0.2em] text-mint">Customer onboarding</p><h1 className="mt-3 text-3xl font-semibold">Launch your workspace</h1><p className="mt-3 text-sm leading-6 text-white/60">Complete the essentials yourself or let Breviews support configure them with audited access.</p><div className="mt-7 h-2 overflow-hidden rounded-full bg-white/15"><div className="h-full rounded-full bg-mint" style={{ width: `${percent}%` }}/></div><p className="mt-2 text-sm text-white/65">{data?.completed_count ?? 0} of {data?.total_count ?? 7} essentials complete</p><div className="mt-7 space-y-3">{Object.entries(data?.checks ?? {}).map(([key, complete]) => <div key={key} className="flex items-center gap-3 text-sm"><span className={`grid size-6 place-items-center rounded-full ${complete ? "bg-mint text-ink" : "bg-white/10 text-white/40"}`}>{complete ? "✓" : "·"}</span><span className={complete ? "text-white" : "text-white/55"}>{checkLabels[key]}</span></div>)}</div></aside>
        <div className="space-y-7"><div><p className="eyebrow">Welcome{data?.business.name ? `, ${data.business.name}` : ""}</p><h2 className="page-title">Set up your customer workspace</h2><p className="page-intro">These settings control tenant identity, review destinations, consent-aware messaging, and how completed visits reach Breviews.</p></div>{message && <p role="status" className="rounded-2xl border border-forest/15 bg-white px-5 py-4 text-sm text-forest">{message}</p>}
          <section className="panel"><Step number="01" title="Business and messaging" text="Confirm the sender identity, timezone, and quiet-hour defaults."/><form action={saveBusiness} className="mt-6 grid gap-4 md:grid-cols-2"><label className="label">Business name<input className="field" name="name" required defaultValue={data?.business.name}/></label><label className="label">Country<input className="field" name="country" maxLength={2} required defaultValue={data?.business.default_country ?? "US"}/></label><label className="label">Time zone<input className="field" name="timezone" required defaultValue={data?.business.default_timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone}/></label><label className="label">Channel<select className="field" name="channel" defaultValue="sms"><option value="sms">SMS</option><option value="mms">MMS</option></select></label><label className="label">Quiet hours begin<input className="field" type="time" name="quiet_start" defaultValue="20:00"/></label><label className="label">Quiet hours end<input className="field" type="time" name="quiet_end" defaultValue="09:00"/></label><button className="button-primary md:col-span-2" disabled={busy}>Save business settings</button></form></section>
          <section className="panel"><Step number="02" title="Primary location" text="The Google URL is the immutable destination behind future tracking links."/><form action={saveLocation} className="mt-6 grid gap-4 md:grid-cols-2"><label className="label">Location name<input className="field" name="name" required placeholder="Main Street Location" defaultValue={data?.business.locations?.[0]?.name}/></label><label className="label">Time zone<input className="field" name="timezone" required defaultValue={data?.business.locations?.[0]?.timezone ?? data?.business.default_timezone}/></label><label className="label md:col-span-2">Google review URL<input className="field" type="url" name="google_review_url" required placeholder="https://g.page/r/.../review" defaultValue={data?.business.locations?.[0]?.google_review_url}/></label><button className="button-primary md:col-span-2" disabled={busy}>{data?.business.locations?.length ? "Update primary location" : "Save primary location"}</button></form></section>
          {!data?.checks.message_template && <section className="panel"><Step number="03" title="Default review request" text="The required review link variable is included. Keep the request neutral and ask for honest feedback."/><form action={saveTemplate} className="mt-6"><label className="label">Message<textarea className="field min-h-32" name="body" defaultValue="Hi {{customer_first_name}}, thank you for visiting {{business_name}}. We would appreciate your honest feedback. Share your experience here: {{review_link}}"/></label><button className="button-primary mt-4" disabled={busy}>Save default template</button></form></section>}
          <section className="panel"><Step number="04" title="Consent responsibility" text="Breviews only schedules messages for contacts with recorded consent and no active suppression."/><label className="mt-5 flex items-start gap-3 rounded-2xl bg-paper p-4 text-sm leading-6"><input className="mt-1 size-4" type="checkbox" checked={Boolean(data?.checks.consent_confirmation)} readOnly/><span>I confirm that our business will record and retain lawful messaging consent. A POS record will not automatically override an opt-out.</span></label>{!data?.checks.consent_confirmation && <button className="button-primary mt-4" onClick={confirmConsent} disabled={busy}>Confirm consent responsibility</button>}</section>
          <section className="panel"><Step number="05" title="Connect your POS" text="Connect Square securely to import previous and upcoming appointments, use the generic API, or keep manual mode."/><div className="mt-6"><PosSetup onChanged={load}/></div></section>
          <section className="rounded-[2rem] bg-ink p-7 text-white"><p className="text-xs font-bold uppercase tracking-[0.2em] text-mint">Final check</p><h2 className="mt-3 text-2xl font-semibold">Ready to open the dashboard?</h2><p className="mt-2 text-sm leading-6 text-white/60">You can continue refining locations, templates, customers, and POS settings after onboarding.</p><button className="mt-5 rounded-xl bg-mint px-5 py-3 text-sm font-semibold text-ink disabled:opacity-40" disabled={busy || !data || data.completed_count < data.total_count} onClick={complete}>Complete onboarding</button></section>
        </div>
      </div>
    </div>
  </main>;
}

function Step({ number, title, text }: { number: string; title: string; text: string }) { return <div className="flex gap-4"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-mint text-xs font-bold text-ink">{number}</span><div><h2 className="text-xl font-semibold">{title}</h2><p className="mt-1 text-sm leading-6 text-ink/55">{text}</p></div></div>; }
