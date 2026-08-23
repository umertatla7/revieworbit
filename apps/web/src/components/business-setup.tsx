"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Location = { id: string; name: string; timezone: string; google_review_url?: string };
type Business = { id: string; name: string; default_timezone: string; locations: Location[] };

export function BusinessSetup({ onboarding = false }: { onboarding?: boolean }) {
  const router = useRouter();
  const [business, setBusiness] = useState<Business | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => { api<{ data: Business }>("/api/v1/business", {}, true).then(({ data }) => setBusiness(data)).catch((error) => setMessage(error.message)); }, []);

  async function addLocation(formData: FormData) {
    setBusy(true); setMessage("");
    try {
      await api("/api/v1/locations", { method: "POST", body: JSON.stringify({ name: formData.get("name"), timezone: formData.get("timezone"), google_review_url: formData.get("google_review_url") || null }) }, true);
      setMessage("Location saved.");
      if (onboarding) router.push("/dashboard");
      else {
        const result = await api<{ data: Business }>("/api/v1/business", {}, true);
        setBusiness(result.data);
      }
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to save location."); }
    finally { setBusy(false); }
  }

  return <div className="mx-auto max-w-5xl">
    <p className="eyebrow">{onboarding ? "Workspace created" : "Business setup"}</p>
    <h1 className="page-title">{onboarding ? "Add your first location" : business?.name ?? "Business settings"}</h1>
    <p className="page-intro">The saved HTTPS review destination is used later by opaque tracking links. It cannot be supplied by a click request.</p>
    <div className="mt-10 grid gap-6 lg:grid-cols-[1fr_0.8fr]">
      <form className="panel space-y-5" action={addLocation}>
        <h2 className="text-xl font-semibold">Location details</h2>
        <label className="label">Location name<input name="name" className="field" required placeholder="Main Street Location" /></label>
        <label className="label">Time zone<input name="timezone" className="field" required defaultValue={Intl.DateTimeFormat().resolvedOptions().timeZone} /></label>
        <label className="label">Google review URL<input name="google_review_url" className="field" required type="url" placeholder="https://g.page/r/.../review" /></label>
        {message && <p role="status" className="text-sm text-forest">{message}</p>}
        <button className="button-primary" disabled={busy}>{busy ? "Saving…" : onboarding ? "Save and open dashboard" : "Add location"}</button>
      </form>
      <section className="panel"><p className="eyebrow">Current locations</p><div className="mt-5 space-y-3">{business?.locations.length ? business.locations.map((location) => <div className="rounded-2xl border border-ink/10 p-4" key={location.id}><p className="font-semibold">{location.name}</p><p className="mt-1 text-sm text-ink/55">{location.timezone}</p></div>) : <p className="text-sm text-ink/55">No location has been added yet.</p>}</div></section>
    </div>
  </div>;
}
