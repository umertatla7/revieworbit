"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type StripeSettings = {
  configured: boolean;
  publishable_key: string | null;
  secret_key_configured: boolean;
  webhook_secret_configured: boolean;
  secret_key_hint: string | null;
  webhook_secret_hint: string | null;
  mode: "test" | "live";
  status: string;
  account_id: string | null;
  account_name: string | null;
  portal_configured: boolean;
  verified_at: string | null;
  last_error: string | null;
  webhook_url: string;
};

const blank = { publishable_key: "", secret_key: "", webhook_secret: "", mode: "test" as const };

export default function StripeSetupPage() {
  const [settings, setSettings] = useState<StripeSettings | null>(null);
  const [draft, setDraft] = useState<{publishable_key:string;secret_key:string;webhook_secret:string;mode:"test"|"live"}>(blank);
  const [showSecrets, setShowSecrets] = useState(false);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  function applySettings(data: StripeSettings) {
    setSettings(data);
    setDraft((current) => ({ ...current, publishable_key: data.publishable_key ?? "", mode: data.mode }));
  }

  useEffect(() => {
    void api<{data: StripeSettings}>("/api/v1/admin/stripe")
      .then(({data}) => applySettings(data))
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setMessage("");
    try {
      const result = await api<{data: StripeSettings}>("/api/v1/admin/stripe", {
        method: "PUT",
        body: JSON.stringify({ ...draft, secret_key: draft.secret_key || null, webhook_secret: draft.webhook_secret || null }),
      });
      applySettings(result.data);
      setDraft((current) => ({ ...current, secret_key: "", webhook_secret: "" }));
      setMessage("Credentials saved securely. Now verify the Stripe account.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to save Stripe configuration.");
    } finally { setBusy(false); }
  }

  async function verify() {
    setBusy(true); setMessage("");
    try {
      const result = await api<{data: StripeSettings}>("/api/v1/admin/stripe/verify", { method: "POST" });
      applySettings(result.data); setMessage("Stripe account verified successfully. You can now synchronize your plans.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to verify Stripe."); }
    finally { setBusy(false); }
  }

  return <div className="mx-auto max-w-6xl">
    <div className="flex flex-wrap items-end justify-between gap-5">
      <div><p className="eyebrow">Payment infrastructure</p><h1 className="page-title">Stripe configuration</h1><p className="page-intro">Connect one Breviews Stripe account. Customer card details remain on Stripe and are never stored in Breviews.</p></div>
      <span className={`pill ${settings?.status === "verified" ? "bg-mint/30" : ""}`}>{settings?.status?.replaceAll("_", " ") ?? "Loading"}</span>
    </div>
    {message && <p className="mt-5 rounded-xl border border-ink/8 bg-white px-4 py-3 text-sm">{message}</p>}
    <div className="mt-7 grid gap-6 lg:grid-cols-[1.25fr_.75fr]">
      <form onSubmit={save} className="rounded-2xl border border-ink/8 bg-white p-6 shadow-sm">
        <div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-semibold">API credentials</h2><p className="mt-2 text-sm leading-6 text-ink/50">Use Stripe test keys for sandbox testing. Saved secrets are shown only as masked fingerprints.</p></div><button type="button" className="text-xs font-semibold text-forest" onClick={() => setShowSecrets((value) => !value)}>{showSecrets ? "Hide entered keys" : "Show entered keys"}</button></div>
        <label className="label mt-6">Mode<select className="field" value={draft.mode} onChange={(event) => setDraft({...draft, mode:event.target.value as "test"|"live"})}><option value="test">Test / sandbox mode</option><option value="live">Live mode</option></select></label>
        <label className="label mt-4">Publishable key<input className="field font-mono text-sm" required value={draft.publishable_key} onChange={(event) => setDraft({...draft,publishable_key:event.target.value.trim()})} placeholder="pk_test_..." autoComplete="off"/></label>
        <SecretField label="Secret key" value={draft.secret_key} savedHint={settings?.secret_key_hint} show={showSecrets} placeholder="sk_test_..." onChange={(value) => setDraft({...draft,secret_key:value.trim()})}/>
        <SecretField label="Webhook signing secret" value={draft.webhook_secret} savedHint={settings?.webhook_secret_hint} show={showSecrets} placeholder="whsec_..." onChange={(value) => setDraft({...draft,webhook_secret:value.trim()})}/>
        <div className="mt-6 flex flex-wrap gap-3"><button disabled={busy} className="button-primary">{busy ? "Saving…" : "Save credentials"}</button><button disabled={busy || !settings?.secret_key_configured} type="button" className="rounded-lg border border-ink/10 px-4 py-2 text-sm font-semibold disabled:opacity-40" onClick={verify}>Verify Stripe account</button></div>
      </form>
      <aside className="space-y-5"><section className="rounded-2xl bg-forest p-6 text-white"><p className="text-xs font-bold uppercase tracking-[.18em] text-mint">Connection</p><h2 className="mt-3 text-xl font-semibold">{settings?.account_name ?? "Not verified"}</h2><p className="mt-2 text-sm text-white/60">{settings?.account_id ?? "Save and verify your API credentials."}</p><div className="mt-5 grid grid-cols-2 gap-3"><Status label="Secret key" value={settings?.secret_key_hint ?? "Required"}/><Status label="Webhook secret" value={settings?.webhook_secret_hint ?? "Required"}/><Status label="Customer portal" value={settings?.portal_configured ? "Configured" : "After plan sync"}/><Status label="Mode" value={settings?.mode ?? "Test"}/></div></section>
        <section className="rounded-2xl border border-ink/8 bg-white p-6"><h3 className="font-semibold">Webhook endpoint</h3><p className="mt-2 text-sm leading-6 text-ink/50">Add this endpoint in Stripe Workbench and subscribe to checkout, subscription, and invoice events.</p><code className="mt-4 block break-all rounded-lg bg-paper p-3 text-xs">{settings?.webhook_url ?? "—"}</code></section>
      </aside>
    </div>
  </div>;
}

function SecretField({label,value,savedHint,show,placeholder,onChange}:{label:string;value:string;savedHint?:string|null;show:boolean;placeholder:string;onChange:(value:string)=>void}) {
  return <label className="label mt-4">{label}<input className="field font-mono text-sm" type={show ? "text" : "password"} value={value} onChange={(event)=>onChange(event.target.value)} placeholder={savedHint ? `Saved ${savedHint} — leave blank to keep it` : placeholder} autoComplete="new-password"/>{savedHint && <span className="mt-1 block text-xs font-normal text-ink/45">Saved key: {savedHint}</span>}</label>;
}
function Status({label,value}:{label:string;value:string}) { return <div className="rounded-xl bg-white/8 p-3"><p className="text-xs text-white/55">{label}</p><strong className="mt-1 block truncate text-sm capitalize">{value}</strong></div>; }
