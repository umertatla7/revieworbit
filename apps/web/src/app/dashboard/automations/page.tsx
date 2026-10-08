"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Location = { id: string; name: string };
type Template = {
  id: string;
  name: string;
  status: string;
  location_id: string;
  review_destination?: { provider: string };
};
type FollowUp = {
  id?: string;
  message_template_id: string;
  delay_minutes: number;
  cancel_after_click: boolean;
  message_template?: Template;
};
type Rule = {
  id: string;
  name: string;
  status: string;
  trigger_type: "visit.completed" | "contacts.manual";
  delay_minutes: number;
  frequency_limit_days: number;
  quiet_hours_start?: string;
  quiet_hours_end?: string;
  location_id?: string;
  location?: Location;
  message_template_id: string;
  message_template: Template;
  follow_ups: FollowUp[];
  runs?: { id: string; status: string; scheduled_count: number; skipped_count: number; created_at: string }[];
};
type Audience = {
  total_contacts: number;
  eligible_contacts: number;
  new_plan_customers: number;
  customers_remaining_this_month: number | null;
  skipped_contacts: number;
  skipped_summary: Record<string, number>;
  first_message_at?: string;
};
type Entitlements = {
  plan_name: string;
  automation_limit: number;
  automations_used: number;
  automation_step_limit: number;
  can_add_automation: boolean;
};

export default function AutomationsPage() {
  const [locations, setLocations] = useState<Location[]>([]);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [rules, setRules] = useState<Rule[]>([]);
  const [entitlements, setEntitlements] = useState<Entitlements | null>(null);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Rule | null>(null);
  const [launchRule, setLaunchRule] = useState<Rule | null>(null);
  const [audience, setAudience] = useState<Audience | null>(null);
  const [launchBusy, setLaunchBusy] = useState(false);
  const [message, setMessage] = useState("");
  async function load() {
    const [business, templateResult, ruleResult] = await Promise.all([
      api<{ data: { locations: Location[]; entitlements: Entitlements } }>(
        "/api/v1/business",
        {},
        true,
      ),
      api<{ data: Template[] }>("/api/v1/templates", {}, true),
      api<{ data: Rule[] }>("/api/v1/automations", {}, true),
    ]);
    setLocations(business.data.locations);
    setEntitlements(business.data.entitlements);
    setTemplates(templateResult.data);
    setRules(ruleResult.data);
  }
  useEffect(() => {
    void Promise.all([
      api<{ data: { locations: Location[]; entitlements: Entitlements } }>(
        "/api/v1/business",
        {},
        true,
      ),
      api<{ data: Template[] }>("/api/v1/templates", {}, true),
      api<{ data: Rule[] }>("/api/v1/automations", {}, true),
    ])
      .then(([business, templateResult, ruleResult]) => {
        setLocations(business.data.locations);
        setEntitlements(business.data.entitlements);
        setTemplates(templateResult.data);
        setRules(ruleResult.data);
      })
      .catch((error: Error) => setMessage(error.message));
  }, []);
  async function save(payload: Record<string, unknown>) {
    try {
      await api(
        editing ? `/api/v1/automations/${editing.id}` : "/api/v1/automations",
        { method: editing ? "PATCH" : "POST", body: JSON.stringify(payload) },
        true,
      );
      setOpen(false);
      setEditing(null);
      setMessage(editing ? "Automation updated." : "Automation activated.");
      await load();
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Unable to save automation.",
      );
    }
  }
  async function reviewLaunch(rule: Rule) {
    setLaunchBusy(true);
    setMessage("");
    try {
      const result = await api<{ data: Audience }>(
        `/api/v1/automations/${rule.id}/audience`,
        {},
        true,
      );
      setAudience(result.data);
      setLaunchRule(rule);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to calculate the eligible audience.");
    } finally {
      setLaunchBusy(false);
    }
  }
  async function launch() {
    if (!launchRule) return;
    setLaunchBusy(true);
    try {
      const result = await api<{ data: { scheduled_count: number; skipped_count: number } }>(
        `/api/v1/automations/${launchRule.id}/launch`,
        { method: "POST" },
        true,
      );
      setMessage(`Scheduled ${result.data.scheduled_count} eligible contact${result.data.scheduled_count === 1 ? "" : "s"}. ${result.data.skipped_count} contact${result.data.skipped_count === 1 ? " was" : "s were"} skipped by consent and safety checks.`);
      setLaunchRule(null);
      setAudience(null);
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to launch this automation.");
    } finally {
      setLaunchBusy(false);
    }
  }
  return (
    <div className="mx-auto max-w-7xl">
      <div className="flex flex-wrap items-end justify-between gap-5">
        <div>
          <p className="eyebrow">Automation rules</p>
          <h1 className="page-title">Build the follow-up journey</h1>
          <p className="page-intro">
            Build journeys triggered by a completed visit, or launch a journey
            for eligible consented contacts added manually or by CSV.
          </p>
        </div>
        <button
          className="button-primary"
          disabled={entitlements ? !entitlements.can_add_automation : false}
          onClick={() => {
            setEditing(null);
            setOpen(true);
          }}
        >
          + Create automation
        </button>
      </div>
      {message && (
        <p className="mt-5 rounded-xl bg-white px-4 py-3 text-sm text-forest">
          {message}
        </p>
      )}
      {entitlements && (
        <div className="mt-6 rounded-xl border border-ink/8 bg-white p-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-semibold">
                {entitlements.plan_name} automation allowance
              </p>
              <p className="mt-1 text-xs text-ink/45">
                Up to {entitlements.automation_step_limit} message steps in each
                automation.
              </p>
            </div>
            <span className="pill">
              {entitlements.automations_used} of {entitlements.automation_limit}{" "}
              automations used
            </span>
          </div>
        </div>
      )}
      <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white">
        <header className="border-b border-ink/8 px-5 py-4">
          <h2 className="font-semibold">Active journeys</h2>
          <p className="mt-1 text-xs text-ink/45">
            Delays start from visit completion or from the moment you explicitly
            launch a contact-list journey. Quiet hours are always respected.
          </p>
        </header>
        <div className="divide-y divide-ink/8">
          {rules.map((rule) => (
            <article key={rule.id} className="px-5 py-5">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold">{rule.name}</h3>
                    <span
                      className={`pill capitalize ${rule.status === "active" ? "bg-emerald-50 text-emerald-800" : ""}`}
                    >
                      {rule.status}
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-ink/45">
                    {rule.location?.name ?? "All locations"} ·{" "}
                    {rule.frequency_limit_days}-day frequency protection ·{" "}
                    {rule.trigger_type === "contacts.manual" ? "Manual contact list" : "Completed visit"}
                  </p>
                </div>
                <div className="flex gap-2">
                  {rule.trigger_type === "contacts.manual" && rule.status === "active" && (
                    <button className="button-primary px-3 py-2 text-xs" disabled={launchBusy} onClick={() => void reviewLaunch(rule)}>
                      Review &amp; send
                    </button>
                  )}
                  <button
                    className="rounded-lg border border-ink/10 px-3 py-2 text-xs font-semibold"
                    onClick={() => {
                      setEditing(rule);
                      setOpen(true);
                    }}
                  >
                    Edit
                  </button>
                </div>
              </div>
              <div className="mt-5 flex flex-col gap-2 md:flex-row md:items-stretch">
                {[
                  {
                    template: rule.message_template,
                    delay: rule.delay_minutes,
                    cancel: false,
                  },
                  ...rule.follow_ups.map((step) => ({
                    template: step.message_template!,
                    delay: step.delay_minutes,
                    cancel: step.cancel_after_click,
                  })),
                ].map((step, index) => (
                  <div
                    key={index}
                    className="flex min-w-0 flex-1 items-center gap-3"
                  >
                    <div className="min-w-0 flex-1 rounded-xl bg-paper p-4">
                      <p className="text-[10px] font-bold uppercase tracking-wider text-forest">
                        Step {index + 1} · {formatDelay(step.delay, rule.trigger_type)}
                      </p>
                      <p className="mt-2 truncate text-sm font-semibold">
                        {step.template?.name}
                      </p>
                      <p className="mt-1 text-[11px] capitalize text-ink/45">
                        {step.template?.review_destination?.provider ??
                          "Review destination"}
                        {step.cancel ? " · only if link not opened" : ""}
                      </p>
                    </div>
                    {index < rule.follow_ups.length && (
                      <span className="hidden text-ink/25 md:block">→</span>
                    )}
                  </div>
                ))}
              </div>
            </article>
          ))}
          {rules.length === 0 && (
            <div className="px-6 py-16 text-center">
              <p className="text-sm font-semibold">No automations yet</p>
              <p className="mt-2 text-xs text-ink/45">
                Create the first post-visit message journey.
              </p>
            </div>
          )}
        </div>
      </section>
      {open && (
        <AutomationDialog
          locations={locations}
          templates={templates}
          entitlement={entitlements}
          rule={editing}
          onClose={() => {
            setOpen(false);
            setEditing(null);
          }}
          onSave={save}
        />
      )}
      {launchRule && audience && (
        <LaunchDialog rule={launchRule} audience={audience} busy={launchBusy} onClose={() => { setLaunchRule(null); setAudience(null); }} onLaunch={() => void launch()} />
      )}
    </div>
  );
}

function AutomationDialog({
  locations,
  templates,
  entitlement,
  rule,
  onClose,
  onSave,
}: {
  locations: Location[];
  templates: Template[];
  entitlement: Entitlements | null;
  rule: Rule | null;
  onClose: () => void;
  onSave: (payload: Record<string, unknown>) => void;
}) {
  const [locationId, setLocationId] = useState(rule?.location_id ?? "");
  const [triggerType, setTriggerType] = useState<"visit.completed" | "contacts.manual">(
    rule?.trigger_type ?? "visit.completed",
  );
  const [steps, setSteps] = useState<
    { template: string; delay: number; cancel: boolean }[]
  >(
    rule
      ? [
          {
            template: rule.message_template_id,
            delay: rule.delay_minutes,
            cancel: false,
          },
          ...rule.follow_ups.map((item) => ({
            template: item.message_template_id,
            delay: item.delay_minutes,
            cancel: item.cancel_after_click,
          })),
        ]
      : [{ template: "", delay: 60, cancel: false }],
  );
  const available = templates.filter((item) => item.location_id === locationId);
  const activeAvailable = available.filter((item) => item.status === "active");
  const maxSteps = entitlement?.automation_step_limit ?? 1;
  function submit(data: FormData) {
    onSave({
      name: data.get("name"),
      trigger_type: triggerType,
      location_id: locationId || null,
      message_template_id: steps[0].template,
      delay_minutes: steps[0].delay,
      frequency_limit_days: Number(data.get("frequency_limit_days")),
      cancel_follow_up_after_click: true,
      status: data.get("status"),
      follow_ups: steps
        .slice(1)
        .map((step) => ({
          message_template_id: step.template,
          delay_minutes: step.delay,
          cancel_after_click: step.cancel,
        })),
    });
  }
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-4">
      <form
        action={submit}
        className="max-h-[92vh] w-full max-w-3xl overflow-auto rounded-xl bg-white p-6 shadow-2xl"
      >
        <div className="flex justify-between">
          <div>
            <p className="eyebrow">Automation builder</p>
            <h2 className="mt-1 text-xl font-semibold">
              {rule ? "Edit automation" : "Create automation"}
            </h2>
          </div>
          <button type="button" onClick={onClose}>
            ×
          </button>
        </div>
        <div className="mt-5 rounded-xl border border-forest/10 bg-mint/15 p-4 text-xs leading-5 text-ink/65">
          <strong className="block text-ink">When does the timer start?</strong>
          {triggerType === "visit.completed" ? (
            <>The timer begins when the POS or a staff member marks a visit completed—not at the booked appointment time.</>
          ) : (
            <>The timer begins only after you review the eligible audience and click Send. Contacts without valid SMS consent are skipped automatically.</>
          )}{" "}Safe overnight delivery hours are applied automatically.
        </div>
        <fieldset className="mt-6">
          <legend className="label">Start this automation</legend>
          <div className="mt-2 grid gap-3 sm:grid-cols-2">
            <button type="button" className={`rounded-xl border p-4 text-left ${triggerType === "visit.completed" ? "border-forest bg-forest/5" : "border-ink/10"}`} onClick={() => setTriggerType("visit.completed")}>
              <strong className="block text-sm">After a completed visit</strong>
              <span className="mt-1 block text-xs text-ink/50">Automatic when a POS or staff member completes a visit.</span>
            </button>
            <button type="button" className={`rounded-xl border p-4 text-left ${triggerType === "contacts.manual" ? "border-forest bg-forest/5" : "border-ink/10"}`} onClick={() => setTriggerType("contacts.manual")}>
              <strong className="block text-sm">Eligible contact list</strong>
              <span className="mt-1 block text-xs text-ink/50">Manually launch to consented contacts imported by CSV or added in the dashboard.</span>
            </button>
          </div>
        </fieldset>
        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          <label className="label">
            Name
            <input
              className="field"
              name="name"
              required
              defaultValue={rule?.name}
            />
          </label>
          <label className="label">
            Location
            <select
              className="field"
              required={triggerType === "contacts.manual"}
              value={locationId}
              onChange={(event) => {
                setLocationId(event.target.value);
                setSteps([{ template: "", delay: 60, cancel: false }]);
              }}
            >
              <option value="">All locations</option>
              {locations.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
            {triggerType === "contacts.manual" && !locationId && (
              <span className="mt-1 block text-[11px] font-normal text-amber-800">Choose one location for its business name and review link.</span>
            )}
          </label>
          <label className="label">
            Do not request another review for
            <input
              className="field"
              type="number"
              min="0"
              max="365"
              name="frequency_limit_days"
              defaultValue={rule?.frequency_limit_days ?? 30}
            />
            <span className="mt-1 block text-[11px] font-normal text-ink/45">
              Days after a review request. Confirmed reviewers are skipped
              permanently.
            </span>
          </label>
          <label className="label">
            Status
            <select
              className="field"
              name="status"
              defaultValue={rule?.status ?? "active"}
            >
              <option value="active">Active</option>
              <option value="draft">Draft</option>
              <option value="disabled">Disabled</option>
            </select>
          </label>
        </div>
        <div className="mt-7 flex items-center justify-between">
          <div>
            <h3 className="font-semibold">Message steps</h3>
            <p className="mt-1 text-xs text-ink/45">
              Every step is timed from {triggerType === "contacts.manual" ? "the manual launch" : "visit completion"} and must be later than the previous step.
            </p>
          </div>
          {steps.length < maxSteps && (
            <button
              type="button"
              className="rounded-lg border border-forest/20 px-3 py-2 text-xs font-semibold text-forest"
              onClick={() =>
                setSteps([
                  ...steps,
                  {
                    template: "",
                    delay: steps.at(-1)!.delay + 2880,
                    cancel: true,
                  },
                ])
              }
            >
              + Add follow-up
            </button>
          )}
        </div>
        <div className="mt-3 space-y-3">
          {steps.map((step, index) => (
            <div
              key={index}
              className="grid gap-3 rounded-xl border border-ink/10 p-4 sm:grid-cols-[70px_1fr_220px_auto] sm:items-end"
            >
              <div>
                <span className="grid size-9 place-items-center rounded-full bg-forest text-xs font-bold text-white">
                  {index + 1}
                </span>
              </div>
              <label className="label">
                Message template
                <select
                  required
                  className="field"
                  value={step.template}
                  onChange={(event) =>
                    setSteps(
                      steps.map((item, position) =>
                        position === index
                          ? { ...item, template: event.target.value }
                          : item,
                      ),
                    )
                  }
                >
                  <option value="" disabled>
                    Select active template
                  </option>
                  {available.map((item) => (
                    <option
                      key={item.id}
                      value={item.id}
                      disabled={item.status !== "active"}
                    >
                      {item.name}
                      {item.status !== "active"
                        ? " (Draft — activate first)"
                        : ""}
                    </option>
                  ))}
                </select>
                {locationId && activeAvailable.length === 0 && (
                  <span className="mt-2 block text-[11px] font-normal text-amber-800">
                    No active template is available for this location. Open
                    Message templates and change the saved template status to
                    Active.
                  </span>
                )}
              </label>
              <DelayInput
                minutes={step.delay}
                onChange={(delay) =>
                  setSteps(
                    steps.map((item, position) =>
                      position === index ? { ...item, delay } : item,
                    ),
                  )
                }
              />
              <div>
                {index > 0 && (
                  <>
                    <label className="flex gap-2 text-[11px]">
                      <input
                        type="checkbox"
                        checked={step.cancel}
                        onChange={(event) =>
                          setSteps(
                            steps.map((item, position) =>
                              position === index
                                ? { ...item, cancel: event.target.checked }
                                : item,
                            ),
                          )
                        }
                      />
                      Send only if link was not opened
                    </label>
                    <button
                      type="button"
                      className="mt-2 text-xs font-semibold text-red-700"
                      onClick={() =>
                        setSteps(
                          steps.filter((_, position) => position !== index),
                        )
                      }
                    >
                      Remove
                    </button>
                  </>
                )}
              </div>
            </div>
          ))}
        </div>
        {locationId === "" && (
          <p className="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
            Choose a location to see its active templates and review link.
          </p>
        )}
        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            className="rounded-lg border border-ink/10 px-4 py-2"
            onClick={onClose}
          >
            Cancel
          </button>
          <button className="button-primary" disabled={triggerType === "contacts.manual" && !locationId}>Save automation</button>
        </div>
      </form>
    </div>
  );
}
function DelayInput({
  minutes,
  onChange,
}: {
  minutes: number;
  onChange: (minutes: number) => void;
}) {
  const [unit, setUnit] = useState(
    minutes % 1440 === 0 ? 1440 : minutes % 60 === 0 ? 60 : 1,
  );
  const value = Math.max(unit === 1 ? 0 : 1, Math.round(minutes / unit));
  return (
    <label className="label">
      Send after
      <div className="mt-2 grid grid-cols-[1fr_100px] gap-2">
        <input
          aria-label="Delay amount"
          className="field mt-0"
          type="number"
          min={unit === 1 ? 0 : 1}
          max={unit === 1440 ? 30 : unit === 60 ? 720 : 43200}
          value={value}
          onChange={(event) => onChange(Number(event.target.value) * unit)}
        />
        <select
          aria-label="Delay unit"
          className="field mt-0"
          value={unit}
          onChange={(event) => {
            const next = Number(event.target.value);
            setUnit(next);
            onChange(value * next);
          }}
        >
          <option value={1}>Minutes</option>
          <option value={60}>Hours</option>
          <option value={1440}>Days</option>
        </select>
      </div>
    </label>
  );
}
function formatDelay(minutes: number, trigger: Rule["trigger_type"]) {
  const suffix = trigger === "contacts.manual" ? "after launch" : "after visit";
  if (minutes < 60) return `${minutes} min ${suffix}`;
  if (minutes < 1440) return `${Math.round(minutes / 60)} hr ${suffix}`;
  return `${Math.round(minutes / 1440)} day(s) ${suffix}`;
}

function LaunchDialog({ rule, audience, busy, onClose, onLaunch }: { rule: Rule; audience: Audience; busy: boolean; onClose: () => void; onLaunch: () => void }) {
  const labels: Record<string, string> = {
    inactive: "Inactive contacts",
    phone_missing: "Missing phone",
    consent_missing: "SMS consent missing",
    suppressed: "Suppressed / opted out",
    review_already_confirmed: "Review already confirmed",
    frequency_limited: "Recently messaged",
    already_scheduled: "Already scheduled",
  };
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-4">
      <div className="w-full max-w-xl rounded-xl bg-white p-6 shadow-2xl">
        <div className="flex items-start justify-between gap-4">
          <div><p className="eyebrow">Review audience</p><h2 className="mt-1 text-xl font-semibold">Send {rule.name}</h2></div>
          <button onClick={onClose}>×</button>
        </div>
        <div className="mt-5 grid gap-3 sm:grid-cols-3">
          <AutomationMetric label="Eligible" value={audience.eligible_contacts} detail="Will be scheduled" />
          <AutomationMetric label="Skipped" value={audience.skipped_contacts} detail="Safety checks" />
          <AutomationMetric label="Total contacts" value={audience.total_contacts} detail="In this workspace" />
        </div>
        {Object.keys(audience.skipped_summary).length > 0 && (
          <div className="mt-5 rounded-xl bg-paper p-4"><p className="text-xs font-semibold">Skipped contacts</p><div className="mt-2 space-y-1 text-xs text-ink/55">{Object.entries(audience.skipped_summary).map(([reason, count]) => <div key={reason} className="flex justify-between"><span>{labels[reason] ?? reason}</span><strong>{count}</strong></div>)}</div></div>
        )}
        <p className="mt-5 rounded-xl border border-forest/15 bg-mint/15 p-4 text-xs leading-5 text-ink/65">
          Only active contacts with a valid SMS consent record are included. Suppressed contacts, confirmed reviewers, recently messaged contacts, and contacts already scheduled are excluded. The exact saved template is sent; no “test” or “via B Review” text is added.
        </p>
        {audience.first_message_at && <p className="mt-3 text-xs text-ink/50">First messages are scheduled for {new Date(audience.first_message_at).toLocaleString()}.</p>}
        <div className="mt-6 flex justify-end gap-2"><button className="button-secondary" onClick={onClose}>Cancel</button><button className="button-primary" disabled={busy || audience.eligible_contacts === 0} onClick={onLaunch}>{busy ? "Scheduling…" : `Send to ${audience.eligible_contacts} eligible contact${audience.eligible_contacts === 1 ? "" : "s"}`}</button></div>
      </div>
    </div>
  );
}

function AutomationMetric({ label, value, detail }: { label: string; value: number; detail: string }) {
  return <div className="rounded-xl bg-paper p-4"><p className="text-[10px] font-bold uppercase tracking-wider text-ink/40">{label}</p><p className="mt-2 text-2xl font-semibold">{value}</p><p className="mt-1 text-[10px] text-ink/40">{detail}</p></div>;
}
