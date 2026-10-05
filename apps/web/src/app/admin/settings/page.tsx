"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";

type NotificationSettings = { registration_email: string };
type MailSettings = {
  configured: boolean;
  host: string;
  port: number;
  encryption: "ssl" | "starttls";
  username: string | null;
  password_configured: boolean;
  from_address: string | null;
  from_name: string;
  enabled: boolean;
  status: "not_configured" | "draft" | "verified" | "error";
  verified_at: string | null;
  last_tested_at: string | null;
  last_error: string | null;
};

const initialMail: MailSettings = {
  configured: false,
  host: "smtp.hostinger.com",
  port: 465,
  encryption: "ssl",
  username: null,
  password_configured: false,
  from_address: null,
  from_name: "B Review",
  enabled: true,
  status: "not_configured",
  verified_at: null,
  last_tested_at: null,
  last_error: null,
};

export default function PlatformSettingsPage() {
  const [email, setEmail] = useState("zee@buckeyerank.com");
  const [mail, setMail] = useState<MailSettings>(initialMail);
  const [password, setPassword] = useState("");
  const [testRecipient, setTestRecipient] = useState("zee@buckeyerank.com");
  const [busy, setBusy] = useState<"notifications" | "mail" | "test" | "">("");
  const [message, setMessage] = useState("");
  const [tone, setTone] = useState<"success" | "error">("success");

  useEffect(() => {
    void Promise.all([
      api<{ data: NotificationSettings }>("/api/v1/admin/notifications", {}, true),
      api<{ data: MailSettings }>("/api/v1/admin/mail", {}, true),
    ]).then(([notifications, smtp]) => {
      setEmail(notifications.data.registration_email);
      setTestRecipient(notifications.data.registration_email);
      setMail(smtp.data);
    }).catch((error: Error) => showMessage(error.message, "error"));
  }, []);

  function showMessage(text: string, nextTone: "success" | "error" = "success") {
    setMessage(text);
    setTone(nextTone);
  }

  async function saveNotifications(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy("notifications");
    setMessage("");
    try {
      const result = await api<{ data: NotificationSettings }>("/api/v1/admin/notifications", {
        method: "PUT",
        body: JSON.stringify({ registration_email: email }),
      }, true);
      setEmail(result.data.registration_email);
      showMessage("Registration notification email saved.");
    } catch (error) {
      showMessage(error instanceof Error ? error.message : "Unable to save notification settings.", "error");
    } finally {
      setBusy("");
    }
  }

  async function saveMail(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy("mail");
    setMessage("");
    try {
      const result = await api<{ data: MailSettings }>("/api/v1/admin/mail", {
        method: "PUT",
        body: JSON.stringify({
          host: mail.host,
          port: mail.port,
          encryption: mail.encryption,
          username: mail.username,
          password: password || null,
          from_address: mail.from_address,
          from_name: mail.from_name,
          enabled: mail.enabled,
        }),
      }, true);
      setMail(result.data);
      setPassword("");
      showMessage("SMTP settings saved. Send a test email to verify the connection.");
    } catch (error) {
      showMessage(error instanceof Error ? error.message : "Unable to save SMTP settings.", "error");
    } finally {
      setBusy("");
    }
  }

  async function testMail(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy("test");
    setMessage("");
    try {
      const result = await api<{ message: string; data: MailSettings }>("/api/v1/admin/mail/test", {
        method: "POST",
        body: JSON.stringify({ recipient: testRecipient }),
      }, true);
      setMail(result.data);
      showMessage(result.message);
    } catch (error) {
      showMessage(error instanceof Error ? error.message : "SMTP test failed.", "error");
      void api<{ data: MailSettings }>("/api/v1/admin/mail", {}, true).then(({ data }) => setMail(data));
    } finally {
      setBusy("");
    }
  }

  return <div className="mx-auto max-w-6xl">
    <p className="eyebrow">Configuration</p>
    <h1 className="page-title">Platform settings</h1>
    <p className="page-intro">Control operational notifications and outgoing email delivery for B Review.</p>
    {message&&<p role="status" className={`mt-5 rounded-xl border px-4 py-3 text-sm ${tone === "error" ? "border-red-200 bg-red-50 text-red-700" : "border-ink/8 bg-white text-ink"}`}>{message}</p>}

    <div className="mt-7 grid gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(300px,.75fr)]">
      <form className="rounded-2xl border border-ink/8 bg-white p-6 shadow-sm" onSubmit={saveMail}>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="text-xl font-semibold">Outgoing email (SMTP)</h2>
            <p className="mt-2 text-sm leading-6 text-ink/50">Credentials are encrypted and the saved password is never returned to the browser.</p>
          </div>
          <Status value={mail.status}/>
        </div>

        <div className="mt-6 grid gap-5 md:grid-cols-2">
          <label className="label md:col-span-2">SMTP server
            <input className="field" required value={mail.host} onChange={(event)=>setMail({...mail, host:event.target.value})} placeholder="smtp.hostinger.com" autoComplete="off"/>
          </label>
          <label className="label">Port
            <input className="field" type="number" min={1} max={65535} required value={mail.port} onChange={(event)=>setMail({...mail, port:Number(event.target.value)})}/>
          </label>
          <label className="label">Encryption
            <select className="field" value={mail.encryption} onChange={(event)=>setMail({...mail, encryption:event.target.value as MailSettings["encryption"]})}>
              <option value="ssl">SSL/TLS (usually port 465)</option>
              <option value="starttls">STARTTLS (usually port 587)</option>
            </select>
          </label>
          <label className="label md:col-span-2">Username
            <input className="field" type="email" required value={mail.username ?? ""} onChange={(event)=>setMail({...mail, username:event.target.value})} placeholder="mailbox@example.com" autoComplete="username"/>
          </label>
          <label className="label md:col-span-2">Password
            <input className="field" type="password" required={!mail.password_configured} value={password} onChange={(event)=>setPassword(event.target.value)} placeholder={mail.password_configured ? "Saved securely — leave blank to keep it" : "Enter SMTP password"} autoComplete="new-password"/>
            <span className="mt-2 block text-xs font-normal text-ink/40">{mail.password_configured ? "A password is configured. Enter a new value only when rotating it." : "A password is required before SMTP can be tested."}</span>
          </label>
          <label className="label">From email
            <input className="field" type="email" required value={mail.from_address ?? ""} onChange={(event)=>setMail({...mail, from_address:event.target.value})} placeholder="mailbox@example.com"/>
          </label>
          <label className="label">From name
            <input className="field" required value={mail.from_name} onChange={(event)=>setMail({...mail, from_name:event.target.value})} placeholder="B Review"/>
          </label>
        </div>

        <label className="mt-5 flex items-start gap-3 rounded-xl border border-ink/10 p-4 text-sm">
          <input className="mt-1" type="checkbox" checked={mail.enabled} onChange={(event)=>setMail({...mail, enabled:event.target.checked})}/>
          <span><strong className="block">Use this SMTP connection</strong><span className="mt-1 block text-ink/50">When disabled, the server falls back to its environment mail configuration.</span></span>
        </label>
        <button className="button-primary mt-5" disabled={Boolean(busy)}>{busy === "mail" ? "Saving…" : "Save SMTP settings"}</button>
      </form>

      <div className="space-y-6">
        <form className="rounded-2xl border border-ink/8 bg-white p-6 shadow-sm" onSubmit={testMail}>
          <h2 className="text-xl font-semibold">Connection test</h2>
          <p className="mt-2 text-sm leading-6 text-ink/50">A real test email confirms DNS, encryption, authentication and delivery acceptance.</p>
          <label className="label mt-5">Send test to
            <input className="field" type="email" required value={testRecipient} onChange={(event)=>setTestRecipient(event.target.value)}/>
          </label>
          <button className="button-secondary mt-5" disabled={Boolean(busy) || !mail.configured}>{busy === "test" ? "Testing…" : "Send test email"}</button>
          <dl className="mt-6 space-y-3 border-t border-ink/8 pt-5 text-sm">
            <Row label="Last tested" value={formatDate(mail.last_tested_at)}/>
            <Row label="Verified" value={formatDate(mail.verified_at)}/>
          </dl>
          {mail.last_error&&<p className="mt-4 rounded-xl bg-red-50 p-3 text-sm leading-6 text-red-700">{mail.last_error}</p>}
        </form>

        <form className="rounded-2xl border border-ink/8 bg-white p-6 shadow-sm" onSubmit={saveNotifications}>
          <h2 className="text-xl font-semibold">Registration notifications</h2>
          <p className="mt-2 text-sm leading-6 text-ink/50">New registrations and custom plan requests are sent to this address.</p>
          <label className="label mt-5">Notification email
            <input className="field" type="email" required value={email} onChange={(event)=>setEmail(event.target.value)} placeholder="notifications@example.com"/>
          </label>
          <button className="button-secondary mt-5" disabled={Boolean(busy)}>{busy === "notifications" ? "Saving…" : "Save notification email"}</button>
        </form>
      </div>
    </div>
  </div>;
}

function Status({value}:{value:MailSettings["status"]}) {
  const label = value === "verified" ? "Verified" : value === "error" ? "Needs attention" : value === "draft" ? "Test required" : "Not configured";
  const style = value === "verified" ? "bg-emerald-50 text-emerald-700" : value === "error" ? "bg-red-50 text-red-700" : "bg-amber-50 text-amber-700";
  return <span className={`rounded-full px-3 py-1 text-xs font-semibold ${style}`}>{label}</span>;
}

function Row({label,value}:{label:string;value:string}) {
  return <div className="flex items-center justify-between gap-4"><dt className="text-ink/45">{label}</dt><dd className="text-right font-medium">{value}</dd></div>;
}

function formatDate(value:string|null) {
  if (!value) return "Not yet";
  return new Intl.DateTimeFormat(undefined, {dateStyle:"medium", timeStyle:"short"}).format(new Date(value));
}
