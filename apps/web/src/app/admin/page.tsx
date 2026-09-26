"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, startSupportMode } from "@/lib/api";
import { UsPhoneInput } from "@/components/us-phone-input";

type Business = {
  id: string;
  name: string;
  slug: string;
  status: string;
  plan_code: string;
  default_timezone: string;
  default_country: string;
  onboarding_status: string;
  onboarding_step: number;
  operation_mode: string;
  locations_count: number;
  customers_count: number;
  pos_integrations_count: number;
  owners: { name: string; email: string }[];
};
type PlanOption = { code: string; name: string; status: string; is_public: boolean; business_id:string|null };

const industries = [
  ["automotive", "Automotive"], ["beauty_wellness", "Beauty & wellness"], ["dental", "Dental"],
  ["healthcare", "Healthcare"], ["home_services", "Home services"], ["hospitality", "Hospitality"],
  ["professional_services", "Professional services"], ["restaurant", "Restaurant / café"],
  ["retail", "Retail"], ["other", "Other"],
] as const;
const timezones = ["America/New_York", "America/Chicago", "America/Denver", "America/Los_Angeles", "America/Phoenix", "America/Toronto", "Europe/London", "Asia/Dubai", "Asia/Karachi", "Australia/Sydney"];

export default function AdminPage() {
  const router = useRouter();
  const [businesses, setBusinesses] = useState<Business[]>([]);
  const [plans, setPlans] = useState<PlanOption[]>([]);
  const [search, setSearch] = useState("");
  const [message, setMessage] = useState("");
  const [busyId, setBusyId] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [creatingBusy, setCreatingBusy] = useState(false);
  const [supportTarget, setSupportTarget] = useState<Business | null>(null);
  const [supportReason, setSupportReason] = useState(
    "Customer requested onboarding and POS setup assistance",
  );

  async function load() {
    const result = await api<{ data: Business[] }>(
      `/api/v1/admin/businesses${search ? `?search=${encodeURIComponent(search)}` : ""}`,
    );
    setBusinesses(result.data);
  }
  useEffect(() => {
    void Promise.all([
      api<{ data: Business[] }>("/api/v1/admin/businesses").then((result) => setBusinesses(result.data)),
      api<{ data: PlanOption[] }>("/api/v1/admin/plans").then((result) => setPlans(result.data)),
    ]).catch((error) => setMessage(error.message));
  }, []);

  async function createBusiness(formData: FormData) {
    setMessage(""); setCreatingBusy(true);
    try {
      const result = await api<{ data: Business; meta: { owner_setup_email_status: string } }>("/api/v1/admin/businesses", {
        method: "POST",
        body: JSON.stringify({
          business_name: formData.get("business_name"), legal_name: formData.get("legal_name") || null,
          industry: formData.get("industry"), business_email: formData.get("business_email"),
          business_phone: formData.get("business_phone"), website_url: formData.get("website_url") || null,
          owner_name: formData.get("owner_name"), owner_email: formData.get("owner_email"),
          owner_phone: formData.get("owner_phone") || null, location_name: formData.get("location_name"),
          location_phone: formData.get("location_phone") || null, address_line1: formData.get("address_line1"),
          address_line2: formData.get("address_line2") || null, city: formData.get("city"),
          region: formData.get("region"), postal_code: formData.get("postal_code"), country: formData.get("country"),
          timezone: formData.get("timezone"), google_review_url: formData.get("google_review_url") || null,
          operation_mode: formData.get("operation_mode"), preferred_channel: formData.get("preferred_channel"),
          quiet_hours_start: formData.get("quiet_hours_start"), quiet_hours_end: formData.get("quiet_hours_end"),
          account_notes: formData.get("account_notes") || null,
          plan_code: formData.get("plan_code"),
          send_owner_setup_email: formData.get("send_owner_setup_email") === "on",
        }),
      });
      setCreating(false);
      const emailNote = result.meta.owner_setup_email_status === "sent" ? " The owner password-setup email was sent." : result.meta.owner_setup_email_status === "failed" ? " The workspace was created, but the password-setup email could not be sent; retry it after mail is configured." : result.meta.owner_setup_email_status === "existing_owner" ? " The existing owner account was attached without changing its password." : "";
      setMessage(`${result.data.name} was provisioned with its owner and primary location.${emailNote}`);
      await load();
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Unable to create customer.",
      );
    } finally { setCreatingBusy(false); }
  }
  async function updateBusiness(business: Business, values: Partial<Business>) {
    setBusyId(business.id);
    setMessage("");
    try {
      await api(`/api/v1/admin/businesses/${business.id}`, {
        method: "PATCH",
        body: JSON.stringify(values),
      });
      await load();
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Unable to update customer.",
      );
    } finally {
      setBusyId(null);
    }
  }
  async function support() {
    const business = supportTarget;
    if (!business || supportReason.trim().length < 10) return;
    setBusyId(business.id);
    setMessage("");
    try {
      const result = await api<{
        data: { token: string; business_id: string; business_name: string };
      }>(`/api/v1/admin/businesses/${business.id}/support-sessions`, {
        method: "POST",
        body: JSON.stringify({ reason: supportReason.trim() }),
      });
      startSupportMode(
        result.data.business_id,
        result.data.business_name,
        result.data.token,
      );
      router.push("/dashboard");
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : "Unable to start support session.",
      );
      setBusyId(null);
    }
  }

  const active = businesses.filter(
    (business) => business.status === "active",
  ).length;
  const onboarding = businesses.filter(
    (business) => business.onboarding_status !== "completed",
  ).length;
  const connected = businesses.filter(
    (business) => business.pos_integrations_count > 0,
  ).length;

  return (
    <div className="mx-auto max-w-[1380px]">
      <div className="flex flex-col justify-between gap-4 border-b border-ink/8 pb-6 sm:flex-row sm:items-end">
        <div>
          <p className="eyebrow">Platform · Customers</p>
          <h1 className="page-title">Customer accounts</h1>
          <p className="page-intro">
            Manage tenant lifecycle, onboarding readiness, ownership, and
            audited workspace access.
          </p>
        </div>
        <button
          className="button-primary shrink-0"
          onClick={() => setCreating(true)}
        >
          + New customer
        </button>
      </div>
      <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Stat
          label="Total accounts"
          value={businesses.length}
          detail="All tenant workspaces"
        />
        <Stat label="Active" value={active} detail="Currently in service" />
        <Stat
          label="Need onboarding"
          value={onboarding}
          detail="Require setup assistance"
        />
        <Stat
          label="POS configured"
          value={connected}
          detail="External connections"
        />
      </div>
      {message && (
        <p
          role="status"
          className="mt-5 rounded-lg border border-forest/15 bg-white px-4 py-3 text-xs text-forest"
        >
          {message}
        </p>
      )}
      <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm">
        <div className="flex flex-col justify-between gap-3 border-b border-ink/8 px-5 py-4 sm:flex-row sm:items-center">
          <div>
            <h2 className="text-sm font-semibold">All customer accounts</h2>
            <p className="mt-0.5 text-[11px] text-ink/40">
              {businesses.length} tenant workspaces
            </p>
          </div>
          <form
            className="flex gap-2"
            onSubmit={(event) => {
              event.preventDefault();
              load();
            }}
          >
            <input
              aria-label="Search customers"
              className="rounded-lg border border-ink/10 bg-paper px-3 py-2 text-xs"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search by business"
            />
            <button className="rounded-lg border border-ink/10 bg-white px-3 py-2 text-xs font-semibold">
              Search
            </button>
          </form>
        </div>
        <div className="hidden grid-cols-[minmax(200px,1.4fr)_130px_120px_130px_220px] gap-4 border-b border-ink/8 bg-paper/70 px-5 py-2.5 text-[10px] font-bold uppercase tracking-wider text-ink/35 lg:grid">
          <span>Business</span>
          <span>Onboarding</span>
          <span>POS mode</span>
          <span>Status</span>
          <span className="text-right">Actions</span>
        </div>
        <div className="divide-y divide-ink/8">
          {businesses.map((business) => (
            <article
              key={business.id}
              className="grid gap-4 px-5 py-4 transition hover:bg-paper/45 lg:grid-cols-[minmax(200px,1.4fr)_130px_120px_130px_220px] lg:items-center"
            >
              <div>
                <div className="flex items-center gap-3">
                  <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-forest/8 text-xs font-bold text-forest">
                    {business.name.slice(0, 2).toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold">
                      {business.name}
                    </h3>
                    <p className="mt-0.5 truncate text-[11px] text-ink/40">
                      {business.owners[0]?.email ?? "Owner not assigned"} ·{" "}
                      {business.default_timezone}
                    </p>
                  </div>
                </div>
              </div>
              <div>
                <span className="pill">
                  {business.onboarding_status === "completed"
                    ? "Completed"
                    : `Step ${business.onboarding_step}/10`}
                </span>
                <p className="mt-1.5 text-[10px] text-ink/35">
                  {business.locations_count} loc. · {business.customers_count}{" "}
                  contacts
                </p>
              </div>
              <div>
                <p className="text-xs font-semibold capitalize">
                  {business.operation_mode}
                </p>
                <p className="mt-1 text-[10px] text-ink/35">
                  {business.pos_integrations_count} connections
                </p>
              </div>
              <div className="space-y-2">
                <select aria-label={`Plan for ${business.name}`} className="w-full rounded-lg border border-ink/10 bg-white px-2.5 py-2 text-xs capitalize" value={business.plan_code} disabled={busyId === business.id} onChange={(event) => updateBusiness(business, { plan_code: event.target.value })}>
                  {plans.filter((plan) => plan.status !== "archived" && (plan.is_public || !plan.business_id || plan.business_id===business.id)).map((plan)=><option value={plan.code} key={plan.code}>{plan.name}{plan.is_public ? "" : " · private"}</option>)}
                </select>
                <select aria-label={`Status for ${business.name}`} className="w-full rounded-lg border border-ink/10 bg-white px-2.5 py-2 text-xs" value={business.status} disabled={busyId === business.id} onChange={(event) => updateBusiness(business, { status: event.target.value })}>
                  <option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option>
                </select>
              </div>
              <div className="flex justify-end gap-2">
              <Link href={`/admin/customers/${business.id}`} className="rounded-lg border border-ink/10 px-3 py-2 text-xs font-semibold text-forest">
                Details
              </Link>
                <button
                  className="button-primary"
                  disabled={
                    busyId === business.id || business.status !== "active"
                  }
                  onClick={() => setSupportTarget(business)}
                >
                  {busyId === business.id ? "Opening…" : "Manage"}
                </button>
              </div>
            </article>
          ))}
        </div>
      </section>
      {creating && (
        <Modal
          title="Create customer account"
          eyebrow="Concierge onboarding"
          onClose={() => setCreating(false)}
          wide
        >
          <form action={createBusiness} className="mt-6 space-y-7">
            <div className="rounded-xl border border-forest/12 bg-mint/35 px-4 py-3 text-xs leading-5 text-forest">This creates the tenant, owner access, primary location, and onboarding defaults together. Sensitive provider credentials are configured later inside audited support mode.</div>
            <FormSection number="01" title="Business identity" description="The public identity customers will recognize in review requests.">
              <div className="grid gap-4 sm:grid-cols-2"><FormField label="Business display name" required><input name="business_name" className="field" required placeholder="Harbor Dental" autoFocus /></FormField><FormField label="Legal business name" hint="Optional if different"><input name="legal_name" className="field" placeholder="Harbor Dental Group LLC" /></FormField><FormField label="Industry" required><select name="industry" className="field" required defaultValue=""><option value="" disabled>Select an industry</option>{industries.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></FormField><FormField label="Website" hint="Optional"><input name="website_url" className="field" type="url" placeholder="https://harbordental.com" /></FormField></div>
            </FormSection>
            <FormSection number="02" title="Business contact" description="Operational contact details for the account and default sender profile.">
              <div className="grid gap-4 sm:grid-cols-2"><FormField label="Business email" required><input name="business_email" className="field" type="email" required placeholder="hello@harbordental.com" /></FormField><FormField label="Business phone" required hint="US format"><UsPhoneInput name="business_phone" required/></FormField></div>
            </FormSection>
            <FormSection number="03" title="Account owner" description="The person responsible for this B Reviews workspace.">
              <div className="grid gap-4 sm:grid-cols-3"><FormField label="Owner full name" required><input name="owner_name" className="field" required autoComplete="name" placeholder="Ava Morgan" /></FormField><FormField label="Owner email" required><input name="owner_email" className="field" type="email" required autoComplete="email" placeholder="ava@harbordental.com" /></FormField><FormField label="Owner mobile" hint="Optional · US format"><UsPhoneInput name="owner_phone"/></FormField></div><label className="mt-4 flex items-start gap-3 rounded-xl border border-ink/8 bg-paper p-4 text-xs leading-5"><input className="mt-0.5" type="checkbox" name="send_owner_setup_email" defaultChecked/><span><strong className="block text-ink">Send password setup email</strong><span className="text-ink/45">The owner receives a secure password-reset link. Existing B Reviews users are attached without changing their password.</span></span></label>
            </FormSection>
            <FormSection number="04" title="Primary location" description="Creates the first location used for timezone, visits, and the Google review destination.">
              <div className="grid gap-4 sm:grid-cols-2"><FormField label="Location name" required><input name="location_name" className="field" required placeholder="Downtown clinic" /></FormField><FormField label="Location phone" hint="Optional · defaults to business phone"><UsPhoneInput name="location_phone"/></FormField><FormField label="Street address" required><input name="address_line1" className="field" required autoComplete="address-line1" placeholder="125 Harbor Avenue" /></FormField><FormField label="Suite / unit" hint="Optional"><input name="address_line2" className="field" autoComplete="address-line2" placeholder="Suite 200" /></FormField><FormField label="City" required><input name="city" className="field" required autoComplete="address-level2" placeholder="Boston" /></FormField><FormField label="State / province" required><input name="region" className="field" required autoComplete="address-level1" placeholder="MA" /></FormField><FormField label="Postal code" required><input name="postal_code" className="field" required autoComplete="postal-code" placeholder="02110" /></FormField><FormField label="Country" required><select name="country" className="field" defaultValue="US" required><option value="US">United States</option></select></FormField><FormField label="Time zone" required><select name="timezone" className="field" defaultValue="America/New_York" required>{timezones.map((timezone) => <option value={timezone} key={timezone}>{timezone.replaceAll("_", " ")}</option>)}</select></FormField><FormField label="Google review URL" hint="Optional during account creation"><input name="google_review_url" className="field" type="url" placeholder="https://g.page/r/.../review" /></FormField></div>
            </FormSection>
            <FormSection number="05" title="Service defaults" description="Initial delivery and integration preferences; these remain editable during onboarding.">
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"><FormField label="Plan" required><select name="plan_code" className="field" defaultValue="launch" required>{plans.filter((plan)=>plan.status!=="archived"&&(plan.is_public||!plan.business_id)).map((plan)=><option value={plan.code} key={plan.code}>{plan.name}{plan.is_public ? "" : " · private"}</option>)}</select></FormField><FormField label="POS setup" required><select name="operation_mode" className="field" defaultValue="square"><option value="square">Connect Square</option><option value="toast">Connect Toast</option><option value="generic">Generic API / POS</option><option value="manual">Manual visits</option></select></FormField><FormField label="Preferred channel" required><select name="preferred_channel" className="field" defaultValue="sms"><option value="sms">SMS</option></select></FormField><FormField label="Quiet hours start" required><input name="quiet_hours_start" className="field" type="time" defaultValue="20:00" required /></FormField><FormField label="Quiet hours end" required><input name="quiet_hours_end" className="field" type="time" defaultValue="09:00" required /></FormField></div><FormField label="Internal setup notes" hint="Optional · visible to platform staff only"><textarea name="account_notes" className="field mt-1 min-h-24 resize-y" maxLength={2000} placeholder="Customer requested concierge POS setup. Main contact is available weekday mornings." /></FormField>
            </FormSection>
            <div className="sticky bottom-0 -mx-6 flex items-center justify-between gap-3 border-t border-ink/8 bg-white/95 px-6 py-4 backdrop-blur"><p className="hidden text-xs text-ink/40 sm:block">Required fields are marked with *</p><div className="ml-auto flex gap-2">
              <button
                type="button"
                className="rounded-lg border border-ink/10 px-4 py-2.5 text-sm font-semibold"
                onClick={() => setCreating(false)}
              >
                Cancel
              </button>
              <button className="button-primary" disabled={creatingBusy}>{creatingBusy ? "Creating account…" : "Create customer account"}</button>
              </div>
            </div>
          </form>
        </Modal>
      )}
      {supportTarget && (
        <Modal
          title={`Manage ${supportTarget.name}`}
          eyebrow="Audited support access"
          onClose={() => setSupportTarget(null)}
        >
          <p className="mt-3 text-xs leading-5 text-ink/45">
            Explain why access is needed. The session lasts one hour and every
            tenant change retains your administrator identity.
          </p>
          <label className="label mt-5">
            Support reason
            <textarea
              className="field min-h-24"
              value={supportReason}
              onChange={(event) => setSupportReason(event.target.value)}
            />
          </label>
          <div className="mt-5 flex justify-end gap-2">
            <button
              className="rounded-lg border border-ink/10 px-4 py-2.5 text-sm font-semibold"
              onClick={() => setSupportTarget(null)}
            >
              Cancel
            </button>
            <button
              className="button-primary"
              disabled={supportReason.trim().length < 10 || busyId !== null}
              onClick={support}
            >
              Start support session
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function Stat({
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
function Modal({
  title,
  eyebrow,
  onClose,
  children,
  wide = false,
}: {
  title: string;
  eyebrow: string;
  onClose: () => void;
  children: React.ReactNode;
  wide?: boolean;
}) {
  return (
    <div
      className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-5"
      role="presentation"
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        className={`max-h-[92vh] w-full overflow-y-auto rounded-xl bg-white p-6 shadow-2xl ${wide ? "max-w-5xl" : "max-w-lg"}`}
      >
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="eyebrow">{eyebrow}</p>
            <h2 id="modal-title" className="mt-1.5 text-xl font-semibold">
              {title}
            </h2>
          </div>
          <button
            aria-label="Close dialog"
            className="rounded-lg border border-ink/10 px-2 py-1 text-sm text-ink/45"
            onClick={onClose}
          >
            ×
          </button>
        </div>
        {children}
      </section>
    </div>
  );
}

function FormSection({ number, title, description, children }: { number: string; title: string; description: string; children: React.ReactNode }) {
  return <section className="border-t border-ink/8 pt-6"><div className="mb-5 flex gap-3"><span className="grid size-8 shrink-0 place-items-center rounded-lg bg-forest text-[10px] font-bold text-white">{number}</span><div><h3 className="text-sm font-semibold">{title}</h3><p className="mt-0.5 text-xs leading-5 text-ink/40">{description}</p></div></div>{children}</section>;
}

function FormField({ label, hint, required = false, children }: { label: string; hint?: string; required?: boolean; children: React.ReactNode }) {
  return <label className="label"><span>{label}{required && <span className="ml-1 text-red-600">*</span>}</span>{children}{hint && <span className="mt-1 block text-[10px] font-normal text-ink/40">{hint}</span>}</label>;
}
