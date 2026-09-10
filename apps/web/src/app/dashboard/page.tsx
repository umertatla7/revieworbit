"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Entitlements = {
  plan_name: string;
  location_limit: number;
  locations_used: number;
  review_destination_limit: number;
  review_destinations_used: number;
  template_limit: number;
  templates_used: number;
  media_template_limit: number;
  media_templates_used: number;
  automation_limit: number;
  automations_used: number;
  included_message_credits: number;
  message_credits_used: number;
};
type Business = {
  name: string;
  operation_mode: string;
  locations: { id: string; name: string; review_destinations: unknown[] }[];
  entitlements: Entitlements;
};
type Customer = {
  id: string;
  first_name: string;
  last_name?: string;
  visits_count?: number;
  review_links_count?: number;
  clicked_review_links_count?: number;
};
type ReviewMeta = {
  total: number;
  messages_sent: number;
  links_clicked: number;
  click_rate: number;
};
type Messaging = {
  configuration: { status: string; sms_enabled: boolean } | null;
  platform: { configured: boolean };
};

export default function DashboardPage() {
  const [business, setBusiness] = useState<Business | null>(null);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [customerTotal, setCustomerTotal] = useState(0);
  const [visits, setVisits] = useState(0);
  const [posStatus, setPosStatus] = useState<string | null>(null);
  const [review, setReview] = useState<ReviewMeta>({
    total: 0,
    messages_sent: 0,
    links_clicked: 0,
    click_rate: 0,
  });
  const [messaging, setMessaging] = useState<Messaging | null>(null);
  const [message, setMessage] = useState("");

  useEffect(() => {
    Promise.all([
      api<{ data: Business }>("/api/v1/business", {}, true),
      api<{ data: Customer[]; meta: { total: number } }>(
        "/api/v1/customers",
        {},
        true,
      ),
      api<{ data: unknown[]; meta: { total: number } }>(
        "/api/v1/visits",
        {},
        true,
      ),
      api<{ data: unknown[]; meta: ReviewMeta }>(
        "/api/v1/review-links",
        {},
        true,
      ),
      api<{ data: Messaging }>("/api/v1/messaging-configuration", {}, true),
      api<{ data: { status: string }[] }>("/api/v1/pos-integrations", {}, true),
    ])
      .then(
        ([
          businessResult,
          customerResult,
          visitResult,
          reviewResult,
          messagingResult,
          posResult,
        ]) => {
          setBusiness(businessResult.data);
          setCustomers(customerResult.data.slice(0, 5));
          setCustomerTotal(customerResult.meta.total);
          setVisits(visitResult.meta.total);
          setReview(reviewResult.meta);
          setMessaging(messagingResult.data);
          setPosStatus(posResult.data[0]?.status ?? null);
        },
      )
      .catch((error: Error) => setMessage(error.message));
  }, []);

  const entitlements = business?.entitlements;
  const smsReady = Boolean(
    messaging?.platform.configured &&
    messaging.configuration?.status === "active" &&
    messaging.configuration.sms_enabled,
  );

  return (
    <div className="mx-auto max-w-[1380px]">
      <header className="flex flex-col justify-between gap-4 border-b border-ink/8 pb-6 sm:flex-row sm:items-end">
        <div>
          <p className="eyebrow">Workspace overview</p>
          <h1 className="page-title">
            Welcome back, {business?.name ?? "ReviewOrbit"}
          </h1>
          <p className="page-intro">
            Your business activity and setup, explained in one place.
          </p>
        </div>
        <div className="flex gap-2">
          <Link className="button-secondary" href="/dashboard/locations">
            Manage locations
          </Link>
          <Link className="button-primary" href="/dashboard/customers">
            View customers
          </Link>
        </div>
      </header>
      {message && (
        <p
          role="alert"
          className="mt-5 rounded-lg bg-red-50 px-4 py-3 text-xs text-red-800"
        >
          {message}
        </p>
      )}
      <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Metric
          label="Customers"
          value={customerTotal}
          detail="Contacts in your workspace"
        />
        <Metric
          label="Completed visits"
          value={visits}
          detail="Manual and POS visits"
        />
        <Metric
          label="Messages sent"
          value={review.messages_sent}
          detail={`${entitlements?.message_credits_used ?? 0} credits used this month`}
        />
        <Metric
          label="Review link clicked"
          value={`${review.click_rate}%`}
          detail={`${review.links_clicked} of ${review.total} tracking links`}
        />
      </div>
      <div className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section className="overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm">
          <div className="flex items-center justify-between border-b border-ink/8 px-5 py-4">
            <div>
              <h2 className="text-sm font-semibold">
                Your {entitlements?.plan_name ?? "current"} plan
              </h2>
              <p className="mt-1 text-[11px] text-ink/40">
                See what is configured and what is still available.
              </p>
            </div>
            <Link
              className="text-xs font-semibold text-forest"
              href="/dashboard/billing"
            >
              View plan
            </Link>
          </div>
          <div className="grid gap-3 p-5 sm:grid-cols-2">
            <Usage
              label="Locations"
              used={entitlements?.locations_used}
              limit={entitlements?.location_limit}
              href="/dashboard/locations"
            />
            <Usage
              label="Review links"
              used={entitlements?.review_destinations_used}
              limit={entitlements?.review_destination_limit}
              href="/dashboard/locations"
            />
            <Usage
              label="Message templates"
              used={entitlements?.templates_used}
              limit={entitlements?.template_limit}
              href="/dashboard/templates"
            />
            <Usage
              label="Personalized media"
              used={entitlements?.media_templates_used}
              limit={entitlements?.media_template_limit}
              href="/dashboard/media"
            />
            <Usage
              label="Automations"
              used={entitlements?.automations_used}
              limit={entitlements?.automation_limit}
              href="/dashboard/automations"
            />
            <Usage
              label="Message credits"
              used={entitlements?.message_credits_used}
              limit={entitlements?.included_message_credits}
              href="/dashboard/messages"
            />
          </div>
        </section>
        <aside className="rounded-xl bg-[#17231f] p-5 text-white">
          <p className="eyebrow text-mint">Connection status</p>
          <Status
            label="Location & review link"
            ready={
              (business?.locations.length ?? 0) > 0 &&
              (entitlements?.review_destinations_used ?? 0) > 0
            }
            href="/dashboard/locations"
          />
          <Status
            label="Twilio SMS"
            ready={smsReady}
            href="/dashboard/messages"
          />
          <Status
            label="POS integration"
            ready={Boolean(posStatus)}
            detail={posStatus?.replaceAll("_", " ") ?? "Not connected"}
            href="/dashboard/integrations"
          />
          <Status
            label="Message template"
            ready={(entitlements?.templates_used ?? 0) > 0}
            href="/dashboard/templates"
          />
        </aside>
      </div>
      <section className="mt-6 overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-ink/8 px-5 py-4">
          <div>
            <h2 className="text-sm font-semibold">Recent customers</h2>
            <p className="mt-1 text-[11px] text-ink/40">
              A quick look at their visit and review-link activity.
            </p>
          </div>
          <Link
            href="/dashboard/customers"
            className="text-xs font-semibold text-forest"
          >
            See all customers →
          </Link>
        </div>
        <div className="divide-y divide-ink/8">
          {customers.map((customer) => (
            <Link
              href="/dashboard/customers"
              key={customer.id}
              className="grid gap-2 px-5 py-4 text-xs transition hover:bg-paper sm:grid-cols-[1fr_140px_180px]"
            >
              <strong>
                {customer.first_name} {customer.last_name}
              </strong>
              <span>{customer.visits_count ?? 0} visits</span>
              <span
                className={
                  (customer.clicked_review_links_count ?? 0) > 0
                    ? "text-emerald-700"
                    : "text-ink/45"
                }
              >
                {(customer.clicked_review_links_count ?? 0) > 0
                  ? "Review link clicked"
                  : (customer.review_links_count ?? 0) > 0
                    ? "Link not clicked"
                    : "No review link sent"}
              </span>
            </Link>
          ))}
          {customers.length === 0 && (
            <p className="p-8 text-center text-xs text-ink/45">
              No customers yet. Add one manually or connect your POS.
            </p>
          )}
        </div>
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
  value: number | string;
  detail: string;
}) {
  return (
    <article className="rounded-xl border border-ink/8 bg-white p-4 shadow-sm">
      <p className="text-xs text-ink/45">{label}</p>
      <p className="mt-2 text-2xl font-semibold capitalize">{value}</p>
      <p className="mt-1 text-[10px] text-ink/35">{detail}</p>
    </article>
  );
}
function Usage({
  label,
  used = 0,
  limit = 0,
  href,
}: {
  label: string;
  used?: number;
  limit?: number;
  href: string;
}) {
  const percent = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <Link
      href={href}
      className="rounded-xl border border-ink/8 p-4 transition hover:border-forest/30"
    >
      <div className="flex items-center justify-between text-xs">
        <strong>{label}</strong>
        <span>
          {used} / {limit}
        </span>
      </div>
      <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-paper">
        <div
          className="h-full rounded-full bg-forest"
          style={{ width: `${percent}%` }}
        />
      </div>
    </Link>
  );
}
function Status({
  label,
  ready,
  detail,
  href,
}: {
  label: string;
  ready: boolean;
  detail?: string;
  href: string;
}) {
  return (
    <Link
      href={href}
      className="mt-3 flex items-center gap-3 rounded-xl bg-white/6 p-3 transition hover:bg-white/10"
    >
      <span
        className={`grid size-7 shrink-0 place-items-center rounded-full text-xs ${ready ? "bg-mint text-ink" : "bg-white/10 text-white/50"}`}
      >
        {ready ? "✓" : "→"}
      </span>
      <span>
        <strong className="block text-xs">{label}</strong>
        <span className="mt-0.5 block text-[10px] capitalize text-white/45">
          {detail ?? (ready ? "Ready" : "Needs setup")}
        </span>
      </span>
    </Link>
  );
}
