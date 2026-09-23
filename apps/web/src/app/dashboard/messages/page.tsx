"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Configuration = {
  status: string;
  twilio_subaccount_sid?: string;
  twilio_messaging_service_sid?: string;
  sms_sender?: string;
  sms_enabled: boolean;
  verified_at?: string;
  last_error?: string;
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
  customer: { first_name: string; last_name?: string };
  template: { name: string };
};

export default function MessagesPage() {
  const [configuration, setConfiguration] = useState<Configuration | null>(
    null,
  );
  const [platform, setPlatform] = useState<Platform | null>(null);
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  async function load() {
    const [settings, history] = await Promise.all([
      api<{
        data: { configuration: Configuration | null; platform: Platform };
      }>("/api/v1/messaging-configuration", {}, true),
      api<{ data: Delivery[] }>("/api/v1/message-deliveries", {}, true),
    ]);
    setConfiguration(settings.data.configuration);
    setPlatform(settings.data.platform);
    setDeliveries(history.data);
  }

  useEffect(() => {
    void Promise.all([
      api<{
        data: { configuration: Configuration | null; platform: Platform };
      }>("/api/v1/messaging-configuration", {}, true),
      api<{ data: Delivery[] }>("/api/v1/message-deliveries", {}, true),
    ])
      .then(([settings, history]) => {
        setConfiguration(settings.data.configuration);
        setPlatform(settings.data.platform);
        setDeliveries(history.data);
      })
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save(formData: FormData) {
    setBusy(true);
    setMessage("");
    try {
      await api(
        "/api/v1/messaging-configuration",
        {
          method: "PUT",
          body: JSON.stringify({
            twilio_subaccount_sid: formData.get("subaccount_sid"),
            twilio_messaging_service_sid: formData.get("service_sid"),
            sms_sender: formData.get("sms_sender") || null,
            sms_enabled: formData.get("sms_enabled") === "on",
            whatsapp_enabled: false,
          }),
        },
        true,
      );
      setMessage("Messaging configuration saved. Verify it before sending.");
      await load();
    } catch (error) {
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
              A platform administrator must add the Twilio Account SID and Auth
              Token before this workspace can be verified.
            </p>
          )}
          {platform?.provider === "fake" && (
            <p className="rounded-xl bg-blue-50 p-4 text-sm leading-6 text-blue-800">
              Local safe mode is active. Messages are recorded through the fake
              provider and never leave Breviews.
            </p>
          )}
          <label className="label">
            Customer Twilio subaccount SID
            <input
              className="field font-mono"
              name="subaccount_sid"
              required
              defaultValue={configuration?.twilio_subaccount_sid}
              placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
            />
          </label>
          <label className="label">
            Messaging Service SID
            <input
              className="field font-mono"
              name="service_sid"
              required
              defaultValue={configuration?.twilio_messaging_service_sid}
              placeholder="MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
            />
          </label>
          <label className="label">
            Approved SMS sender
            <input
              className="field"
              name="sms_sender"
              defaultValue={configuration?.sms_sender}
              placeholder="+12025550123"
            />
          </label>
          <label className="flex items-start gap-3 rounded-xl border border-ink/10 p-4 text-sm">
            <input
              type="checkbox"
              name="sms_enabled"
              defaultChecked={configuration?.sms_enabled}
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
            <p className="text-[10px] font-bold uppercase tracking-[.16em] text-mint">
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
                • Breviews never accepts an arbitrary unverified From number.
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
                    {item.customer.first_name} {item.customer.last_name}
                  </p>
                  <p className="text-xs text-ink/40">
                    •••• {item.to_last_four}
                  </p>
                </div>
                <p>{item.template.name}</p>
                <span className="pill uppercase">{item.channel}</span>
                <div className="text-right">
                  <p className="font-medium capitalize">{item.status}</p>
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
    </div>
  );
}
