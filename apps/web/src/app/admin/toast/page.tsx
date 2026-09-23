"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { PasswordInput } from "@/components/password-input";

type ToastSetting = {
  configured: boolean; ready_for_connections: boolean; environment: "sandbox" | "production";
  api_base_url?: string; client_id?: string; client_secret_configured: boolean;
  partner_webhook_secret_configured: boolean; orders_webhook_secret_configured: boolean;
  marketplace_url?: string; status: string; verified_at?: string; last_error?: string;
  partner_webhook_url: string; orders_webhook_url: string;
  counts: { connected_locations: number; pending_requests: number; failed_events: number };
  recent_events: { id: string; category: string; event_type: string; status: string; failure_message?: string }[];
  connections: { id: string; restaurant_name?: string; restaurant_guid: string; status: string; last_synced_at?: string; sync_error?: string; business: { id: string; name: string }; location: { id: string; name: string } }[];
};

export default function AdminToastPage() {
  const [environment, setEnvironment] = useState<"sandbox" | "production">("sandbox");
  const [setting, setSetting] = useState<ToastSetting | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  async function load(env = environment) {
    const result = await api<{ data: ToastSetting }>(`/api/v1/admin/toast?environment=${env}`);
    setSetting(result.data);
  }
  useEffect(() => {
    void api<{ data: ToastSetting }>("/api/v1/admin/toast?environment=sandbox")
      .then((result) => setSetting(result.data))
      .catch((error: Error) => setMessage(error.message));
  }, []);
  async function switchEnvironment(value: "sandbox" | "production") {
    setEnvironment(value); setMessage("");
    await load(value).catch((error: Error) => setMessage(error.message));
  }
  async function save(formData: FormData) {
    setBusy(true); setMessage("");
    try {
      const result = await api<{ data: ToastSetting }>("/api/v1/admin/toast", { method: "PUT", body: JSON.stringify({
        environment, api_base_url: formData.get("api_base_url"), client_id: formData.get("client_id"),
        client_secret: formData.get("client_secret") || null, partner_webhook_secret: formData.get("partner_webhook_secret") || null,
        orders_webhook_secret: formData.get("orders_webhook_secret") || null, marketplace_url: formData.get("marketplace_url") || null,
      }) });
      setSetting(result.data); setMessage("Toast partner settings saved securely. Verify the API credentials next.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to save Toast settings."); }
    finally { setBusy(false); }
  }
  async function verify() {
    setBusy(true); setMessage("");
    try {
      const result = await api<{ data: { configuration: ToastSetting } }>("/api/v1/admin/toast/verify", { method: "POST", body: JSON.stringify({ environment }) });
      setSetting(result.data.configuration); setMessage("Toast machine-client credentials verified successfully.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Toast verification failed."); await load().catch(() => undefined); }
    finally { setBusy(false); }
  }
  return <div className="mx-auto max-w-7xl">
    <header className="border-b border-ink/8 pb-6"><p className="eyebrow">Configuration · POS partner</p><h1 className="page-title">Toast partner connection</h1><p className="page-intro">Configure Breviews once as a Toast integration partner. Store owners connect individual restaurant locations with a one-time location code.</p></header>
    {message && <p role="status" className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest">{message}</p>}
    <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
      <form key={`${environment}-${setting?.status}`} action={save} className="panel space-y-5">
        <div className="flex items-center justify-between gap-4"><div><h2 className="text-xl font-semibold">Partner credentials</h2><p className="mt-1 text-xs text-ink/45">Credentials and webhook signing secrets come from Toast Partner Engineering.</p></div><span className="pill capitalize">{setting?.status.replaceAll("_", " ") ?? "Loading"}</span></div>
        <label className="label">Environment<select className="field" value={environment} onChange={(event) => void switchEnvironment(event.target.value as "sandbox" | "production")}><option value="sandbox">Sandbox</option><option value="production">Production</option></select></label>
        <label className="label">Toast API base URL<input className="field font-mono" name="api_base_url" type="url" required defaultValue={setting?.api_base_url} placeholder="Provided by Toast for this environment" /></label>
        <label className="label">Client ID<input className="field font-mono" name="client_id" required defaultValue={setting?.client_id} /></label>
        <Secret name="client_secret" label="Client secret" configured={setting?.client_secret_configured} />
        <div className="grid gap-4 md:grid-cols-2"><Secret name="partner_webhook_secret" label="Partner webhook secret" configured={setting?.partner_webhook_secret_configured} /><Secret name="orders_webhook_secret" label="Orders webhook secret" configured={setting?.orders_webhook_secret_configured} /></div>
        <label className="label">Toast marketplace listing URL<input className="field" name="marketplace_url" type="url" defaultValue={setting?.marketplace_url} placeholder="https://www.toasttab.com/integrations/..." /></label>
        <div className="flex flex-wrap gap-3"><button className="button-primary" disabled={busy}>{busy ? "Saving…" : "Save settings"}</button><button className="button-secondary" type="button" disabled={busy || !setting?.configured} onClick={verify}>Verify API credentials</button></div>
      </form>
      <aside className="space-y-5">
        <section className="rounded-xl bg-[#17231f] p-5 text-white"><p className="eyebrow text-mint">Readiness</p><p className="mt-3 text-lg font-semibold">{setting?.ready_for_connections ? "Ready for store connections" : "Setup incomplete"}</p><dl className="mt-5 space-y-3 text-sm"><Row label="Connected locations" value={String(setting?.counts.connected_locations ?? 0)} /><Row label="Pending codes" value={String(setting?.counts.pending_requests ?? 0)} /><Row label="Failed events" value={String(setting?.counts.failed_events ?? 0)} /></dl></section>
        <section className="panel"><p className="eyebrow">Register with Toast</p><Webhook label="Partner lifecycle" value={setting?.partner_webhook_url} /><Webhook label="Order updates" value={setting?.orders_webhook_url} /><p className="mt-4 text-xs leading-5 text-ink/50">Required scopes: orders:read, guest.pi:read, restaurants:read. Toast must approve and provision the partner account before stores can connect.</p></section>
        {setting?.last_error && <section className="rounded-xl bg-red-50 p-4 text-xs text-red-800"><strong>Last error</strong><p className="mt-1">{setting.last_error}</p></section>}
      </aside>
    </div>
    <section className="panel mt-6"><div className="flex items-center justify-between"><h2 className="text-xl font-semibold">Customer Toast locations</h2><span className="pill">{setting?.connections.length ?? 0} mappings</span></div>{setting?.connections.length ? <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-ink/10 text-xs uppercase tracking-wider text-ink/40"><tr><th className="py-3 pr-4">Customer</th><th className="py-3 pr-4">Breviews location</th><th className="py-3 pr-4">Toast restaurant</th><th className="py-3 pr-4">Status</th><th className="py-3">Last sync</th></tr></thead><tbody className="divide-y divide-ink/10">{setting.connections.map((connection) => <tr key={connection.id}><td className="py-4 pr-4 font-semibold">{connection.business.name}</td><td className="py-4 pr-4">{connection.location.name}</td><td className="py-4 pr-4">{connection.restaurant_name ?? connection.restaurant_guid}</td><td className="py-4 pr-4"><span className="pill capitalize">{connection.status}</span></td><td className="py-4">{connection.last_synced_at ? new Date(connection.last_synced_at).toLocaleString() : "Pending"}{connection.sync_error && <p className="mt-1 text-xs text-red-700">{connection.sync_error}</p>}</td></tr>)}</tbody></table></div> : <p className="mt-4 text-sm text-ink/45">No customer locations are connected in this environment.</p>}</section>
    <section className="panel mt-6"><div className="flex items-center justify-between"><h2 className="text-xl font-semibold">Recent verified webhook events</h2><span className="pill">No payload PII shown</span></div>{setting?.recent_events.length ? <div className="mt-4 divide-y divide-ink/10">{setting.recent_events.map((event) => <div key={event.id} className="grid gap-2 py-3 text-sm md:grid-cols-[120px_1fr_120px]"><span className="capitalize text-ink/45">{event.category}</span><span>{event.event_type.replaceAll("_", " ")}</span><span className="pill justify-self-start capitalize">{event.status}</span>{event.failure_message && <p className="md:col-span-3 text-xs text-red-700">{event.failure_message}</p>}</div>)}</div> : <p className="mt-4 text-sm text-ink/45">No Toast webhook events received yet.</p>}</section>
  </div>;
}

function Secret({ name, label, configured }: { name: string; label: string; configured?: boolean }) { return <label className="label">{label}<PasswordInput className="field font-mono" name={name} autoComplete="new-password" placeholder={configured ? "Saved securely — leave blank to keep it" : `Enter ${label.toLowerCase()}`} /></label>; }
function Row({ label, value }: { label: string; value: string }) { return <div className="flex justify-between"><dt className="text-white/55">{label}</dt><dd className="font-semibold">{value}</dd></div>; }
function Webhook({ label, value }: { label: string; value?: string }) { return <div className="mt-4"><p className="text-xs text-ink/45">{label}</p><code className="mt-1 block break-all text-[11px]">{value}</code></div>; }
