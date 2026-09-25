"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";

type SyncSummary = {
  total: number;
  past: number;
  upcoming: number;
  missing_contact: number;
  locations: number;
};

type Connection = {
  id: string;
  provider: string;
  name: string;
  environment: "sandbox" | "production";
  status: string;
  external_merchant_id?: string;
  connected_at?: string;
  last_synced_at?: string;
  sync_error?: string;
  sync_summary?: SyncSummary;
};

type ToastLocation = { id: string; name: string };
type ToastRequest = { id: string; location: ToastLocation; connection_code: string; expires_at: string };
type ToastRestaurant = { id: string; status: string; restaurant_name?: string; last_synced_at?: string; sync_error?: string; location: ToastLocation; sync_summary?: { orders?: number; created?: number; missing_contact?: number } };
type ToastState = { ready: boolean; environment: "sandbox" | "production"; marketplace_url?: string; requests: ToastRequest[]; connections: ToastRestaurant[] };

type Provider = {
  id: "generic" | "square" | "toast" | "manual";
  name: string;
  availability: string;
  description: string;
  configured?: boolean;
};

type Appointment = {
  id: string;
  status: string;
  starts_at: string;
  ends_at?: string;
  customer?: { first_name: string; last_name?: string; email?: string; phone_e164?: string };
  location?: { name: string; timezone: string };
};

type Credentials = { api_key: string; key_prefix: string; hmac_secret: string };
type BusinessLocationsResponse = { locations?: ToastLocation[]; business?: { locations?: ToastLocation[] } };

export function PosSetup({ onChanged }: { onChanged?: () => void }) {
  const [connections, setConnections] = useState<Connection[]>([]);
  const [providers, setProviders] = useState<Provider[]>([]);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [appointmentTotal, setAppointmentTotal] = useState(0);
  const [environment, setEnvironment] = useState<"sandbox" | "production">("sandbox");
  const [credentials, setCredentials] = useState<Credentials | null>(null);
  const [message, setMessage] = useState(() => {
    if (typeof window === "undefined") return "";
    return new URLSearchParams(window.location.search).get("message") ?? "";
  });
  const [busy, setBusy] = useState<string | null>(null);
  const [toast, setToast] = useState<ToastState | null>(null);
  const [locations, setLocations] = useState<ToastLocation[]>([]);
  const [toastLocationId, setToastLocationId] = useState("");

  const squareConnection = useMemo(
    () => connections.find((connection) => connection.provider === "square" && connection.status !== "disconnected"),
    [connections],
  );
  const squareProvider = useMemo(() => providers.find((provider) => provider.id === "square"), [providers]);
  const toastProvider = useMemo(() => providers.find((provider) => provider.id === "toast"), [providers]);

  async function load() {
    const result = await api<{ data: { connections: Connection[]; providers: Provider[] } }>("/api/v1/pos-integrations", {}, true);
    setConnections(result.data.connections);
    setProviders(result.data.providers);
    const [toastResult, businessResult] = await Promise.all([
      api<{ data: ToastState }>("/api/v1/toast/connections", {}, true),
      api<{ data: BusinessLocationsResponse }>("/api/v1/business", {}, true),
    ]);
    setToast(Array.isArray(toastResult.data.requests) ? toastResult.data : { ready: false, environment: "sandbox", requests: [], connections: [] });
    const locationList = businessResult.data.locations ?? businessResult.data.business?.locations ?? [];
    setLocations(locationList);
    if (!toastLocationId && locationList[0]) setToastLocationId(locationList[0].id);
    const square = result.data.connections.find((connection) => connection.provider === "square" && connection.status === "connected");
    if (square) {
      const synced = await api<{ data: Appointment[]; meta: { total: number } }>("/api/v1/square/appointments?timing=upcoming", {}, true);
      setAppointments(synced.data.slice(0, 5));
      setAppointmentTotal(synced.meta.total);
    } else {
      setAppointments([]);
      setAppointmentTotal(0);
    }
  }

  useEffect(() => {
    api<{ data: { connections: Connection[]; providers: Provider[] } }>("/api/v1/pos-integrations", {}, true)
      .then(async (result) => {
        setConnections(result.data.connections);
        setProviders(result.data.providers);
        const [toastResult, businessResult] = await Promise.all([
          api<{ data: ToastState }>("/api/v1/toast/connections", {}, true),
          api<{ data: BusinessLocationsResponse }>("/api/v1/business", {}, true),
        ]);
        setToast(Array.isArray(toastResult.data.requests) ? toastResult.data : { ready: false, environment: "sandbox", requests: [], connections: [] });
        const locationList = businessResult.data.locations ?? businessResult.data.business?.locations ?? [];
        setLocations(locationList);
        if (locationList[0]) setToastLocationId(locationList[0].id);
        const square = result.data.connections.find((connection) => connection.provider === "square" && connection.status === "connected");
        if (!square) return;
        const synced = await api<{ data: Appointment[]; meta: { total: number } }>("/api/v1/square/appointments?timing=upcoming", {}, true);
        setAppointments(synced.data.slice(0, 5));
        setAppointmentTotal(synced.meta.total);
      })
      .catch((error) => setMessage(error instanceof Error ? error.message : "Unable to load integrations."));
  }, []);

  async function connect(provider: Provider) {
    setBusy(provider.id);
    setMessage("");
    setCredentials(null);
    try {
      if (provider.id === "square") {
        const result = await api<{ data: { authorization_url: string } }>(
          "/api/v1/pos-integrations/square/authorize",
          { method: "POST", body: JSON.stringify({ environment }) },
          true,
        );
        window.location.assign(result.data.authorization_url);
        return;
      }

      await api(
        "/api/v1/pos-integrations",
        {
          method: "POST",
          body: JSON.stringify({
            provider: provider.id,
            name: provider.id === "generic" ? "Primary POS API" : "Dashboard visits",
            environment: "sandbox",
          }),
        },
        true,
      );
      if (provider.id === "generic") {
        const result = await api<{ data: Credentials }>(
          "/api/v1/integration-keys",
          { method: "POST", body: JSON.stringify({ name: "Primary POS API" }) },
          true,
        );
        setCredentials(result.data);
        setMessage("Generic POS connection created. Copy these credentials now—they are shown once.");
      } else {
        setMessage("Manual visit mode is ready.");
      }
      await load();
      onChanged?.();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to configure integration.");
    } finally {
      setBusy(null);
    }
  }

  async function createToastCode() {
    if (!toastLocationId) return;
    setBusy("toast"); setMessage("");
    try {
      const result = await api<{ data: { connection_code: string; marketplace_url: string } }>("/api/v1/pos-integrations/toast/connect", { method: "POST", body: JSON.stringify({ location_id: toastLocationId }) }, true);
      await navigator.clipboard?.writeText(result.data.connection_code).catch(() => undefined);
      setMessage("Toast location code created and copied. Open Toast, add B Reviews, choose the restaurant location, and paste this code as the Location ID.");
      await load(); onChanged?.();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to create the Toast location code."); }
    finally { setBusy(null); }
  }

  async function syncToast(connection: ToastRestaurant) {
    setBusy(`toast-sync-${connection.id}`); setMessage("");
    try {
      const result = await api<{ data: { summary: { orders: number; created: number } } }>(`/api/v1/pos-integrations/toast/connections/${connection.id}/sync`, { method: "POST", body: "{}" }, true);
      setMessage(`Toast sync complete: ${result.data.summary.orders} orders checked and ${result.data.summary.created} new visits imported.`);
      await load(); onChanged?.();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Toast sync failed."); }
    finally { setBusy(null); }
  }

  async function syncSquare() {
    if (!squareConnection) return;
    setBusy("sync");
    setMessage("");
    try {
      const result = await api<{ data: { summary: SyncSummary } }>(
        `/api/v1/pos-integrations/${squareConnection.id}/square/sync`,
        { method: "POST", body: "{}" },
        true,
      );
      setMessage(`Square sync complete: ${result.data.summary.past} previous and ${result.data.summary.upcoming} upcoming appointments imported.`);
      await load();
      onChanged?.();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Square sync failed.");
    } finally {
      setBusy(null);
    }
  }

  async function disconnectSquare() {
    if (!squareConnection || !window.confirm("Disconnect Square? Imported appointment history will be retained.")) return;
    setBusy("disconnect");
    try {
      await api(`/api/v1/pos-integrations/${squareConnection.id}/square`, { method: "DELETE" }, true);
      setMessage("Square has been disconnected. Imported records were retained.");
      await load();
      onChanged?.();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to disconnect Square.");
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-6">
      {message && <p role="status" className="rounded-xl border border-forest/15 bg-white px-5 py-4 text-sm text-forest">{message}</p>}

      <section className="overflow-hidden rounded-2xl border border-ink/10 bg-white">
        <div className="grid lg:grid-cols-[1.2fr_.8fr]">
          <div className="p-6 lg:p-7">
            <div className="flex flex-wrap items-center gap-3">
              <span className="grid size-11 place-items-center rounded-xl bg-[#111827] text-lg font-bold text-white">S</span>
              <div>
              <div className="flex items-center gap-2"><h2 className="text-xl font-semibold">Square Appointments</h2><span className="pill capitalize">{squareProvider?.availability ?? "Loading"}</span></div>
                <p className="mt-1 text-sm text-ink/50">Secure OAuth connection · read-only appointment access</p>
              </div>
            </div>
            <p className="mt-5 max-w-2xl text-sm leading-6 text-ink/60">Import Square locations, customer contact profiles, previous appointments, and upcoming appointments. B Reviews never treats a scheduled appointment as a completed visit or messaging consent.</p>

            {squareConnection?.status === "connected" ? (
              <div className="mt-6 flex flex-wrap gap-3">
                <button className="button-primary" disabled={busy !== null} onClick={syncSquare}>{busy === "sync" ? "Syncing appointments…" : "Sync appointments now"}</button>
                <button className="button-secondary" disabled={busy !== null} onClick={disconnectSquare}>{busy === "disconnect" ? "Disconnecting…" : "Disconnect Square"}</button>
              </div>
            ) : (
              <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end">
                <label className="label max-w-56">Square environment<select className="field" value={environment} onChange={(event) => setEnvironment(event.target.value as "sandbox" | "production")}><option value="sandbox">Sandbox testing</option><option value="production">Production account</option></select></label>
                <button className="button-primary sm:mb-px" disabled={busy !== null || squareProvider?.configured === false} onClick={() => squareProvider && connect(squareProvider)}>{busy === "square" ? "Opening Square…" : squareProvider?.configured === false ? "Square app setup required" : "Connect Square account"}</button>
              </div>
            )}
            {squareProvider?.configured === false && <p className="mt-3 text-xs leading-5 text-amber-700">A platform administrator must add the Square application ID, secret, and callback URL before store owners can connect.</p>}
          </div>
          <div className="border-t border-ink/10 bg-paper/70 p-6 lg:border-l lg:border-t-0">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-ink/40">Connection status</p>
            <dl className="mt-4 space-y-3 text-sm">
              <StatusRow label="Status" value={squareConnection?.status.replaceAll("_", " ") ?? "Not connected"} />
              <StatusRow label="Environment" value={squareConnection?.environment ?? environment} />
              <StatusRow label="Last sync" value={squareConnection?.last_synced_at ? new Date(squareConnection.last_synced_at).toLocaleString() : "Not synced yet"} />
              <StatusRow label="Appointments" value={String(squareConnection?.sync_summary?.total ?? 0)} />
              <StatusRow label="Locations" value={String(squareConnection?.sync_summary?.locations ?? 0)} />
            </dl>
            {squareConnection?.sync_error && <p className="mt-4 rounded-xl bg-red-50 p-3 text-xs leading-5 text-red-700">{squareConnection.sync_error}</p>}
          </div>
        </div>
      </section>

      <section className="overflow-hidden rounded-2xl border border-ink/10 bg-white">
        <div className="grid lg:grid-cols-[1.2fr_.8fr]">
          <div className="p-6 lg:p-7"><div className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-xl bg-[#f05a28] text-lg font-bold text-white">T</span><div><div className="flex items-center gap-2"><h2 className="text-xl font-semibold">Toast POS</h2><span className="pill capitalize">{toastProvider?.availability ?? "Loading"}</span></div><p className="mt-1 text-sm text-ink/50">Partner connection · one code per restaurant location</p></div></div>
            <p className="mt-5 max-w-2xl text-sm leading-6 text-ink/60">Toast connections are location-based. B Reviews imports completed paid checks as visits. Guest contact information is used only to match the customer and never counts as SMS consent.</p>
            <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end"><label className="label flex-1">B Reviews location<select className="field" value={toastLocationId} onChange={(event) => setToastLocationId(event.target.value)}><option value="">Select a location</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label><button className="button-primary sm:mb-px" disabled={busy !== null || !toast?.ready || !toastLocationId} onClick={createToastCode}>{busy === "toast" ? "Creating code…" : "Create Toast location code"}</button></div>
            {!toast?.ready && <p className="mt-3 text-xs leading-5 text-amber-700">Toast partner access is not ready yet. A platform administrator must add and verify the Toast credentials and webhook secrets.</p>}
          </div>
          <div className="border-t border-ink/10 bg-paper/70 p-6 lg:border-l lg:border-t-0"><p className="text-xs font-bold uppercase tracking-[0.16em] text-ink/40">How to connect</p><ol className="mt-4 space-y-3 text-sm leading-6 text-ink/60"><li>1. Choose the matching B Reviews location.</li><li>2. Create and copy its secure location code.</li><li>3. Open B Reviews in Toast My Integrations.</li><li>4. Select the Toast restaurant and paste the code into Location ID.</li><li>5. Return here; Toast confirms automatically by webhook.</li></ol>{toast?.marketplace_url && <a className="button-secondary mt-5 inline-block" href={toast.marketplace_url} target="_blank" rel="noreferrer">Open Toast integration ↗</a>}</div>
        </div>
        {!!toast?.requests.length && <div className="border-t border-ink/10 px-6 py-5"><h3 className="font-semibold">Waiting for Toast authorization</h3><div className="mt-3 grid gap-3 md:grid-cols-2">{toast.requests.map((item) => <div key={item.id} className="rounded-xl border border-ink/10 p-4"><p className="text-sm font-semibold">{item.location.name}</p><button className="mt-2 font-mono text-sm text-forest underline" onClick={() => void navigator.clipboard?.writeText(item.connection_code)}>{item.connection_code} · Copy</button><p className="mt-2 text-xs text-ink/45">Expires {new Date(item.expires_at).toLocaleDateString()}</p></div>)}</div></div>}
        {!!toast?.connections.length && <div className="border-t border-ink/10 px-6 py-5"><h3 className="font-semibold">Toast locations</h3><div className="mt-3 divide-y divide-ink/10">{toast.connections.map((connection) => <div key={connection.id} className="flex flex-col justify-between gap-3 py-4 sm:flex-row sm:items-center"><div><p className="font-semibold">{connection.location.name}</p><p className="mt-1 text-xs text-ink/45">{connection.restaurant_name ?? "Toast restaurant"} · {connection.status.replaceAll("_", " ")} · {connection.last_synced_at ? `Synced ${new Date(connection.last_synced_at).toLocaleString()}` : "Initial sync pending"}</p>{connection.sync_error && <p className="mt-2 text-xs text-red-700">{connection.sync_error}</p>}</div><button className="button-secondary" disabled={busy !== null || connection.status !== "connected"} onClick={() => syncToast(connection)}>{busy === `toast-sync-${connection.id}` ? "Syncing…" : "Sync orders"}</button></div>)}</div></div>}
      </section>

      {squareConnection?.status === "connected" && (
        <section className="overflow-hidden rounded-2xl border border-ink/10 bg-white">
          <div className="flex items-center justify-between border-b border-ink/10 px-5 py-4"><div><h3 className="font-semibold">Upcoming Square appointments</h3><p className="mt-1 text-xs text-ink/45">{appointmentTotal} upcoming appointments in the synchronized range</p></div><span className="pill">Read only</span></div>
          {appointments.length ? <div className="divide-y divide-ink/10">{appointments.map((appointment) => <div key={appointment.id} className="grid gap-3 px-5 py-4 text-sm sm:grid-cols-[1.1fr_1fr_auto] sm:items-center"><div><p className="font-semibold">{appointment.customer ? `${appointment.customer.first_name} ${appointment.customer.last_name ?? ""}`.trim() : "Customer unavailable"}</p><p className="mt-1 text-xs text-ink/45">{appointment.customer?.phone_e164 ?? appointment.customer?.email ?? "No contact information"}</p></div><div><p>{new Date(appointment.starts_at).toLocaleString()}</p><p className="mt-1 text-xs text-ink/45">{appointment.location?.name ?? "Unmapped Square location"}</p></div><span className="pill">{appointment.status.replaceAll("_", " ")}</span></div>)}</div> : <p className="px-5 py-7 text-sm text-ink/50">No upcoming appointments are stored yet. Run the first sync after connecting.</p>}
        </section>
      )}

      <section className="grid gap-4 md:grid-cols-2">
        {providers.filter((provider) => provider.id !== "square" && provider.id !== "toast").map((provider) => {
          const exists = connections.some((connection) => connection.provider === provider.id && connection.status !== "disconnected");
          return <article key={provider.id} className="rounded-2xl border border-ink/10 bg-white p-5"><div className="flex items-center justify-between gap-3"><h3 className="font-semibold">{provider.name}</h3><span className="pill">{provider.availability}</span></div><p className="mt-3 min-h-16 text-sm leading-6 text-ink/55">{provider.description}</p><button className="button-secondary mt-4 w-full" disabled={busy !== null || exists} onClick={() => connect(provider)}>{exists ? "Configured" : busy === provider.id ? "Saving…" : provider.id === "manual" ? "Use manual mode" : "Connect API"}</button></article>;
        })}
      </section>

      {credentials && <section className="rounded-2xl bg-ink p-5 text-white"><p className="font-semibold text-[#ffb0b6]">Copy credentials now</p><p className="mt-1 text-xs text-white/55">Only hashes and encrypted secrets are stored by B Reviews.</p><Credential label="API key" value={credentials.api_key}/><Credential label="Webhook key prefix" value={credentials.key_prefix}/><Credential label="HMAC secret" value={credentials.hmac_secret}/></section>}
    </div>
  );
}

function StatusRow({ label, value }: { label: string; value: string }) {
  return <div className="flex items-center justify-between gap-4"><dt className="text-ink/45">{label}</dt><dd className="max-w-48 truncate text-right font-medium capitalize">{value}</dd></div>;
}

function Credential({ label, value }: { label: string; value: string }) {
  return <div className="mt-4"><p className="text-[11px] uppercase tracking-wider text-white/45">{label}</p><code className="mt-1 block break-all rounded-xl bg-white/10 p-3 text-xs">{value}</code></div>;
}
