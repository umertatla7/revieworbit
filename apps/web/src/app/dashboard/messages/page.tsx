"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ApiError, api } from "@/lib/api";

type Configuration = {
  status: string;
  twilio_subaccount_sid?: string;
  twilio_messaging_service_sid?: string;
  sms_sender?: string;
  sms_enabled: boolean;
  verified_at?: string;
  last_health_check_at?: string;
  last_error?: string;
};
type Activity = {
  id: string;
  action: string;
  changes?: { reason?: string };
  created_at: string;
};
type Platform = {
  provider: string;
  configured: boolean;
  recommended_architecture: string;
  status_callback_url: string;
  inbound_webhook_url: string;
};
type Delivery = {
  id: string;
  channel: string;
  status: string;
  to_last_four: string;
  created_at: string;
  provider_error_code?: string;
  failure_message?: string;
  is_test: boolean;
  customer?: { first_name: string; last_name?: string };
  template?: { name: string };
};

export default function MessagesPage() {
  const [configuration, setConfiguration] = useState<Configuration | null>(
    null,
  );
  const [platform, setPlatform] = useState<Platform | null>(null);
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);
  const [activity, setActivity] = useState<Activity[]>([]);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [subaccountSid, setSubaccountSid] = useState("");
  const [serviceSid, setServiceSid] = useState("");
  const [smsSender, setSmsSender] = useState("");
  const [smsEnabled, setSmsEnabled] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  function applyConfiguration(next: Configuration | null) {
    setConfiguration(next);
    if (!next) return;
    setSubaccountSid(next.twilio_subaccount_sid ?? "");
    setServiceSid(next.twilio_messaging_service_sid ?? "");
    setSmsSender(next.sms_sender ?? "");
    setSmsEnabled(next.sms_enabled);
  }

  async function load() {
    const [settings, history] = await Promise.all([
      api<{
        data: {
          configuration: Configuration | null;
          platform: Platform;
          activity: Activity[];
        };
      }>("/api/v1/messaging-configuration", {}, true),
      api<{ data: Delivery[] }>("/api/v1/message-deliveries", {}, true),
    ]);
    applyConfiguration(settings.data.configuration);
    setPlatform(settings.data.platform);
    setActivity(settings.data.activity ?? []);
    setDeliveries(history.data);
  }

  useEffect(() => {
    void Promise.all([
      api<{
        data: {
          configuration: Configuration | null;
          platform: Platform;
          activity: Activity[];
        };
      }>("/api/v1/messaging-configuration", {}, true),
      api<{ data: Delivery[] }>("/api/v1/message-deliveries", {}, true),
    ])
      .then(([settings, history]) => {
        applyConfiguration(settings.data.configuration);
        setPlatform(settings.data.platform);
        setActivity(settings.data.activity ?? []);
        setDeliveries(history.data);
      })
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save() {
    setBusy(true);
    setMessage("");
    setFieldErrors({});
    try {
      await api(
        "/api/v1/messaging-configuration",
        {
          method: "PUT",
          body: JSON.stringify({
            twilio_subaccount_sid: subaccountSid.trim(),
            twilio_messaging_service_sid: serviceSid.trim(),
            sms_sender: smsSender.trim() || null,
            sms_enabled: smsEnabled,
            whatsapp_enabled: false,
          }),
        },
        true,
      );
      setMessage("Messaging configuration saved. Verify it before sending.");
      await load();
    } catch (error) {
      if (error instanceof ApiError) setFieldErrors(error.errors);
      setMessage(
        error instanceof Error
          ? error.message
          : "Unable to save messaging configuration.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function verify() {
    setBusy(true);
    setMessage("");
    try {
      await api(
        "/api/v1/messaging-configuration/verify",
        { method: "POST", body: "{}" },
        true,
      );
      setMessage("Twilio configuration verified and activated.");
      await load();
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Twilio verification failed.",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto max-w-6xl">
      <p className="eyebrow">Connections</p>
      <h1 className="page-title">Twilio / SMS connection</h1>
      <p className="page-intro">
        Connect the approved Twilio sender used for review-request text
        messages.
      </p>
      {message && (
        <p
          role="status"
          className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest"
        >
          {message}
        </p>
      )}
      <div className="mt-7 grid gap-6 lg:grid-cols-[1.2fr_.8fr]">
        <form action={save} className="panel space-y-5">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold">Twilio workspace</h2>
              <p className="mt-1 text-xs text-ink/45">
                One subaccount and sender pool for this business
              </p>
            </div>
            <span className="pill capitalize">
              {configuration?.status ?? "Not configured"}
            </span>
          </div>
          {!platform?.configured && (
            <p className="rounded-xl bg-amber-50 p-4 text-sm leading-6 text-amber-800">
              First save and verify the master Twilio Account SID and Auth Token
              in{" "}
              <Link className="font-semibold underline" href="/admin/twilio">
                Admin → Twilio setup
              </Link>
              . The fields below are different: they connect this
              business&apos;s Messaging Service and sender.
            </p>
          )}
          {platform?.provider === "fake" && (
            <p className="rounded-xl bg-blue-50 p-4 text-sm leading-6 text-blue-800">
              Local safe mode is active. Messages are recorded through the fake
              provider and never leave B Review.
            </p>
          )}
          <label className="label">
            Customer Twilio subaccount SID
            <input
              className="field font-mono"
              name="subaccount_sid"
              required
              value={subaccountSid}
              onChange={(event) => setSubaccountSid(event.target.value)}
              placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
              pattern="AC[a-fA-F0-9]{32}"
              spellCheck={false}
            />
            <span className="mt-2 block text-xs font-normal text-ink/45">
              Must start with AC and contain exactly 32 letters/numbers after
              it. A Trial account may use the same Account SID configured by the
              administrator.
            </span>
            {fieldErrors.twilio_subaccount_sid?.[0] && (
              <span className="mt-2 block text-xs font-normal text-red-700">
                {fieldErrors.twilio_subaccount_sid[0]}
              </span>
            )}
          </label>
          <label className="label">
            Messaging Service SID
            <input
              className="field font-mono"
              name="service_sid"
              required
              value={serviceSid}
              onChange={(event) => setServiceSid(event.target.value)}
              placeholder="MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
              pattern="MG[a-fA-F0-9]{32}"
              spellCheck={false}
            />
            <span className="mt-2 block text-xs font-normal text-ink/45">
              Copy the Service SID from Twilio Messaging → Services. It starts
              with MG; it is not the Auth Token.
            </span>
            {fieldErrors.twilio_messaging_service_sid?.[0] && (
              <span className="mt-2 block text-xs font-normal text-red-700">
                {fieldErrors.twilio_messaging_service_sid[0]}
              </span>
            )}
          </label>
          <label className="label">
            Approved SMS sender
            <input
              className="field"
              name="sms_sender"
              value={smsSender}
              onChange={(event) => setSmsSender(event.target.value)}
              placeholder="+12025550123"
            />
            <span className="mt-2 block text-xs font-normal text-ink/45">
              Use E.164 format, such as +17138931144, and make sure this number
              is in the Messaging Service sender pool.
            </span>
            {fieldErrors.sms_sender?.[0] && (
              <span className="mt-2 block text-xs font-normal text-red-700">
                {fieldErrors.sms_sender[0]}
              </span>
            )}
          </label>
          <label className="flex items-start gap-3 rounded-xl border border-ink/10 p-4 text-sm">
            <input
              type="checkbox"
              name="sms_enabled"
              checked={smsEnabled}
              onChange={(event) => setSmsEnabled(event.target.checked)}
            />
            <span>
              <strong className="block">Enable SMS</strong>
              <span className="mt-1 block text-xs leading-5 text-ink/45">
                Requires an approved sender in the service pool and applicable
                carrier registration.
              </span>
            </span>
          </label>
          <div className="flex flex-wrap gap-3">
            <button className="button-primary" disabled={busy}>
              Save configuration
            </button>
            <button
              type="button"
              className="button-secondary"
              disabled={busy || !configuration || !platform?.configured}
              onClick={verify}
            >
              Verify & activate
            </button>
          </div>
          {configuration?.last_error && (
            <p className="text-xs text-red-700">{configuration.last_error}</p>
          )}
        </form>
        <aside className="space-y-5">
          <section className="rounded-xl bg-ink p-5 text-white">
            <p className="text-[10px] font-bold uppercase tracking-[.16em] text-[#ffb0b6]">
              Recommended sender model
            </p>
            <h2 className="mt-3 text-lg font-semibold">
              Dedicated per business
            </h2>
            <ul className="mt-4 space-y-3 text-xs leading-5 text-white/65">
              <li>
                • Separate reputation, opt-outs, analytics, and compliance.
              </li>
              <li>
                • A Messaging Service chooses from its approved SMS sender pool.
              </li>
              <li>
                • B Review never accepts an arbitrary unverified From number.
              </li>
            </ul>
          </section>
          <section className="panel">
            <p className="eyebrow">Webhook setup</p>
            <p className="mt-3 text-xs text-ink/45">Delivery status callback</p>
            <code className="mt-1 block break-all text-[11px]">
              {platform?.status_callback_url}
            </code>
            <p className="mt-4 text-xs text-ink/45">
              Incoming messages / opt-outs
            </p>
            <code className="mt-1 block break-all text-[11px]">
              {platform?.inbound_webhook_url}
            </code>
          </section>
        </aside>
      </div>
      <section className="panel mt-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold">Delivery history</h2>
            <p className="mt-1 text-xs text-ink/45">
              Phone numbers are masked in operational views.
            </p>
          </div>
          <span className="pill">{deliveries.length} messages</span>
        </div>
        {deliveries.length ? (
          <div className="mt-4 divide-y divide-ink/8">
            {deliveries.map((item) => (
              <div
                key={item.id}
                className="grid gap-2 py-4 text-sm sm:grid-cols-[1fr_1fr_auto_auto] sm:items-center"
              >
                <div>
                  <p className="font-semibold">
                    {item.customer?.first_name ?? "Unknown customer"}{" "}
                    {item.customer?.last_name}
                  </p>
                  <p className="text-xs text-ink/40">
                    •••• {item.to_last_four}
                  </p>
                </div>
                <p>{item.template?.name ?? "Deleted template"}</p>
                <div className="flex flex-wrap gap-2">
                  <span className="pill uppercase">{item.channel}</span>
                  {item.is_test && <span className="pill">TEST</span>}
                </div>
                <div className="text-right">
                  <p className="font-medium capitalize">{item.status}</p>
                  {(item.failure_message || item.provider_error_code) && (
                    <p className="mt-1 max-w-xs text-xs text-red-700">
                      {item.failure_message ?? item.provider_error_code}
                    </p>
                  )}
                  <p className="text-xs text-ink/40">
                    {new Date(item.created_at).toLocaleString()}
                  </p>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="mt-5 text-sm text-ink/50">
            No messages have been sent yet.
          </p>
        )}
      </section>
      <section className="panel mt-6">
        <div className="flex items-center justify-between gap-4">
          <div>
            <h2 className="text-lg font-semibold">Connection activity</h2>
            <p className="mt-1 text-xs text-ink/45">
              Configuration, verification, and test-message events are recorded
              without exposing credentials or full phone numbers.
            </p>
          </div>
          {configuration?.last_health_check_at && (
            <span className="pill">
              Checked{" "}
              {new Date(configuration.last_health_check_at).toLocaleString()}
            </span>
          )}
        </div>
        {activity.length ? (
          <div className="mt-4 divide-y divide-ink/8">
            {activity.map((item) => (
              <div
                key={item.id}
                className="flex flex-wrap items-start justify-between gap-3 py-4 text-sm"
              >
                <div>
                  <p className="font-semibold">{activityLabel(item.action)}</p>
                  {item.changes?.reason && (
                    <p className="mt-1 max-w-3xl text-xs text-red-700">
                      {item.changes.reason}
                    </p>
                  )}
                </div>
                <time className="text-xs text-ink/40">
                  {new Date(item.created_at).toLocaleString()}
                </time>
              </div>
            ))}
          </div>
        ) : (
          <p className="mt-5 text-sm text-ink/50">
            No connection activity recorded yet.
          </p>
        )}
      </section>
    </div>
  );
}

function activityLabel(action: string): string {
  const labels: Record<string, string> = {
    "messaging.configuration.updated": "Business Twilio configuration saved",
    "messaging.configuration.verified": "Business Twilio connection verified",
    "messaging.configuration.verification_failed":
      "Business Twilio verification failed",
    "template.test_message_sent": "Test SMS submitted to Twilio",
    "template.test_message_failed": "Test SMS failed",
  };
  return labels[action] ?? action.replaceAll(".", " ");
}
