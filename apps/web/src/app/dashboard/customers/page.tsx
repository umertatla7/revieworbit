"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Consent = { channel: string; status: string; recorded_at?: string };
type Suppression = { channel: string; released_at?: string };
type Delivery = {
  id: string;
  status: string;
  channel: string;
  body_snapshot?: string;
  created_at: string;
  template?: { name: string };
  review_link?: { first_clicked_at?: string; click_count: number };
};
type ReviewLink = {
  id: string;
  click_count: number;
  first_clicked_at?: string;
  created_at: string;
  location?: { name: string };
  destination?: { provider: string };
  deliveries?: Delivery[];
};
type Visit = {
  id: string;
  completed_at: string;
  source: string;
  type: string;
  status: string;
  location?: { name: string };
};
type Customer = {
  id: string;
  first_name: string;
  last_name?: string;
  email?: string;
  phone_e164?: string;
  status: string;
  source: string;
  review_request_status: "eligible" | "review_confirmed";
  review_confirmed_at?: string;
  review_confirmation_source?: string;
  consents: Consent[];
  suppressions: Suppression[];
  visits?: Visit[];
  review_links?: ReviewLink[];
  message_deliveries?: Delivery[];
  visits_count?: number;
  review_links_count?: number;
  clicked_review_links_count?: number;
};
type Meta = { current_page: number; last_page: number; total: number };
type ImportPreview = {
  valid_rows: number;
  preview: Record<string, string>[];
  errors: { row: number; reason: string }[];
};

export default function CustomersPage() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [meta, setMeta] = useState<Meta>({
    current_page: 1,
    last_page: 1,
    total: 0,
  });
  const [filters, setFilters] = useState({
    search: "",
    visit: "",
    link: "",
    sms: "",
    source: "",
    review_status: "",
  });
  const [dialog, setDialog] = useState<"add" | "import" | null>(null);
  const [selected, setSelected] = useState<Customer | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  async function load(page = 1, values = filters) {
    const params = new URLSearchParams({ page: String(page) });
    Object.entries(values).forEach(([key, value]) => {
      if (value) params.set(key, value);
    });
    const result = await api<{ data: Customer[]; meta: Meta }>(
      `/api/v1/customers?${params}`,
      {},
      true,
    );
    setCustomers(result.data);
    setMeta(result.meta);
  }

  useEffect(() => {
    void api<{ data: Customer[]; meta: Meta }>(
      "/api/v1/customers?page=1",
      {},
      true,
    )
      .then((result) => {
        setCustomers(result.data);
        setMeta(result.meta);
      })
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function openCustomer(id: string) {
    setBusy(true);
    setMessage("");
    try {
      const result = await api<{ data: Customer }>(
        `/api/v1/customers/${id}`,
        {},
        true,
      );
      setSelected(result.data);
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : "Unable to load customer activity.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function create(formData: FormData) {
    setBusy(true);
    setMessage("");
    try {
      const result = await api<{ data: Customer }>(
        "/api/v1/customers",
        {
          method: "POST",
          body: JSON.stringify({
            first_name: formData.get("first_name"),
            last_name: formData.get("last_name") || null,
            phone: formData.get("phone"),
            email: formData.get("email") || null,
            source: "manual",
          }),
        },
        true,
      );
      if (formData.get("consent") === "granted")
        await api(
          `/api/v1/customers/${result.data.id}/consents`,
          {
            method: "POST",
            body: JSON.stringify({
              channel: "sms",
              status: "granted",
              source: formData.get("consent_source"),
              disclosure_version: "manual-entry-v1",
            }),
          },
          true,
        );
      setDialog(null);
      setMessage("Customer added successfully.");
      await load(1);
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Unable to add customer.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function changeSuppression(customer: Customer) {
    const suppressed = consentState(customer) === "Suppressed";
    setBusy(true);
    try {
      await api(
        `/api/v1/customers/${customer.id}/suppressions`,
        {
          method: suppressed ? "DELETE" : "POST",
          body: JSON.stringify({ channel: "sms", reason: "manual" }),
        },
        true,
      );
      setMessage(
        suppressed
          ? "SMS suppression removed."
          : "SMS suppressed for this customer.",
      );
      if (selected?.id === customer.id) await openCustomer(customer.id);
      else await load(meta.current_page);
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : "Unable to update SMS suppression.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function resend(link: ReviewLink, data: FormData) {
    setBusy(true);
    try {
      await api(
        `/api/v1/review-links/${link.id}/resend`,
        { method: "POST", body: JSON.stringify({ body: data.get("body") }) },
        true,
      );
      setMessage("Follow-up message queued after consent and plan checks.");
      if (selected) await openCustomer(selected.id);
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Unable to send follow-up.",
      );
    } finally {
      setBusy(false);
    }
  }

  async function changeReviewStatus(customer: Customer) {
    const confirmed = customer.review_request_status === "review_confirmed";
    setBusy(true);
    try {
      await api(`/api/v1/customers/${customer.id}/review-status`, {
        method: "PATCH",
        body: JSON.stringify({
          status: confirmed ? "eligible" : "review_confirmed",
          source: confirmed ? undefined : "manual",
        }),
      }, true);
      setMessage(confirmed ? "Customer is eligible for future review requests again." : "Review confirmed. Future review requests and pending follow-ups are stopped.");
      await openCustomer(customer.id);
      await load(meta.current_page);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to update review eligibility.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto max-w-[1380px]">
      <header className="flex flex-col justify-between gap-5 border-b border-ink/8 pb-6 lg:flex-row lg:items-end">
        <div>
          <p className="eyebrow">Engagement</p>
          <h1 className="page-title">Customers & activity</h1>
          <p className="page-intro">
            Customers, visits, SMS delivery, and review-link activity together
            in one easy view.
          </p>
        </div>
        <div className="flex gap-2">
          <button
            className="button-secondary"
            onClick={() => setDialog("import")}
          >
            Import CSV
          </button>
          <button className="button-primary" onClick={() => setDialog("add")}>
            + Add customer
          </button>
        </div>
      </header>
      {message && (
        <p
          role="status"
          className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest"
        >
          {message}
        </p>
      )}
      <div className="mt-6 grid gap-3 sm:grid-cols-3">
        <Metric
          label="All customers"
          value={meta.total}
          detail="Across this workspace"
        />
        <Metric
          label="With a visit"
          value={
            customers.filter((item) => (item.visits_count ?? 0) > 0).length
          }
          detail="On this page"
        />
        <Metric
          label="Review link clicked"
          value={
            customers.filter(
              (item) => (item.clicked_review_links_count ?? 0) > 0,
            ).length
          }
          detail="On this page"
        />
      </div>
      <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm">
        <div className="border-b border-ink/8 p-5">
          <div>
            <h2 className="text-sm font-semibold">
              Find the customers who need attention
            </h2>
            <p className="mt-1 text-[11px] text-ink/40">
              Filter by visit history, SMS permission, source, or whether a
              review link was opened.
            </p>
          </div>
          <form
            className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-[1.5fr_repeat(5,1fr)_auto]"
            onSubmit={(event) => {
              event.preventDefault();
              void load(1);
            }}
          >
            <input
              aria-label="Search customers"
              className="field py-2.5 text-xs"
              value={filters.search}
              onChange={(event) =>
                setFilters({ ...filters, search: event.target.value })
              }
              placeholder="Search name, email, or phone"
            />
            <Filter
              value={filters.visit}
              onChange={(visit) => setFilters({ ...filters, visit })}
              label="All visits"
              options={[
                ["has_visits", "Has visits"],
                ["no_visits", "No visits"],
                ["recent_30", "Visited in 30 days"],
                ["inactive_90", "No visit in 90 days"],
              ]}
            />
            <Filter
              value={filters.link}
              onChange={(link) => setFilters({ ...filters, link })}
              label="All link activity"
              options={[
                ["clicked", "Link clicked"],
                ["not_clicked", "Link not clicked"],
                ["no_link", "No link sent"],
              ]}
            />
            <Filter
              value={filters.sms}
              onChange={(sms) => setFilters({ ...filters, sms })}
              label="All SMS status"
              options={[
                ["consented", "SMS consented"],
                ["suppressed", "SMS suppressed"],
                ["not_recorded", "Consent missing"],
              ]}
            />
            <Filter
              value={filters.review_status}
              onChange={(review_status) => setFilters({ ...filters, review_status })}
              label="All review eligibility"
              options={[
                ["eligible", "Can request review"],
                ["review_confirmed", "Review confirmed"],
              ]}
            />
            <Filter
              value={filters.source}
              onChange={(source) => setFilters({ ...filters, source })}
              label="All sources"
              options={[
                ["manual", "Manual"],
                ["import", "CSV import"],
                ["square", "Square POS"],
                ["toast", "Toast POS"],
              ]}
            />
            <button className="rounded-lg bg-ink px-4 py-2.5 text-xs font-semibold text-white">
              Apply filters
            </button>
          </form>
          <button
            className="mt-3 text-[11px] font-semibold text-forest underline"
            onClick={() => {
              const reset = {
                search: "",
                visit: "",
                link: "",
                sms: "",
                source: "",
                review_status: "",
              };
              setFilters(reset);
              void load(1, reset);
            }}
          >
            Clear all filters
          </button>
        </div>
        <div className="hidden grid-cols-[1.2fr_1fr_1fr_1fr_110px_90px] gap-4 border-b border-ink/8 bg-paper/70 px-5 py-3 text-[10px] font-bold uppercase tracking-wider text-ink/35 lg:grid">
          <span>Customer</span>
          <span>Last visit</span>
          <span>Review link</span>
          <span>SMS status</span>
          <span>Source</span>
          <span></span>
        </div>
        <div className="divide-y divide-ink/8">
          {customers.length ? (
            customers.map((customer) => {
              const visit = customer.visits?.[0];
              const link = customer.review_links?.[0];
              return (
                <article
                  key={customer.id}
                  className="grid gap-4 px-5 py-4 transition hover:bg-paper/50 lg:grid-cols-[1.2fr_1fr_1fr_1fr_110px_90px] lg:items-center"
                >
                  <div className="flex items-center gap-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-forest/8 text-xs font-bold text-forest">
                      {customer.first_name[0]}
                      {customer.last_name?.[0] ?? ""}
                    </span>
                    <div>
                      <p className="text-sm font-semibold">
                        {customer.first_name} {customer.last_name}
                      </p>
                      <p className="mt-0.5 text-[10px] text-ink/40">
                        {customer.phone_e164} · {customer.email || "No email"}
                      </p>
                    </div>
                  </div>
                  <div>
                    <p className="text-xs font-medium">
                      {visit
                        ? new Date(visit.completed_at).toLocaleDateString()
                        : "No visits"}
                    </p>
                    <p className="mt-1 text-[10px] text-ink/40">
                      {visit?.location?.name ??
                        `${customer.visits_count ?? 0} total visits`}
                    </p>
                  </div>
                  <div>
                    {customer.review_request_status === "review_confirmed" ? (
                      <><span className="pill bg-emerald-50 text-emerald-800">Review confirmed</span><p className="mt-1 text-[10px] text-ink/40">Future requests stopped</p></>
                    ) : link ? (
                      <>
                        <StatusPill clicked={Boolean(link.first_clicked_at)} />
                        <p className="mt-1 text-[10px] capitalize text-ink/40">
                          {link.destination?.provider ?? "Review"} ·{" "}
                          {link.click_count} clicks
                        </p>
                      </>
                    ) : (
                      <span className="text-xs text-ink/40">Not sent</span>
                    )}
                  </div>
                  <ConsentPill value={consentState(customer)} />
                  <span className="text-xs capitalize text-ink/55">
                    {customer.source}
                  </span>
                  <button
                    disabled={busy}
                    className="rounded-lg border border-forest/20 px-3 py-2 text-xs font-semibold text-forest"
                    onClick={() => void openCustomer(customer.id)}
                  >
                    View
                  </button>
                </article>
              );
            })
          ) : (
            <div className="px-6 py-16 text-center">
              <p className="text-sm font-semibold">
                No customers match these filters
              </p>
              <p className="mt-2 text-xs text-ink/45">
                Clear a filter, import a CSV, or add a customer.
              </p>
            </div>
          )}
        </div>
        <footer className="flex items-center justify-between border-t border-ink/8 px-5 py-4 text-xs text-ink/45">
          <span>
            Page {meta.current_page} of {meta.last_page} · {meta.total}{" "}
            customers
          </span>
          <div className="flex gap-2">
            <button
              className="rounded-lg border border-ink/10 px-3 py-2 disabled:opacity-40"
              disabled={meta.current_page <= 1}
              onClick={() => void load(meta.current_page - 1)}
            >
              Previous
            </button>
            <button
              className="rounded-lg border border-ink/10 px-3 py-2 disabled:opacity-40"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => void load(meta.current_page + 1)}
            >
              Next
            </button>
          </div>
        </footer>
      </section>
      {selected && (
        <CustomerDialog
          customer={selected}
          busy={busy}
          onClose={() => setSelected(null)}
          onSuppression={() => void changeSuppression(selected)}
          onReviewStatus={() => void changeReviewStatus(selected)}
          onResend={resend}
        />
      )}
      {dialog === "add" && (
        <AddCustomerDialog
          busy={busy}
          onClose={() => setDialog(null)}
          onSave={create}
        />
      )}{" "}
      {dialog === "import" && (
        <ImportDialog
          onClose={() => setDialog(null)}
          onComplete={async (text) => {
            setDialog(null);
            setMessage(text);
            await load();
          }}
        />
      )}
    </div>
  );
}

function CustomerDialog({
  customer,
  busy,
  onClose,
  onSuppression,
  onReviewStatus,
  onResend,
}: {
  customer: Customer;
  busy: boolean;
  onClose: () => void;
  onSuppression: () => void;
  onReviewStatus: () => void;
  onResend: (link: ReviewLink, data: FormData) => void;
}) {
  const latestLink = customer.review_links?.[0];
  return (
    <Modal
      title={`${customer.first_name} ${customer.last_name ?? ""}`}
      eyebrow="Customer activity"
      onClose={onClose}
      wide
    >
      <div className="space-y-6 p-6">
        <div className="grid gap-3 sm:grid-cols-4">
          <Info label="Phone" value={customer.phone_e164 || "Not provided"} />
          <Info label="Email" value={customer.email || "Not provided"} />
          <Info label="SMS" value={consentState(customer)} />
          <Info label="Review requests" value={customer.review_request_status === "review_confirmed" ? "Stopped · review confirmed" : "Eligible"} />
        </div>
        <div className="flex flex-wrap gap-2"><button
          disabled={busy}
          className={
            consentState(customer) === "Suppressed"
              ? "button-primary"
              : "rounded-lg border border-red-200 px-4 py-2 text-xs font-semibold text-red-700"
          }
          onClick={onSuppression}
        >
          {consentState(customer) === "Suppressed"
            ? "Allow SMS again"
            : "Suppress SMS"}
        </button><button disabled={busy} className={customer.review_request_status === "review_confirmed" ? "button-secondary" : "rounded-lg border border-forest/20 px-4 py-2 text-xs font-semibold text-forest"} onClick={onReviewStatus}>{customer.review_request_status === "review_confirmed" ? "Allow future review requests" : "Mark review as confirmed"}</button></div>
        <p className="rounded-xl bg-paper p-3 text-[11px] leading-5 text-ink/50">A link click does not prove a review was submitted. Mark a review confirmed only when the customer confirms it or your team can match it to a provider review.</p>
        <section>
          <h3 className="text-sm font-semibold">Visit history</h3>
          <div className="mt-3 overflow-hidden rounded-xl border border-ink/8">
            {customer.visits?.length ? (
              customer.visits.map((visit) => (
                <div
                  className="grid gap-2 border-b border-ink/8 p-4 text-xs last:border-0 sm:grid-cols-4"
                  key={visit.id}
                >
                  <div>
                    <strong>
                      {new Date(visit.completed_at).toLocaleString()}
                    </strong>
                    <p className="mt-1 text-ink/40">
                      {visit.location?.name ?? "Location"}
                    </p>
                  </div>
                  <span className="capitalize">{visit.type ?? "Service"}</span>
                  <span className="capitalize">{visit.source}</span>
                  <span className="capitalize">{visit.status}</span>
                </div>
              ))
            ) : (
              <p className="p-5 text-xs text-ink/45">No visits recorded yet.</p>
            )}
          </div>
        </section>
        <section>
          <h3 className="text-sm font-semibold">Messages & review links</h3>
          <div className="mt-3 space-y-3">
            {customer.review_links?.length ? (
              customer.review_links.map((link) => (
                <article
                  className="rounded-xl border border-ink/8 p-4"
                  key={link.id}
                >
                  <StatusPill clicked={Boolean(link.first_clicked_at)} />
                  <p className="mt-2 text-xs capitalize">
                    {link.destination?.provider ?? "Review"} ·{" "}
                    {link.location?.name ?? "Location"}
                  </p>
                  <p className="mt-1 text-[10px] text-ink/40">
                    Sent {new Date(link.created_at).toLocaleString()} ·{" "}
                    {link.click_count} clicks
                  </p>
                </article>
              ))
            ) : (
              <p className="text-xs text-ink/45">
                No review link has been sent.
              </p>
            )}
          </div>
        </section>
        {latestLink && consentState(customer) === "Consented" && (
          <form
            action={(data) => onResend(latestLink, data)}
            className="rounded-xl bg-paper p-4"
          >
            <h3 className="text-sm font-semibold">Send a custom follow-up</h3>
            <p className="mt-1 text-[11px] leading-5 text-ink/45">
              A fresh tracked review link is added automatically. Consent and
              plan credits are checked again before sending.
            </p>
            <textarea
              name="body"
              required
              minLength={10}
              maxLength={1500}
              className="field mt-3 min-h-28"
              defaultValue="Hi {{customer_first_name}}, thank you for visiting {{business_name}}. We would appreciate your honest feedback: {{review_link}}"
            />
            <button disabled={busy} className="button-primary mt-3">
              {busy ? "Sending…" : "Send follow-up SMS"}
            </button>
          </form>
        )}
        <footer className="flex justify-end border-t border-ink/8 pt-4">
          <button className="button-secondary" onClick={onClose}>
            Close
          </button>
        </footer>
      </div>
    </Modal>
  );
}

function AddCustomerDialog({
  busy,
  onClose,
  onSave,
}: {
  busy: boolean;
  onClose: () => void;
  onSave: (data: FormData) => void;
}) {
  return (
    <Modal title="Add customer" eyebrow="Customer record" onClose={onClose}>
      <form action={onSave} className="space-y-5 p-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="First name" required>
            <input className="field" name="first_name" required autoFocus />
          </Field>
          <Field label="Last name">
            <input className="field" name="last_name" />
          </Field>
          <Field
            label="Mobile phone"
            required
            hint="International format, for example +12025550123"
          >
            <input
              className="field"
              name="phone"
              type="tel"
              required
              pattern="\+[1-9][0-9]{7,14}"
              placeholder="+12025550123"
            />
          </Field>
          <Field label="Email">
            <input className="field" name="email" type="email" />
          </Field>
        </div>
        <div className="rounded-xl border border-ink/8 bg-paper p-4">
          <p className="text-xs font-semibold">SMS consent</p>
          <p className="mt-1 text-[11px] leading-5 text-ink/45">
            A phone number does not automatically mean permission. Record
            consent only when the customer agreed.
          </p>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <Field label="Permission">
              <select className="field" name="consent" defaultValue="none">
                <option value="none">Not recorded</option>
                <option value="granted">Customer agreed</option>
              </select>
            </Field>
            <Field label="How did they agree?">
              <select className="field" name="consent_source">
                <option value="written">In writing</option>
                <option value="verbal">Verbally</option>
                <option value="web_form">Web form</option>
                <option value="provider">POS / provider</option>
              </select>
            </Field>
          </div>
        </div>
        <footer className="flex justify-end gap-2">
          <button type="button" className="button-secondary" onClick={onClose}>
            Cancel
          </button>
          <button className="button-primary" disabled={busy}>
            {busy ? "Saving…" : "Add customer"}
          </button>
        </footer>
      </form>
    </Modal>
  );
}

function ImportDialog({
  onClose,
  onComplete,
}: {
  onClose: () => void;
  onComplete: (message: string) => void;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [progress, setProgress] = useState(0);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  function downloadSample() {
    const csv =
      "first_name,last_name,email,phone\nAva,Morgan,ava@example.com,+12025550123\n";
    const url = URL.createObjectURL(
      new Blob([csv], { type: "text/csv;charset=utf-8" }),
    );
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "breviews-customer-import-template.csv";
    anchor.click();
    URL.revokeObjectURL(url);
  }
  async function inspect() {
    if (!file) return;
    setError("");
    setStatus("Uploading and checking rows…");
    setProgress(25);
    const data = new FormData();
    data.set("file", file);
    data.set("preview", "1");
    try {
      const result = await api<{ data: ImportPreview }>(
        "/api/v1/customers/import-csv",
        { method: "POST", body: data },
        true,
      );
      setPreview(result.data);
      setProgress(60);
      setStatus(`${result.data.valid_rows} valid rows ready.`);
    } catch (reason) {
      setError(
        reason instanceof Error
          ? reason.message
          : "Unable to validate the CSV.",
      );
      setProgress(0);
    }
  }
  async function commit() {
    if (!file) return;
    setError("");
    setProgress(75);
    setStatus("Creating customer records…");
    const data = new FormData();
    data.set("file", file);
    data.set("preview", "0");
    try {
      const result = await api<{ data: { created: number; skipped: number } }>(
        "/api/v1/customers/import-csv",
        { method: "POST", body: data },
        true,
      );
      setProgress(100);
      onComplete(
        `Import complete: ${result.data.created} created, ${result.data.skipped} skipped.`,
      );
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Unable to import the CSV.",
      );
    }
  }
  return (
    <Modal title="Import customers" eyebrow="CSV import" onClose={onClose}>
      <div className="space-y-5 p-6">
        <div className="rounded-xl border border-forest/15 bg-mint/30 p-4">
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
              <p className="text-xs font-semibold">
                Start with our simple template
              </p>
              <p className="mt-1 text-[11px] text-ink/45">
                First name and phone are required. Last name and email are
                optional.
              </p>
            </div>
            <button className="button-secondary" onClick={downloadSample}>
              Download sample CSV
            </button>
          </div>
        </div>
        <label className="grid cursor-pointer place-items-center rounded-xl border-2 border-dashed border-ink/12 bg-paper px-6 py-10 text-center">
          <input
            className="sr-only"
            type="file"
            accept=".csv,text/csv"
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null);
              setPreview(null);
              setProgress(0);
            }}
          />
          <span className="text-sm font-semibold">
            {file?.name ?? "Choose a CSV file"}
          </span>
          <span className="mt-2 text-xs text-ink/40">
            Maximum 500 rows and 2 MB
          </span>
        </label>
        {progress > 0 && (
          <div>
            <div className="flex justify-between text-xs">
              <span>{status}</span>
              <strong>{progress}%</strong>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink/8">
              <div
                className="h-full rounded-full bg-forest transition-all"
                style={{ width: `${progress}%` }}
              />
            </div>
          </div>
        )}
        {error && (
          <p
            role="alert"
            className="rounded-xl bg-red-50 px-4 py-3 text-xs text-red-800"
          >
            {error}
          </p>
        )}
        {preview && (
          <div className="rounded-xl border border-ink/8 p-4 text-xs">
            <strong>{preview.valid_rows} valid rows</strong>
            <span className="ml-2 text-ink/45">
              · {preview.errors.length} issues
            </span>
          </div>
        )}
        <footer className="flex justify-end gap-2">
          <button className="button-secondary" onClick={onClose}>
            Cancel
          </button>
          {!preview ? (
            <button
              className="button-primary"
              disabled={!file}
              onClick={inspect}
            >
              Check file
            </button>
          ) : (
            <button
              className="button-primary"
              disabled={preview.valid_rows === 0}
              onClick={commit}
            >
              Import {preview.valid_rows} customers
            </button>
          )}
        </footer>
      </div>
    </Modal>
  );
}

function consentState(customer: Customer) {
  return customer.suppressions.some(
    (item) => item.channel === "sms" && !item.released_at,
  )
    ? "Suppressed"
    : customer.consents.find((item) => item.channel === "sms")?.status ===
        "granted"
      ? "Consented"
      : "Not recorded";
}
function Modal({
  title,
  eyebrow,
  onClose,
  wide,
  children,
}: {
  title: string;
  eyebrow: string;
  onClose: () => void;
  wide?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-4">
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="customer-dialog-title"
        className={`max-h-[92vh] w-full overflow-y-auto rounded-xl bg-white shadow-2xl ${wide ? "max-w-4xl" : "max-w-2xl"}`}
      >
        <header className="sticky top-0 z-10 flex items-start justify-between border-b border-ink/8 bg-white px-6 py-5">
          <div>
            <p className="eyebrow">{eyebrow}</p>
            <h2
              id="customer-dialog-title"
              className="mt-1 text-xl font-semibold"
            >
              {title}
            </h2>
          </div>
          <button
            aria-label="Close dialog"
            className="rounded-lg border border-ink/10 px-2.5 py-1.5"
            onClick={onClose}
          >
            ×
          </button>
        </header>
        {children}
      </section>
    </div>
  );
}
function Filter({
  value,
  onChange,
  label,
  options,
}: {
  value: string;
  onChange: (value: string) => void;
  label: string;
  options: string[][];
}) {
  return (
    <select
      aria-label={label}
      className="field py-2.5 text-xs"
      value={value}
      onChange={(event) => onChange(event.target.value)}
    >
      <option value="">{label}</option>
      {options.map(([value, text]) => (
        <option key={value} value={value}>
          {text}
        </option>
      ))}
    </select>
  );
}
function Metric({
  label,
  value,
  detail,
}: {
  label: string;
  value: number;
  detail: string;
}) {
  return (
    <div className="rounded-xl border border-ink/8 bg-white p-4 shadow-sm">
      <p className="text-xs text-ink/45">{label}</p>
      <p className="mt-2 text-2xl font-semibold">{value}</p>
      <p className="mt-1 text-[10px] text-ink/35">{detail}</p>
    </div>
  );
}
function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl bg-paper p-4">
      <p className="text-[10px] font-bold uppercase tracking-wider text-ink/35">
        {label}
      </p>
      <p className="mt-2 break-all text-sm font-semibold">{value}</p>
    </div>
  );
}
function ConsentPill({ value }: { value: string }) {
  return (
    <span
      className={`pill w-fit ${value === "Consented" ? "bg-emerald-50 text-emerald-800" : value === "Suppressed" ? "bg-red-50 text-red-800" : ""}`}
    >
      {value}
    </span>
  );
}
function StatusPill({ clicked }: { clicked: boolean }) {
  return (
    <span
      className={`pill w-fit ${clicked ? "bg-emerald-50 text-emerald-800" : "bg-amber-50 text-amber-800"}`}
    >
      {clicked ? "Review link clicked" : "Link not clicked"}
    </span>
  );
}
function Field({
  label,
  hint,
  required,
  children,
}: {
  label: string;
  hint?: string;
  required?: boolean;
  children: React.ReactNode;
}) {
  return (
    <label className="label">
      <span>
        {label}
        {required && <span className="ml-1 text-red-600">*</span>}
      </span>
      {children}
      {hint && (
        <span className="mt-1 block text-[10px] font-normal text-ink/40">
          {hint}
        </span>
      )}
    </label>
  );
}
