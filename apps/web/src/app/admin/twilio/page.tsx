"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type TwilioSetting = {
  configured: boolean;
  account_sid?: string;
  auth_token_configured: boolean;
  mode: "trial" | "production";
  status: "not_configured" | "draft" | "verified" | "error";
  verified_at?: string;
  last_error?: string;
  status_callback_url: string;
  inbound_webhook_url: string;
};

export default function AdminTwilioPage() {
  const [setting, setSetting] = useState<TwilioSetting | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  async function load() {
    const result = await api<{ data: TwilioSetting }>("/api/v1/admin/twilio");
    setSetting(result.data);
  }

  useEffect(() => {
    void api<{ data: TwilioSetting }>("/api/v1/admin/twilio")
      .then((result) => setSetting(result.data))
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save(formData: FormData) {
    setBusy(true); setMessage("");
    try {
      const result = await api<{ data: TwilioSetting }>("/api/v1/admin/twilio", {
        method: "PUT",
        body: JSON.stringify({
          account_sid: formData.get("account_sid"),
          auth_token: formData.get("auth_token") || null,
          mode: formData.get("mode"),
        }),
      });
      setSetting(result.data);
      setMessage("Credentials saved securely. Verify them before enabling delivery.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to save Twilio credentials.");
    } finally { setBusy(false); }
  }

  async function verify() {
    setBusy(true); setMessage("");
    try {
      const result = await api<{ data: { configuration: TwilioSetting; account: { name?: string; account_status?: string } } }>("/api/v1/admin/twilio/verify", { method: "POST", body: "{}" });
      setSetting(result.data.configuration);
      setMessage(`Twilio verified${result.data.account.name ? ` for ${result.data.account.name}` : ""}.`);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Twilio verification failed.");
      await load().catch(() => undefined);
    } finally { setBusy(false); }
  }

  return <div className="mx-auto max-w-6xl">
    <header className="border-b border-ink/8 pb-6">
      <p className="eyebrow">Configuration · Messaging provider</p>
      <h1 className="page-title">Twilio platform connection</h1>
      <p className="page-intro">Connect the ReviewOrbit-owned Twilio account once. Credentials are encrypted and never shown again; each customer workspace then uses its own Messaging Service.</p>
    </header>

    {message && <p role="status" className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest">{message}</p>}

    <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
      <form key={setting?.account_sid ?? "new"} action={save} className="panel space-y-5">
        <div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-semibold">Platform credentials</h2><p className="mt-1 text-xs text-ink/45">Only a super administrator can change these values.</p></div><span className="pill capitalize">{setting?.status.replaceAll("_", " ") ?? "Loading"}</span></div>
        <label className="label">Account SID
          <input className="field font-mono" name="account_sid" required pattern="AC[a-fA-F0-9]{32}" defaultValue={setting?.account_sid} placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />
          <span className="mt-2 block text-xs font-normal text-ink/45">For Trial testing, use the Account SID shown on the Twilio Console home page.</span>
        </label>
        <label className="label">Auth Token
          <input className="field font-mono" name="auth_token" type="password" autoComplete="new-password" placeholder={setting?.auth_token_configured ? "Saved securely — leave blank to keep it" : "Enter the Twilio Auth Token"} />
          <span className="mt-2 block text-xs font-normal text-ink/45">ReviewOrbit requires the Auth Token for signed webhooks and parent-account access. It is encrypted at rest and never returned to the browser.</span>
        </label>
        <label className="label">Account mode
          <select className="field" name="mode" defaultValue={setting?.mode ?? "trial"}><option value="trial">Trial testing</option><option value="production">Production</option></select>
        </label>
        <div className="flex flex-wrap gap-3"><button className="button-primary" disabled={busy}>{busy ? "Saving…" : "Save credentials"}</button><button type="button" className="rounded-lg border border-ink/10 px-4 py-2.5 text-sm font-semibold disabled:opacity-50" disabled={busy || !setting?.configured} onClick={verify}>Verify connection</button></div>
      </form>

      <aside className="space-y-5">
        <section className="rounded-xl bg-[#17231f] p-5 text-white"><p className="eyebrow text-mint">Trial checklist</p><ol className="mt-4 space-y-3 text-xs leading-5 text-white/70"><li>1. Copy Account SID and Auth Token from Twilio Console.</li><li>2. Verify the platform connection here.</li><li>3. Create a Messaging Service in the same Trial account.</li><li>4. Add the Trial sender to its sender pool.</li><li>5. Verify the recipient number in Twilio.</li><li>6. Configure the customer workspace and send from Message Templates.</li></ol><a className="mt-5 inline-block text-xs font-semibold text-mint underline" href="https://console.twilio.com/" target="_blank" rel="noreferrer">Open Twilio Console ↗</a></section>
        <section className="panel"><p className="eyebrow">Webhook URLs</p><p className="mt-3 text-xs text-ink/45">Delivery status</p><code className="mt-1 block break-all text-[11px]">{setting?.status_callback_url}</code><p className="mt-4 text-xs text-ink/45">Incoming messages and opt-outs</p><code className="mt-1 block break-all text-[11px]">{setting?.inbound_webhook_url}</code></section>
        {setting?.last_error && <section className="rounded-xl bg-red-50 p-4 text-xs leading-5 text-red-800"><strong className="block">Last verification error</strong><span className="mt-1 block">{setting.last_error}</span></section>}
      </aside>
    </div>
  </div>;
}
