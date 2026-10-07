"use client";

import { useEffect, useState } from "react";
import { ApiError, api } from "@/lib/api";
import { PasswordInput } from "@/components/password-input";

type TwilioSetting = {
  configured: boolean;
  account_sid?: string;
  auth_token_configured: boolean;
  shared_messaging_service_sid?: string;
  shared_sms_sender?: string;
  shared_sender_enabled: boolean;
  shared_sender_compliance_confirmed: boolean;
  mode: "trial" | "production";
  status: "not_configured" | "draft" | "verified" | "error";
  verified_at?: string;
  last_error?: string;
  activity: {
    id: string;
    action: string;
    changes?: { reason?: string };
    created_at: string;
  }[];
  status_callback_url: string;
  inbound_webhook_url: string;
};

export default function AdminTwilioPage() {
  const [setting, setSetting] = useState<TwilioSetting | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [accountSid, setAccountSid] = useState("");
  const [authToken, setAuthToken] = useState("");
  const [sharedServiceSid, setSharedServiceSid] = useState("");
  const [sharedSmsSender, setSharedSmsSender] = useState("");
  const [sharedSenderEnabled, setSharedSenderEnabled] = useState(false);
  const [complianceConfirmed, setComplianceConfirmed] = useState(false);
  const [mode, setMode] = useState<"trial" | "production">("trial");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  function applySetting(next: TwilioSetting) {
    setSetting(next);
    setAccountSid(next.account_sid ?? "");
    setMode(next.mode);
    setSharedServiceSid(next.shared_messaging_service_sid ?? "");
    setSharedSmsSender(next.shared_sms_sender ?? "");
    setSharedSenderEnabled(next.shared_sender_enabled);
    setComplianceConfirmed(next.shared_sender_compliance_confirmed);
  }

  async function load() {
    const result = await api<{ data: TwilioSetting }>("/api/v1/admin/twilio");
    applySetting(result.data);
  }

  useEffect(() => {
    void api<{ data: TwilioSetting }>("/api/v1/admin/twilio")
      .then((result) => applySetting(result.data))
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save() {
    setBusy(true);
    setMessage("");
    setFieldErrors({});
    try {
      const result = await api<{ data: TwilioSetting }>(
        "/api/v1/admin/twilio",
        {
          method: "PUT",
          body: JSON.stringify({
            account_sid: accountSid.trim(),
            auth_token: authToken || null,
            shared_messaging_service_sid: sharedServiceSid.trim() || null,
            shared_sms_sender: sharedSmsSender.trim() || null,
            shared_sender_enabled: sharedSenderEnabled,
            shared_sender_compliance_confirmed: complianceConfirmed,
            mode,
          }),
        },
      );
      applySetting(result.data);
      setAuthToken("");
      setMessage(
        "Credentials saved securely. Verify them before enabling delivery.",
      );
    } catch (error) {
      if (error instanceof ApiError) setFieldErrors(error.errors);
      setMessage(
        error instanceof Error
          ? error.message
          : "Unable to save Twilio credentials.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function verify() {
    setBusy(true);
    setMessage("");
    try {
      const result = await api<{
        data: {
          configuration: TwilioSetting;
          account: { name?: string; account_status?: string };
        };
      }>("/api/v1/admin/twilio/verify", { method: "POST", body: "{}" });
      applySetting(result.data.configuration);
      setMessage(
        `Twilio verified${result.data.account.name ? ` for ${result.data.account.name}` : ""}.`,
      );
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Twilio verification failed.",
      );
      await load().catch(() => undefined);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto max-w-6xl">
      <header className="border-b border-ink/8 pb-6">
        <p className="eyebrow">Configuration · Messaging provider</p>
        <h1 className="page-title">Twilio platform connection</h1>
        <p className="page-intro">
          Connect the B Review-owned Twilio account once. Credentials are
          encrypted and never shown again. Workspaces can inherit the verified
          default sender or use an approved dedicated connection.
        </p>
      </header>

      {message && (
        <p
          role="status"
          className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest"
        >
          {message}
        </p>
      )}

      <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <form action={save} className="panel space-y-5">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h2 className="text-xl font-semibold">Platform credentials</h2>
              <p className="mt-1 text-xs text-ink/45">
                Only a super administrator can change these values.
              </p>
            </div>
            <span className="pill capitalize">
              {setting?.status.replaceAll("_", " ") ?? "Loading"}
            </span>
          </div>
          <label className="label">
            Account SID
            <input
              className="field font-mono"
              name="account_sid"
              required
              pattern="AC[a-fA-F0-9]{32}"
              value={accountSid}
              onChange={(event) => setAccountSid(event.target.value)}
              placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
              spellCheck={false}
            />
            <span className="mt-2 block text-xs font-normal text-ink/45">
              For Trial testing, use the Account SID shown on the Twilio Console
              home page.
            </span>
            {fieldErrors.account_sid?.[0] && (
              <span className="mt-2 block text-xs font-normal text-red-700">
                {fieldErrors.account_sid[0]}
              </span>
            )}
          </label>
          <label className="label">
            Auth Token
            <PasswordInput
              className="field font-mono"
              name="auth_token"
              autoComplete="new-password"
              value={authToken}
              onChange={(event) => setAuthToken(event.target.value)}
              placeholder={
                setting?.auth_token_configured
                  ? "Saved securely — leave blank to keep it"
                  : "Enter the Twilio Auth Token"
              }
            />
            <span className="mt-2 block text-xs font-normal text-ink/45">
              B Review requires the Auth Token for signed webhooks and
              parent-account access. It is encrypted at rest and never returned
              to the browser.
            </span>
            {setting?.auth_token_configured && (
              <span className="mt-2 block text-xs font-semibold text-emerald-700">
                An encrypted Auth Token is saved.
              </span>
            )}
            {fieldErrors.auth_token?.[0] && (
              <span className="mt-2 block text-xs font-normal text-red-700">
                {fieldErrors.auth_token[0]}
              </span>
            )}
          </label>
          <label className="label">
            Account mode
            <select
              className="field"
              name="mode"
              value={mode}
              onChange={(event) =>
                setMode(event.target.value as "trial" | "production")
              }
            >
              <option value="trial">Trial testing</option>
              <option value="production">Production</option>
            </select>
          </label>
          <section className="space-y-4 rounded-xl border border-ink/10 bg-paper p-4">
            <label className="flex items-start gap-3 text-sm">
              <input
                className="mt-1"
                type="checkbox"
                checked={sharedSenderEnabled}
                onChange={(event) => setSharedSenderEnabled(event.target.checked)}
              />
              <span>
                <strong className="block">Offer the B Review default number</strong>
                <span className="mt-1 block text-xs leading-5 text-ink/50">
                  Customer workspaces can inherit this sender without receiving
                  the platform Auth Token.
                </span>
              </span>
            </label>
            {sharedSenderEnabled && (
              <>
                <label className="label">
                  Default Messaging Service SID
                  <input
                    className="field font-mono"
                    value={sharedServiceSid}
                    onChange={(event) => setSharedServiceSid(event.target.value)}
                    placeholder="MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                    required
                    pattern="MG[a-fA-F0-9]{32}"
                    spellCheck={false}
                  />
                  {fieldErrors.shared_messaging_service_sid?.[0] && (
                    <span className="mt-2 block text-xs font-normal text-red-700">
                      {fieldErrors.shared_messaging_service_sid[0]}
                    </span>
                  )}
                </label>
                <label className="label">
                  Default SMS number
                  <input
                    className="field"
                    value={sharedSmsSender}
                    onChange={(event) => setSharedSmsSender(event.target.value)}
                    placeholder="+17138931144"
                    required
                  />
                  {fieldErrors.shared_sms_sender?.[0] && (
                    <span className="mt-2 block text-xs font-normal text-red-700">
                      {fieldErrors.shared_sms_sender[0]}
                    </span>
                  )}
                </label>
                <label className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs leading-5 text-amber-900">
                  <input
                    className="mt-1"
                    type="checkbox"
                    checked={complianceConfirmed}
                    onChange={(event) => setComplianceConfirmed(event.target.checked)}
                  />
                  <span>
                    I confirm Twilio approved this number, Messaging Service,
                    Brand, and Campaign for the businesses and message use case
                    that will use the shared sender.
                  </span>
                </label>
                {fieldErrors.shared_sender_compliance_confirmed?.[0] && (
                  <span className="block text-xs text-red-700">
                    {fieldErrors.shared_sender_compliance_confirmed[0]}
                  </span>
                )}
              </>
            )}
          </section>
          <div className="flex flex-wrap gap-3">
            <button className="button-primary" disabled={busy}>
              {busy ? "Saving…" : "Save credentials"}
            </button>
            <button
              type="button"
              className="rounded-lg border border-ink/10 px-4 py-2.5 text-sm font-semibold disabled:opacity-50"
              disabled={busy || !setting?.configured}
              onClick={verify}
            >
              Verify connection
            </button>
          </div>
        </form>

        <aside className="space-y-5">
          <section className="rounded-xl bg-[#1d275f] p-5 text-white">
            <p className="eyebrow text-[#ffb0b6]">Trial checklist</p>
            <ol className="mt-4 space-y-3 text-xs leading-5 text-white/70">
              <li>1. Copy Account SID and Auth Token from Twilio Console.</li>
              <li>2. Verify the platform connection here.</li>
              <li>3. Create the approved Messaging Service.</li>
              <li>4. Add the purchased number to its sender pool.</li>
              <li>5. Verify the recipient number in Twilio.</li>
              <li>
                6. Save the default sender above, verify it, then select it in
                the customer workspace.
              </li>
            </ol>
            <a
              className="mt-5 inline-block text-xs font-semibold text-[#ffb0b6] underline"
              href="https://console.twilio.com/"
              target="_blank"
              rel="noreferrer"
            >
              Open Twilio Console ↗
            </a>
          </section>
          <section className="panel">
            <p className="eyebrow">Webhook URLs</p>
            <p className="mt-3 text-xs text-ink/45">Delivery status</p>
            <code className="mt-1 block break-all text-[11px]">
              {setting?.status_callback_url}
            </code>
            <p className="mt-4 text-xs text-ink/45">
              Incoming messages and opt-outs
            </p>
            <code className="mt-1 block break-all text-[11px]">
              {setting?.inbound_webhook_url}
            </code>
          </section>
          {setting?.last_error && (
            <section className="rounded-xl bg-red-50 p-4 text-xs leading-5 text-red-800">
              <strong className="block">Last verification error</strong>
              <span className="mt-1 block">{setting.last_error}</span>
            </section>
          )}
        </aside>
      </div>
      <section className="panel mt-6">
        <h2 className="text-lg font-semibold">Verification activity</h2>
        <p className="mt-1 text-xs text-ink/45">
          Credential values are never included in this log.
        </p>
        {setting?.activity.length ? (
          <div className="mt-4 divide-y divide-ink/8">
            {setting.activity.map((item) => (
              <div
                key={item.id}
                className="flex flex-wrap items-start justify-between gap-3 py-4 text-sm"
              >
                <div>
                  <p className="font-semibold">
                    {platformActivityLabel(item.action)}
                  </p>
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
            No Twilio activity recorded yet.
          </p>
        )}
      </section>
    </div>
  );
}

function platformActivityLabel(action: string): string {
  const labels: Record<string, string> = {
    "platform.twilio.credentials_updated": "Platform credentials saved",
    "platform.twilio.credentials_verified": "Platform connection verified",
    "platform.twilio.credentials_verification_failed":
      "Platform verification failed",
  };
  return labels[action] ?? action.replaceAll(".", " ");
}
