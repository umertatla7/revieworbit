"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";

type NotificationSettings = { registration_email: string };

export default function PlatformSettingsPage() {
  const [email, setEmail] = useState("zee@buckeyerank.com");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");

  useEffect(() => {
    void api<{ data: NotificationSettings }>("/api/v1/admin/notifications", {}, true)
      .then(({ data }) => setEmail(data.registration_email))
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setMessage("");
    try {
      const result = await api<{ data: NotificationSettings }>("/api/v1/admin/notifications", {
        method: "PUT",
        body: JSON.stringify({ registration_email: email }),
      }, true);
      setEmail(result.data.registration_email);
      setMessage("Registration notification email saved.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to save notification settings.");
    } finally {
      setBusy(false);
    }
  }

  return <div className="mx-auto max-w-5xl">
    <p className="eyebrow">Configuration</p>
    <h1 className="page-title">Platform settings</h1>
    <p className="page-intro">Choose where B Review sends operational notifications.</p>
    {message&&<p role="status" className="mt-5 rounded-xl border border-ink/8 bg-white px-4 py-3 text-sm">{message}</p>}
    <form className="mt-7 rounded-2xl border border-ink/8 bg-white p-6 shadow-sm" onSubmit={save}>
      <h2 className="text-xl font-semibold">Registration notifications</h2>
      <p className="mt-2 text-sm leading-6 text-ink/50">New registrations and custom plan requests are sent to this address.</p>
      <label className="label mt-6 max-w-xl">Notification email
        <input className="field" type="email" required value={email} onChange={(event)=>setEmail(event.target.value)} placeholder="zee@buckeyerank.com"/>
      </label>
      <button className="button-primary mt-5" disabled={busy}>{busy?"Saving…":"Save notification email"}</button>
    </form>
  </div>;
}
