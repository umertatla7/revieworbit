"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { UsPhoneInput } from "@/components/us-phone-input";

type Provider = "google" | "trustpilot" | "facebook" | "yelp" | "other";
type Destination = {
  id?: string;
  provider: Provider;
  url: string;
  is_primary: boolean;
};
type Location = {
  id: string;
  name: string;
  timezone: string;
  phone?: string;
  status: string;
  address?: Record<string, string>;
  review_destinations: Destination[];
};
type Entitlements = {
  plan_code: string;
  plan_name: string;
  location_limit: number;
  locations_used: number;
  locations_remaining: number;
  can_add_location: boolean;
  review_destination_limit: number;
  review_providers: Provider[];
  available_review_providers: { provider: Provider; included: boolean }[];
};
type Business = {
  name: string;
  default_timezone: string;
  default_country: string;
  locations: Location[];
  entitlements: Entitlements;
};
const providerLabels: Record<Provider, string> = {
  google: "Google",
  trustpilot: "Trustpilot",
  facebook: "Facebook",
  yelp: "Yelp",
  other: "Other",
};
const timezones = [
  "America/New_York",
  "America/Chicago",
  "America/Denver",
  "America/Los_Angeles",
  "America/Phoenix",
  "America/Toronto",
  "Europe/London",
  "Asia/Dubai",
  "Asia/Karachi",
  "Australia/Sydney",
];

export default function LocationsPage() {
  const [business, setBusiness] = useState<Business | null>(null);
  const [editing, setEditing] = useState<Location | "new" | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  async function load() {
    const result = await api<{ data: Business }>("/api/v1/business", {}, true);
    setBusiness(result.data);
  }
  useEffect(() => {
    void api<{ data: Business }>("/api/v1/business", {}, true)
      .then(({ data }) => setBusiness(data))
      .catch((error: Error) => setMessage(error.message));
  }, []);

  async function save(formData: FormData) {
    if (!business || !editing) return;
    setBusy(true);
    setMessage("");
    const destinations = business.entitlements.review_providers
      .map((provider) => ({
        provider,
        url: String(formData.get(`review_${provider}`) ?? "").trim(),
        is_primary: false,
      }))
      .filter((item) => item.url)
      .map((item, index) => ({ ...item, is_primary: index === 0 }));
    try {
      await api(
        editing === "new"
          ? "/api/v1/locations"
          : `/api/v1/locations/${editing.id}`,
        {
          method: editing === "new" ? "POST" : "PATCH",
          body: JSON.stringify({
            name: formData.get("name"),
            timezone: formData.get("timezone"),
            phone: formData.get("phone") || null,
            status: formData.get("status") || "active",
            address: {
              line1: formData.get("line1") || null,
              line2: formData.get("line2") || null,
              city: formData.get("city") || null,
              region: formData.get("region") || null,
              postal_code: formData.get("postal_code") || null,
              country: formData.get("country") || business.default_country,
            },
            review_destinations: destinations,
          }),
        },
        true,
      );
      const wasNew = editing === "new";
      setEditing(null);
      setMessage(
        wasNew
          ? "Location added successfully."
          : "Location updated successfully.",
      );
      await load();
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Unable to save the location.",
      );
    } finally {
      setBusy(false);
    }
  }

  const entitlements = business?.entitlements;
  return (
    <div className="mx-auto max-w-[1380px]">
      <header className="flex flex-col justify-between gap-5 border-b border-ink/8 pb-6 lg:flex-row lg:items-end">
        <div>
          <p className="eyebrow">Workspace · Locations</p>
          <h1 className="page-title">Locations & reviews</h1>
          <p className="page-intro">
            Manage every business location and its approved review destinations
            from one place.
          </p>
        </div>
        <button
          className="button-primary shrink-0"
          disabled={!entitlements?.can_add_location}
          onClick={() => setEditing("new")}
        >
          + Add location
        </button>
      </header>
      {message && (
        <p
          role="status"
          className="mt-5 rounded-xl border border-forest/15 bg-white px-4 py-3 text-sm text-forest"
        >
          {message}
        </p>
      )}
      <div className="mt-6 grid gap-4 sm:grid-cols-3">
        <Metric
          label="Current plan"
          value={entitlements?.plan_name ?? "—"}
          detail="Feature allowance"
        />
        <Metric
          label="Locations"
          value={`${entitlements?.locations_used ?? 0} / ${entitlements?.location_limit ?? 0}`}
          detail={`${entitlements?.locations_remaining ?? 0} remaining`}
        />
        <Metric
          label="Review channels"
          value={String(entitlements?.review_providers.length ?? 0)}
          detail={
            (entitlements?.review_providers ?? [])
              .map((item) => providerLabels[item])
              .join(", ") || "Loading"
          }
        />
      </div>
      {entitlements && !entitlements.can_add_location && (
        <div className="mt-5 flex flex-col justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 sm:flex-row sm:items-center">
          <div>
            <p className="text-sm font-semibold text-amber-950">
              Your {entitlements.plan_name} plan location limit is reached
            </p>
            <p className="mt-1 text-xs text-amber-800">
              Your review link can be Google, Trustpilot, Facebook, Yelp, or
              another HTTPS destination. Upgrade when you need more locations or
              links.
            </p>
          </div>
          <Link
            className="rounded-lg bg-amber-950 px-4 py-2.5 text-center text-xs font-semibold text-white"
            href="/dashboard/billing"
          >
            Compare plans
          </Link>
        </div>
      )}
      <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-ink/8 px-5 py-4">
          <div>
            <h2 className="text-sm font-semibold">All locations</h2>
            <p className="mt-1 text-[11px] text-ink/40">
              Review URLs are stored server-side and used behind opaque tracking
              links.
            </p>
          </div>
          <span className="pill">
            {business?.locations.length ?? 0} locations
          </span>
        </div>
        <div className="hidden grid-cols-[1.25fr_1fr_1.2fr_100px_80px] gap-4 border-b border-ink/8 bg-paper/70 px-5 py-3 text-[10px] font-bold uppercase tracking-wider text-ink/35 lg:grid">
          <span>Location</span>
          <span>Contact & timezone</span>
          <span>Review destinations</span>
          <span>Status</span>
          <span></span>
        </div>
        <div className="divide-y divide-ink/8">
          {business?.locations.length ? (
            business.locations.map((location) => (
              <article
                className="grid gap-4 px-5 py-5 lg:grid-cols-[1.25fr_1fr_1.2fr_100px_80px] lg:items-center"
                key={location.id}
              >
                <div className="flex items-center gap-3">
                  <span className="grid size-10 place-items-center rounded-xl bg-forest/8 font-bold text-forest">
                    {location.name.slice(0, 2).toUpperCase()}
                  </span>
                  <div>
                    <h3 className="text-sm font-semibold">{location.name}</h3>
                    <p className="mt-1 text-[11px] text-ink/40">
                      {[location.address?.city, location.address?.region]
                        .filter(Boolean)
                        .join(", ") || "Address not added"}
                    </p>
                  </div>
                </div>
                <div>
                  <p className="text-xs font-medium">
                    {location.phone || "No phone"}
                  </p>
                  <p className="mt-1 text-[11px] text-ink/40">
                    {location.timezone.replaceAll("_", " ")}
                  </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {location.review_destinations.length ? (
                    location.review_destinations.map((destination) => (
                      <span className="pill" key={destination.provider}>
                        {providerLabels[destination.provider]}
                      </span>
                    ))
                  ) : (
                    <span className="text-xs text-amber-700">
                      No review destination
                    </span>
                  )}
                </div>
                <span
                  className={`pill w-fit capitalize ${location.status === "active" ? "bg-emerald-50 text-emerald-800" : ""}`}
                >
                  {location.status}
                </span>
                <button
                  className="rounded-lg border border-ink/10 px-3 py-2 text-xs font-semibold"
                  onClick={() => setEditing(location)}
                >
                  Edit
                </button>
              </article>
            ))
          ) : (
            <div className="px-6 py-16 text-center">
              <p className="text-sm font-semibold">No locations yet</p>
              <p className="mt-2 text-xs text-ink/45">
                Add the first location and choose its review link.
              </p>
            </div>
          )}
        </div>
      </section>
      {editing && business && (
        <LocationDialog
          location={editing}
          business={business}
          busy={busy}
          onClose={() => setEditing(null)}
          onSave={save}
        />
      )}
    </div>
  );
}

function LocationDialog({
  location,
  business,
  busy,
  onClose,
  onSave,
}: {
  location: Location | "new";
  business: Business;
  busy: boolean;
  onClose: () => void;
  onSave: (data: FormData) => void;
}) {
  const current = location === "new" ? null : location;
  const destination = (provider: Provider) =>
    current?.review_destinations.find((item) => item.provider === provider)
      ?.url ?? "";
  const [basicProvider, setBasicProvider] = useState<Provider>(
    current?.review_destinations[0]?.provider ?? "google",
  );
  const basic = business.entitlements.plan_code === "launch";
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-4">
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="location-dialog-title"
        className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl"
      >
        <header className="sticky top-0 z-10 flex items-start justify-between border-b border-ink/8 bg-white px-6 py-5">
          <div>
            <p className="eyebrow">Location workspace</p>
            <h2
              id="location-dialog-title"
              className="mt-1 text-xl font-semibold"
            >
              {current ? `Edit ${current.name}` : "Add a location"}
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
        <form action={onSave} className="space-y-7 p-6">
          <DialogSection title="Location details">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Location name" required>
                <input
                  className="field"
                  name="name"
                  required
                  defaultValue={current?.name}
                />
              </Field>
              <Field label="Phone" hint="US format, for example (713) 893-1144">
                <UsPhoneInput name="phone" defaultValue={current?.phone}/>
              </Field>
              <Field label="Time zone" required>
                <select
                  className="field"
                  name="timezone"
                  required
                  defaultValue={current?.timezone ?? business.default_timezone}
                >
                  {timezones.map((item) => (
                    <option key={item}>{item}</option>
                  ))}
                </select>
              </Field>
              <Field label="Status">
                <select
                  className="field"
                  name="status"
                  defaultValue={current?.status ?? "active"}
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </Field>
            </div>
          </DialogSection>
          <DialogSection title="Address">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Street address">
                <input
                  className="field"
                  name="line1"
                  defaultValue={current?.address?.line1}
                />
              </Field>
              <Field label="Suite / unit">
                <input
                  className="field"
                  name="line2"
                  defaultValue={current?.address?.line2}
                />
              </Field>
              <Field label="City">
                <input
                  className="field"
                  name="city"
                  defaultValue={current?.address?.city}
                />
              </Field>
              <Field label="State / province">
                <input
                  className="field"
                  name="region"
                  defaultValue={current?.address?.region}
                />
              </Field>
              <Field label="Postal code">
                <input
                  className="field"
                  name="postal_code"
                  defaultValue={current?.address?.postal_code}
                />
              </Field>
              <Field label="Country">
                <input
                  className="field uppercase"
                  name="country"
                  maxLength={2}
                  defaultValue={
                    current?.address?.country ?? business.default_country
                  }
                />
              </Field>
            </div>
          </DialogSection>
          <DialogSection
            title="Review destinations"
            description={
              basic
                ? "Launch includes one review link of your choice."
                : `Your ${business.entitlements.plan_name} plan includes up to ${business.entitlements.review_destination_limit} review links.`
            }
          >
            {basic ? (
              <div className="grid gap-4 sm:grid-cols-[.7fr_1.3fr]">
                <Field label="Review site" required>
                  <select
                    className="field"
                    value={basicProvider}
                    onChange={(event) =>
                      setBasicProvider(event.target.value as Provider)
                    }
                  >
                    {business.entitlements.review_providers.map((provider) => (
                      <option key={provider} value={provider}>
                        {providerLabels[provider]}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field
                  label={`${providerLabels[basicProvider]} review URL`}
                  required
                >
                  <input
                    key={basicProvider}
                    className="field"
                    name={`review_${basicProvider}`}
                    type="url"
                    required
                    placeholder="https://"
                    defaultValue={destination(basicProvider)}
                  />
                </Field>
              </div>
            ) : (
              <div className="space-y-4">
                {business.entitlements.available_review_providers.map(
                  ({ provider, included }) =>
                    included ? (
                      <Field
                        key={provider}
                        label={`${providerLabels[provider]} review URL`}
                      >
                        <input
                          className="field"
                          name={`review_${provider}`}
                          type="url"
                          placeholder="https://"
                          defaultValue={destination(provider)}
                        />
                      </Field>
                    ) : null,
                )}
              </div>
            )}
          </DialogSection>
          <footer className="flex justify-end gap-2 border-t border-ink/8 pt-5">
            <button
              type="button"
              className="rounded-lg border border-ink/10 px-4 py-2.5 text-sm font-semibold"
              onClick={onClose}
            >
              Cancel
            </button>
            <button className="button-primary" disabled={busy}>
              {busy ? "Saving…" : current ? "Save changes" : "Add location"}
            </button>
          </footer>
        </form>
      </section>
    </div>
  );
}
function Metric({
  label,
  value,
  detail,
}: {
  label: string;
  value: string;
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
function DialogSection({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section>
      <h3 className="text-sm font-semibold">{title}</h3>
      {description && <p className="mt-1 text-xs text-ink/45">{description}</p>}
      <div className="mt-4">{children}</div>
    </section>
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
