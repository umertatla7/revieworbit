"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type StripeSettings = { configured: boolean; publishable_key: string | null; secret_key_configured: boolean; webhook_secret_configured: boolean; mode: "test" | "live"; status: string; account_id: string | null; account_name: string | null; verified_at: string | null; last_error: string | null; webhook_url: string };

export default function StripeSetupPage() {
  const [settings, setSettings] = useState<StripeSettings | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  async function load() { setSettings((await api<{data: StripeSettings}>("/api/v1/admin/stripe")).data); }
  useEffect(() => { void api<{data: StripeSettings}>("/api/v1/admin/stripe").then(({data}) => setSettings(data)).catch((error: Error) => setMessage(error.message)); }, []);
  async function save(data: FormData) {
    setBusy(true); setMessage("");
    try {
      await api("/api/v1/admin/stripe", { method: "PUT", body: JSON.stringify({ publishable_key: data.get("publishable_key"), secret_key: data.get("secret_key") || null, webhook_secret: data.get("webhook_secret") || null, mode: data.get("mode") }) });
      await load(); setMessage("Stripe configuration saved. Verify it before syncing plans.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to save Stripe configuration."); } finally { setBusy(false); }
  }
  async function verify() {
    setBusy(true); setMessage("");
    try { await api("/api/v1/admin/stripe/verify", { method: "POST" }); await load(); setMessage("Stripe account verified successfully."); }
    catch (error) { setMessage(error instanceof Error ? error.message : "Unable to verify Stripe."); } finally { setBusy(false); }
  }
  return <div className="mx-auto max-w-6xl">
    <div className="flex flex-wrap items-end justify-between gap-5"><div><p className="eyebrow">Payment infrastructure</p><h1 className="page-title">Stripe configuration</h1><p className="page-intro">Connect one ReviewOrbit Stripe account. Customer payment details remain on Stripe-hosted, PCI-compliant pages and are never stored here.</p></div><span className={`pill ${settings?.status === "verified" ? "bg-mint/30" : ""}`}>{settings?.status?.replaceAll("_", " ") ?? "Loading"}</span></div>
    {message && <p className="mt-5 rounded-xl border border-ink/8 bg-white px-4 py-3 text-sm">{message}</p>}
    <div className="mt-7 grid gap-6 lg:grid-cols-[1.25fr_.75fr]">
      <form action={save} className="rounded-2xl border border-ink/8 bg-white p-6 shadow-sm"><h2 className="text-xl font-semibold">API credentials</h2><p className="mt-2 text-sm leading-6 text-ink/50">Start with Stripe test mode. Secret values are encrypted and never returned after saving.</p>
        <label className="label mt-6">Mode<select className="field" name="mode" defaultValue={settings?.mode ?? "test"}><option value="test">Test mode</option><option value="live">Live mode</option></select></label>
        <label className="label mt-4">Publishable key<input className="field" name="publishable_key" required defaultValue={settings?.publishable_key ?? ""} placeholder="pk_test_..." autoComplete="off"/></label>
        <label className="label mt-4">Secret key<input className="field" name="secret_key" type="password" placeholder={settings?.secret_key_configured ? "Saved — leave blank to keep current key" : "sk_test_..."} autoComplete="new-password"/></label>
        <label className="label mt-4">Webhook signing secret<input className="field" name="webhook_secret" type="password" placeholder={settings?.webhook_secret_configured ? "Saved — leave blank to keep current secret" : "whsec_..."} autoComplete="new-password"/></label>
        <div className="mt-6 flex flex-wrap gap-3"><button disabled={busy} className="button-primary">{busy ? "Working…" : "Save configuration"}</button><button disabled={busy || !settings?.secret_key_configured} type="button" className="rounded-lg border border-ink/10 px-4 py-2 text-sm font-semibold" onClick={verify}>Verify Stripe account</button></div>
      </form>
      <aside className="space-y-5"><section className="rounded-2xl bg-forest p-6 text-white"><p className="text-xs font-bold uppercase tracking-[.18em] text-mint">Connection</p><h2 className="mt-3 text-xl font-semibold">{settings?.account_name ?? "Not verified"}</h2><p className="mt-2 text-sm text-white/60">{settings?.account_id ?? "Save and verify your API credentials."}</p><div className="mt-5 grid grid-cols-2 gap-3"><Status label="Secret key" ready={settings?.secret_key_configured}/><Status label="Webhook secret" ready={settings?.webhook_secret_configured}/></div></section>
        <section className="rounded-2xl border border-ink/8 bg-white p-6"><h3 className="font-semibold">Webhook endpoint</h3><p className="mt-2 text-sm leading-6 text-ink/50">Add this endpoint in Stripe Workbench and subscribe to checkout, subscription, and invoice events.</p><code className="mt-4 block break-all rounded-lg bg-paper p-3 text-xs">{settings?.webhook_url ?? "—"}</code></section>
      </aside>
    </div>
  </div>;
}
function Status({label, ready}: {label: string; ready?: boolean}) { return <div className="rounded-xl bg-white/8 p-3"><p className="text-xs text-white/55">{label}</p><strong className="mt-1 block text-sm">{ready ? "Configured" : "Required"}</strong></div>; }
